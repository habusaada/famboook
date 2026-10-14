<?php

namespace Tests\Feature\ChangeRequests;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestCodes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * CO-02.3a PostgreSQL schema (docs/04 DB-ADR-062): the coordinator-request
 * columns and CHECKs on change_requests, the COORDINATOR workflow actor, the
 * preserved PWA-5a / CONTACT_UPDATE constraints, and the guarded rollback.
 * PostgreSQL-only like every CHECK in the repository (run with
 * phpunit.pgsql.xml against the disposable test cluster). Rows are written
 * with the query builder so the database — not a cast or the model — is
 * what refuses them. The migration's DDL runs inside the test transaction
 * and is rolled back with it. Synthetic data only.
 */
class CoordinatorRequestSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__.'/../../../database/migrations/2026_10_21_090000_add_coordinator_change_requests.php';

    private const OLD_TYPES = ['CONTACT_UPDATE', 'RESIDENCE_UPDATE', 'PERSON_CORRECTION', 'ADD_FAMILY_MEMBER', 'MEMBERSHIP_CHANGE',
        'HOUSEHOLD_HEAD_CHANGE', 'BIRTH_REPORT', 'DEATH_REPORT', 'MARRIAGE_UPDATE', 'DOCUMENT_UPDATE', 'OTHER'];

    /** Constraints that existed before CO-02.3a (PWA-5a, as relaxed by CONTACT_UPDATE). */
    private const PRESERVED_CHANGE_REQUEST_CHECKS = ['chk_change_request_status', 'chk_change_request_type', 'chk_change_request_code',
        'chk_change_request_payload_version', 'chk_change_request_submitted', 'chk_change_request_reviewed',
        'chk_change_request_approved', 'chk_change_request_rejected', 'chk_change_request_rejection_reason',
        'chk_change_request_rejected_after_approval', 'chk_change_request_applied', 'chk_change_request_cancelled',
        'chk_change_request_base', 'chk_change_request_apply_failures', 'chk_change_request_submitted_data'];

    private const NEW_CHANGE_REQUEST_CHECKS = ['chk_change_request_channel', 'chk_change_request_family_subject',
        'chk_change_request_placement', 'chk_change_request_created_family', 'chk_change_request_channel_type'];

    private const WORKFLOW_CHECKS = ['chk_workflow_event_workflowable', 'chk_workflow_event_statuses', 'chk_workflow_event_type',
        'chk_workflow_event_change', 'chk_workflow_event_actor_side', 'chk_workflow_event_actor', 'chk_workflow_event_reason_code',
        'chk_workflow_event_messages', 'chk_workflow_event_internal_note', 'chk_workflow_event_metadata'];

    private int $familyId;

    private int $otherFamilyId;

    private int $personId;

    private int $familyUser;

    private int $staff;

    private int $clanId;

    private int $branchId;

    private int $otherClanId;

    private int $otherBranchId;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints are PostgreSQL-only.');
        }

        $head = FamilyMembership::factory()->householdHead()->create();
        $this->familyId = $head->family_id;
        $this->personId = $head->person_id;
        $this->otherFamilyId = FamilyMembership::factory()->householdHead()->create()->family_id;
        $this->familyUser = User::factory()->familySide()->create()->id;
        $this->staff = User::factory()->create()->id;

        $clan = Clan::where('code', Clan::AL_BREEM)->firstOrFail();
        $group = BranchGroup::create(['clan_id' => $clan->id, 'code' => 'G01', 'name' => 'مجموعة تجريبية', 'sort_order' => 1]);
        $this->clanId = $clan->id;
        $this->branchId = Branch::create(['branch_group_id' => $group->id, 'clan_id' => $clan->id, 'code' => 'BR_A', 'name' => 'فرع أ', 'sort_order' => 1])->id;

        $other = Clan::create(['code' => 'TEST_CLAN', 'name' => 'عائلة تجريبية أخرى']);
        $otherGroup = BranchGroup::create(['clan_id' => $other->id, 'code' => 'G01', 'name' => null, 'sort_order' => 1]);
        $this->otherClanId = $other->id;
        $this->otherBranchId = Branch::create(['branch_group_id' => $otherGroup->id, 'clan_id' => $other->id, 'code' => 'BR_X', 'name' => 'فرع س', 'sort_order' => 1])->id;
    }

    // ----------------------------------------------------------------- helpers

    /** @return array<string, mixed> a valid SUBMITTED family-channel row (no submission_channel: the default applies) */
    private function row(array $o = []): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'request_code' => ChangeRequestCodes::next(), 'family_id' => $this->familyId,
            'type' => 'RESIDENCE_UPDATE', 'payload_version' => 1, 'status' => 'SUBMITTED',
            'submitted_data' => '{"example":"synthetic"}', 'submitted_by' => $this->familyUser,
            'submitted_by_person_id' => $this->personId, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ...$o,
        ];
    }

    /** @return array<string, mixed> a valid SUBMITTED coordinator FAMILY_ADD proposal */
    private function proposal(array $o = []): array
    {
        return $this->row([
            'family_id' => null, 'type' => 'REGISTER_FAMILY', 'submission_channel' => 'COORDINATOR',
            'proposed_clan_id' => $this->clanId, 'proposed_branch_id' => $this->branchId, ...$o,
        ]);
    }

    /** @return array<string, mixed> the APPLIED state of a Staff-reviewed request */
    private function applied(array $row): array
    {
        return [...$row, 'status' => 'APPLIED', 'reviewed_by' => $this->staff, 'reviewed_at' => now(),
            'approved_by' => $this->staff, 'approved_at' => now(), 'applied_by' => $this->staff, 'applied_at' => now()];
    }

    /** @return array<string, mixed> */
    private function event(int $requestId, array $o = []): array
    {
        return [
            'workflowable_type' => 'change_request', 'workflowable_id' => $requestId, 'from_status' => null, 'to_status' => 'SUBMITTED',
            'event_type' => 'SUBMITTED', 'actor_user_id' => $this->familyUser, 'actor_side' => 'FAMILY', 'created_at' => now(), ...$o,
        ];
    }

    /** @param array<string, mixed> $row */
    private function accepts(string $table, array $row): int
    {
        $id = DB::transaction(fn () => DB::table($table)->insertGetId($row));
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    /** @param array<string, mixed> $row */
    private function refuses(string $table, array $row, string $constraint): void
    {
        try {
            DB::transaction(fn () => DB::table($table)->insert($row));
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());

            return;
        }
        $this->fail("The database accepted what {$constraint} must refuse.");
    }

    /** @return array<string, string> constraint name => definition */
    private function checks(string $table): array
    {
        return collect(DB::select(
            "select conname, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass and contype = 'c' order by conname",
            [$table],
        ))->pluck('def', 'conname')->all();
    }

    private function column(string $table, string $column): ?object
    {
        return DB::selectOne(
            'select is_nullable, character_maximum_length, column_default from information_schema.columns '
            .'where table_schema = current_schema() and table_name = ? and column_name = ?',
            [$table, $column],
        );
    }

    private function migration(): object
    {
        return require self::MIGRATION;
    }

    /** Runs up() as Laravel does on PostgreSQL: inside one transaction. */
    private function migrateUp(): void
    {
        DB::transaction(fn () => $this->migration()->up());
    }

    private function migrateDown(): void
    {
        DB::transaction(fn () => $this->migration()->down());
    }

    // ---------------------------------------------------------------- columns

    public function test_the_new_columns_have_the_approved_shape(): void
    {
        $this->assertSame('YES', $this->column('change_requests', 'family_id')->is_nullable);
        $channel = $this->column('change_requests', 'submission_channel');
        $this->assertSame('NO', $channel->is_nullable);
        $this->assertSame(20, (int) $channel->character_maximum_length);
        $this->assertStringContainsString("'FAMILY'", (string) $channel->column_default);
        foreach (['proposed_clan_id', 'proposed_branch_id', 'created_family_id'] as $column) {
            $this->assertSame('YES', $this->column('change_requests', $column)->is_nullable, $column);
        }
        $this->assertSame(20, (int) $this->column('workflow_events', 'actor_side')->character_maximum_length);

        $foreign = collect(DB::select(
            "select conname, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = 'change_requests'::regclass and contype = 'f'"
        ))->pluck('def', 'conname');
        $this->assertSame('FOREIGN KEY (proposed_branch_id, proposed_clan_id) REFERENCES branches(id, clan_id) ON DELETE RESTRICT',
            $foreign['fk_change_request_proposed_branch_clan']);
        $this->assertSame('FOREIGN KEY (proposed_clan_id) REFERENCES clans(id) ON DELETE RESTRICT', $foreign['change_requests_proposed_clan_id_foreign']);
        $this->assertSame('FOREIGN KEY (created_family_id) REFERENCES families(id) ON DELETE RESTRICT', $foreign['change_requests_created_family_id_foreign']);
        $this->assertSame('FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE RESTRICT', $foreign['change_requests_family_id_foreign']);

        $indexes = collect(DB::select("select indexdef from pg_indexes where schemaname = current_schema() and tablename = 'change_requests'"))
            ->pluck('indexdef')->implode("\n");
        foreach (['(submission_channel, status, submitted_at)', '(submitted_by, status)', '(proposed_clan_id, proposed_branch_id)',
            '(created_family_id)', '(family_id, status)'] as $expected) {
            $this->assertStringContainsString($expected, $indexes);
        }
    }

    public function test_every_existing_constraint_is_preserved_and_only_the_approved_ones_are_added(): void
    {
        $checks = $this->checks('change_requests');
        $this->assertEqualsCanonicalizing([...self::PRESERVED_CHANGE_REQUEST_CHECKS, ...self::NEW_CHANGE_REQUEST_CHECKS], array_keys($checks));
        $this->assertEqualsCanonicalizing(self::WORKFLOW_CHECKS, array_keys($this->checks('workflow_events')));

        // The CONTACT_UPDATE relaxations (2026_10_18_090000) are untouched.
        foreach (['chk_change_request_reviewed', 'chk_change_request_approved', 'chk_change_request_applied', 'chk_change_request_cancelled'] as $name) {
            $this->assertStringContainsString("'CONTACT_UPDATE'", $checks[$name], $name);
        }
        $this->assertSame(1, DB::scalar("select count(*) from pg_trigger where tgname = 'trg_workflow_events_append_only'"));
    }

    // -------------------------------------------------------- existing requests

    public function test_existing_family_requests_and_every_existing_type_remain_valid(): void
    {
        foreach (self::OLD_TYPES as $type) {
            $id = $this->accepts('change_requests', $this->row(['type' => $type]));
            $this->assertSame('FAMILY', DB::table('change_requests')->where('id', $id)->value('submission_channel'), $type);
        }
        // An explicit FAMILY channel, and the CONTACT_UPDATE system apply (no applier).
        $this->accepts('change_requests', $this->row(['submission_channel' => 'FAMILY']));
        $this->accepts('change_requests', $this->row(['type' => 'CONTACT_UPDATE', 'status' => 'APPLIED',
            'approved_at' => now(), 'applied_at' => now()]));
        $this->refuses('change_requests', $this->row(['status' => 'APPLIED', 'reviewed_by' => $this->staff, 'reviewed_at' => now(),
            'approved_by' => $this->staff, 'approved_at' => now(), 'applied_at' => now()]), 'chk_change_request_applied');
        $this->refuses('change_requests', $this->row(['type' => 'FAMILY_DATA_UPDATE']), 'chk_change_request_type');
    }

    public function test_a_coordinator_may_submit_an_existing_type_for_an_existing_family(): void
    {
        $this->accepts('change_requests', $this->row(['type' => 'ADD_FAMILY_MEMBER', 'submission_channel' => 'COORDINATOR']));
    }

    public function test_invalid_submission_channels_are_rejected(): void
    {
        foreach (['STAFF', 'SYSTEM', 'family', ''] as $channel) {
            $this->refuses('change_requests', $this->row(['submission_channel' => $channel]), 'chk_change_request_channel');
        }
        $this->refuses('change_requests', $this->row(['submission_channel' => null]), 'submission_channel');
    }

    // --------------------------------------------------------- REGISTER_FAMILY

    public function test_register_family_has_no_family_and_a_placement_in_scope_of_its_clan(): void
    {
        $this->accepts('change_requests', $this->proposal());
        // Clan level, no Branch.
        $this->accepts('change_requests', $this->proposal(['proposed_branch_id' => null]));
        $this->accepts('change_requests', $this->proposal(['proposed_clan_id' => $this->otherClanId, 'proposed_branch_id' => $this->otherBranchId]));
    }

    public function test_only_register_family_may_have_a_null_family(): void
    {
        foreach (self::OLD_TYPES as $type) {
            $this->refuses('change_requests', $this->row(['type' => $type, 'family_id' => null]), 'chk_change_request_family_subject');
            $this->refuses('change_requests', $this->row(['type' => $type, 'family_id' => null, 'submission_channel' => 'COORDINATOR']),
                'chk_change_request_family_subject');
        }
        // … and a proposed Family never names an existing one.
        $this->refuses('change_requests', $this->proposal(['family_id' => $this->familyId]), 'chk_change_request_family_subject');
    }

    public function test_register_family_is_coordinator_channel_only(): void
    {
        $this->refuses('change_requests', $this->proposal(['submission_channel' => 'FAMILY']), 'chk_change_request_channel_type');
    }

    public function test_invalid_clan_and_branch_combinations_are_rejected(): void
    {
        // A Branch of another Clan.
        $this->refuses('change_requests', $this->proposal(['proposed_branch_id' => $this->otherBranchId]), 'fk_change_request_proposed_branch_clan');
        $this->refuses('change_requests', $this->proposal(['proposed_clan_id' => $this->otherClanId]), 'fk_change_request_proposed_branch_clan');
        // A Branch without its Clan, and a proposal without a Clan.
        $this->refuses('change_requests', $this->proposal(['proposed_clan_id' => null]), 'chk_change_request_placement');
        $this->refuses('change_requests', $this->proposal(['proposed_clan_id' => null, 'proposed_branch_id' => null]), 'chk_change_request_placement');
        // Nonexistent Clan or Branch.
        $this->refuses('change_requests', $this->proposal(['proposed_clan_id' => 999999, 'proposed_branch_id' => null]), 'change_requests_proposed_clan_id_foreign');
        $this->refuses('change_requests', $this->proposal(['proposed_branch_id' => 999999]), 'fk_change_request_proposed_branch_clan');
        // A placement on any other type.
        $this->refuses('change_requests', $this->row(['proposed_clan_id' => $this->clanId]), 'chk_change_request_placement');
        $this->refuses('change_requests', $this->row(['type' => 'ADD_FAMILY_MEMBER', 'submission_channel' => 'COORDINATOR',
            'proposed_clan_id' => $this->clanId, 'proposed_branch_id' => $this->branchId]), 'chk_change_request_placement');
    }

    public function test_the_created_family_is_recorded_exactly_when_a_proposal_is_applied(): void
    {
        $this->accepts('change_requests', $this->applied($this->proposal(['created_family_id' => $this->otherFamilyId])));

        $this->refuses('change_requests', $this->applied($this->proposal()), 'chk_change_request_created_family');
        $this->refuses('change_requests', $this->proposal(['created_family_id' => $this->otherFamilyId]), 'chk_change_request_created_family');
        $this->refuses('change_requests', $this->applied($this->row(['created_family_id' => $this->otherFamilyId])), 'chk_change_request_created_family');
        $this->refuses('change_requests', $this->applied($this->proposal(['created_family_id' => 999999])), 'change_requests_created_family_id_foreign');
        // A proposal keeps the Staff actor rules: no system apply.
        $this->refuses('change_requests', [...$this->applied($this->proposal(['created_family_id' => $this->otherFamilyId])), 'applied_by' => null],
            'chk_change_request_applied');
    }

    public function test_placement_rows_cannot_be_deleted_from_under_a_proposal(): void
    {
        $this->accepts('change_requests', $this->proposal());
        try {
            DB::transaction(fn () => DB::table('branches')->where('id', $this->branchId)->delete());
            $this->fail('A proposed Branch was deleted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('fk_change_request_proposed_branch_clan', $e->getMessage());
        }
    }

    // --------------------------------------------------------- workflow actors

    public function test_existing_workflow_actor_values_remain_valid_and_coordinator_is_accepted(): void
    {
        $request = $this->accepts('change_requests', $this->proposal());

        $this->accepts('workflow_events', $this->event($request));
        $this->accepts('workflow_events', $this->event($request, ['from_status' => 'SUBMITTED', 'to_status' => 'UNDER_REVIEW',
            'event_type' => 'REVIEW_STARTED', 'actor_user_id' => $this->staff, 'actor_side' => 'STAFF', 'internal_note' => 'ملاحظة']));
        $this->accepts('workflow_events', $this->event($request, ['actor_user_id' => null, 'actor_side' => 'SYSTEM']));
        $this->accepts('workflow_events', $this->event($request, ['actor_side' => 'COORDINATOR', 'public_message' => 'رسالة تجريبية']));

        // A Coordinator is a person: always an actor, never a Staff-only note.
        $this->refuses('workflow_events', $this->event($request, ['actor_side' => 'COORDINATOR', 'actor_user_id' => null]), 'chk_workflow_event_actor');
        $this->refuses('workflow_events', $this->event($request, ['actor_side' => 'COORDINATOR', 'internal_note' => 'ملاحظة']), 'chk_workflow_event_internal_note');
        foreach (['OPERATOR', 'coordinator', 'REVIEWER'] as $side) {
            $this->refuses('workflow_events', $this->event($request, ['actor_side' => $side]), 'chk_workflow_event_actor_side');
        }
    }

    // ---------------------------------------------------------------- rollback

    public function test_the_migration_rolls_back_exactly_and_reapplies_without_touching_rows(): void
    {
        $checksBefore = [$this->checks('change_requests'), $this->checks('workflow_events')];
        $request = $this->accepts('change_requests', $this->row());
        $this->accepts('change_requests', $this->row(['type' => 'CONTACT_UPDATE', 'status' => 'APPLIED', 'approved_at' => now(), 'applied_at' => now()]));
        $this->accepts('workflow_events', $this->event($request));
        $columns = array_diff(Schema::getColumnListing('change_requests'),
            ['submission_channel', 'proposed_clan_id', 'proposed_branch_id', 'created_family_id']);
        $snapshot = fn () => [
            DB::table('change_requests')->orderBy('id')->get($columns)->toJson(),
            DB::table('workflow_events')->orderBy('id')->get()->toJson(),
        ];
        $before = $snapshot();

        $this->migrateDown();

        $this->assertSame('NO', $this->column('change_requests', 'family_id')->is_nullable);
        $this->assertSame(10, (int) $this->column('workflow_events', 'actor_side')->character_maximum_length);
        foreach (['submission_channel', 'proposed_clan_id', 'proposed_branch_id', 'created_family_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('change_requests', $column), $column);
        }
        $this->assertEqualsCanonicalizing(self::PRESERVED_CHANGE_REQUEST_CHECKS, array_keys($this->checks('change_requests')));
        $this->assertStringNotContainsString('REGISTER_FAMILY', $this->checks('change_requests')['chk_change_request_type']);
        $this->assertStringNotContainsString('COORDINATOR', $this->checks('workflow_events')['chk_workflow_event_actor_side']);
        $this->assertSame($before, $snapshot());

        $this->migrateUp();

        $this->assertSame($checksBefore, [$this->checks('change_requests'), $this->checks('workflow_events')]);
        $this->assertSame($before, $snapshot());
        $this->assertSame(['FAMILY'], DB::table('change_requests')->distinct()->pluck('submission_channel')->all());
    }

    public function test_the_rollback_refuses_while_coordinator_records_exist_and_changes_nothing(): void
    {
        $cases = [
            'a FAMILY_ADD proposal' => fn () => $this->accepts('change_requests', $this->proposal()),
            'a coordinator-channel request' => fn () => $this->accepts('change_requests', $this->row(['type' => 'ADD_FAMILY_MEMBER', 'submission_channel' => 'COORDINATOR'])),
            'a coordinator workflow event' => fn () => $this->accepts('workflow_events', $this->event($this->accepts('change_requests', $this->row()), ['actor_side' => 'COORDINATOR'])),
        ];

        foreach ($cases as $case => $create) {
            DB::beginTransaction();
            try {
                $create();
                try {
                    $this->migration()->down();
                    $this->fail("The rollback ran with {$case}.");
                } catch (RuntimeException $e) {
                    $this->assertSame('Cannot roll back coordinator change requests: coordinator request records exist.', $e->getMessage());
                }
                $this->assertTrue(Schema::hasColumn('change_requests', 'submission_channel'), $case);
                $this->assertSame('YES', $this->column('change_requests', 'family_id')->is_nullable, $case);
                $this->assertEqualsCanonicalizing([...self::PRESERVED_CHANGE_REQUEST_CHECKS, ...self::NEW_CHANGE_REQUEST_CHECKS],
                    array_keys($this->checks('change_requests')), $case);
            } finally {
                DB::rollBack();
            }
        }
    }
}
