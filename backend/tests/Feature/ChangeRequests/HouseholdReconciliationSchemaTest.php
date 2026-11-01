<?php

namespace Tests\Feature\ChangeRequests;

use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Models\Clan;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestCodes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * FB-FAMILY-RECON-01 WP-03 PostgreSQL schema: the CHECKs, the open-request
 * index and the guarded rollback. Rows are written with the query builder so
 * the database is what refuses them. The migration's DDL runs inside the test
 * transaction and is rolled back with it. Synthetic data only.
 */
class HouseholdReconciliationSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__.'/../../../database/migrations/2026_11_01_090000_add_household_reconciliation_foundation.php';

    private const INDEX = 'uq_change_request_open_reconciliation_person';

    private FamilyMembership $membership;

    private int $familyId;

    private int $headPersonId;

    private int $user;

    private int $staff;

    private int $clanId;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints are PostgreSQL-only.');
        }
        $head = FamilyMembership::factory()->householdHead()->create();
        $this->familyId = $head->family_id;
        $this->headPersonId = $head->person_id;
        $this->membership = FamilyMembership::factory()->create(['family_id' => $this->familyId, 'is_household_head' => false, 'is_active' => true]);
        $this->user = User::factory()->familySide()->create()->id;
        $this->staff = User::factory()->create()->id;
        $this->clanId = Clan::where('code', Clan::AL_BREEM)->firstOrFail()->id;
    }

    /** @return array<string, mixed> a valid SUBMITTED reconciliation */
    private function reconciliation(array $o = []): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'request_code' => ChangeRequestCodes::next(), 'family_id' => null,
            'submission_channel' => 'STAFF', 'type' => 'HOUSEHOLD_RECONCILIATION', 'payload_version' => 1, 'status' => 'SUBMITTED',
            'person_id' => $this->membership->person_id, 'target_membership_id' => $this->membership->id,
            'proposed_clan_id' => $this->clanId, 'submitted_data' => '{"example":"synthetic"}', 'submitted_by' => $this->staff,
            'submitted_by_person_id' => null, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ...$o,
        ];
    }

    /** @return array<string, mixed> a valid SUBMITTED family-channel row */
    private function familyRow(array $o = []): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'request_code' => ChangeRequestCodes::next(), 'family_id' => $this->familyId,
            'type' => 'RESIDENCE_UPDATE', 'payload_version' => 1, 'status' => 'SUBMITTED', 'submitted_data' => '{"example":"synthetic"}',
            'submitted_by' => $this->user, 'submitted_by_person_id' => $this->headPersonId, 'submitted_at' => now(),
            'created_at' => now(), 'updated_at' => now(), ...$o,
        ];
    }

    /** @return array<string, array<string, mixed>> a reconciliation row per state */
    private function states(): array
    {
        $reviewed = ['reviewed_by' => $this->staff, 'reviewed_at' => now()];
        $approved = [...$reviewed, 'approved_by' => $this->staff, 'approved_at' => now()];

        return [
            'SUBMITTED' => ['status' => 'SUBMITTED'],
            'UNDER_REVIEW' => ['status' => 'UNDER_REVIEW', ...$reviewed],
            'RETURNED_FOR_CLARIFICATION' => ['status' => 'RETURNED_FOR_CLARIFICATION', ...$reviewed],
            'RESUBMITTED' => ['status' => 'RESUBMITTED', ...$reviewed],
            'APPROVED' => ['status' => 'APPROVED', ...$approved],
            'REJECTED' => ['status' => 'REJECTED', ...$reviewed, 'rejected_by' => $this->staff, 'rejected_at' => now(), 'rejection_reason_code' => 'NO_LONGER_APPLICABLE',
                'approved_by' => null, 'approved_at' => null],
            'CANCELLED' => ['status' => 'CANCELLED', 'cancelled_by' => $this->staff, 'cancelled_at' => now()],
            'APPLIED' => ['status' => 'APPLIED', ...$approved, 'applied_by' => $this->staff, 'applied_at' => now(), 'created_family_id' => $this->familyId],
        ];
    }

    private function accepts(array $row): int
    {
        return DB::transaction(fn () => DB::table('change_requests')->insertGetId($row));
    }

    private function refuses(array $row, string $constraint): void
    {
        try {
            DB::transaction(fn () => DB::table('change_requests')->insert($row));
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());

            return;
        }
        $this->fail("The database accepted what {$constraint} must refuse.");
    }

    /** @return array<string, string> */
    private function checks(): array
    {
        return collect(DB::select(
            "select conname, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = 'change_requests'::regclass and contype = 'c'",
        ))->pluck('def', 'conname')->all();
    }

    private function nullable(): string
    {
        return DB::scalar("select is_nullable from information_schema.columns where table_schema = current_schema() and table_name = 'change_requests' and column_name = 'submitted_by_person_id'");
    }

    private function indexExists(): bool
    {
        return DB::table('pg_indexes')->where('indexname', self::INDEX)->exists();
    }

    private function migration(): object
    {
        return require self::MIGRATION;
    }

    public function test_a_valid_reconciliation_is_accepted_in_every_state(): void
    {
        foreach ($this->states() as $state => $values) {
            $id = $this->accepts($this->reconciliation($values));
            $this->assertGreaterThan(0, $id, $state);
            DB::table('change_requests')->where('id', $id)->delete();
        }
    }

    public function test_every_existing_type_stays_valid_and_keeps_its_rules(): void
    {
        foreach (['RESIDENCE_UPDATE', 'ADD_FAMILY_MEMBER', 'PERSON_CORRECTION', 'DEATH_REPORT', 'CONTACT_UPDATE', 'MEMBERSHIP_CHANGE', 'OTHER'] as $type) {
            $this->assertGreaterThan(0, $this->accepts($this->familyRow(['type' => $type])), $type);
            $this->refuses($this->familyRow(['type' => $type, 'family_id' => null]), 'chk_change_request_family_subject');
            $this->refuses($this->familyRow(['type' => $type, 'proposed_clan_id' => $this->clanId]), 'chk_change_request_placement');
        }
        $this->refuses($this->familyRow(['type' => 'NOT_A_TYPE']), 'chk_change_request_type');
        $this->refuses($this->familyRow(['type' => 'REGISTER_FAMILY', 'family_id' => null, 'proposed_clan_id' => $this->clanId]), 'chk_change_request_channel_type');
        $this->assertGreaterThan(0, $this->accepts($this->familyRow([
            'type' => 'REGISTER_FAMILY', 'family_id' => null, 'proposed_clan_id' => $this->clanId, 'submission_channel' => 'COORDINATOR',
        ])));
    }

    public function test_the_staff_channel_belongs_to_reconciliation_only_and_the_reverse(): void
    {
        $this->refuses($this->familyRow(['submission_channel' => 'STAFF']), 'chk_change_request_channel_type');
        $this->refuses($this->reconciliation(['submission_channel' => 'FAMILY', 'submitted_by_person_id' => $this->headPersonId]), 'chk_change_request_channel_type');
        $this->refuses($this->reconciliation(['submission_channel' => 'COORDINATOR', 'submitted_by_person_id' => $this->headPersonId]), 'chk_change_request_channel_type');
        $this->refuses($this->familyRow(['submission_channel' => 'OTHER']), 'chk_change_request_channel');
    }

    public function test_the_submitter_person_may_be_null_for_staff_only(): void
    {
        $this->assertGreaterThan(0, $this->accepts($this->reconciliation()));
        $this->refuses($this->familyRow(['submitted_by_person_id' => null]), 'chk_change_request_submitter_person');
        $this->refuses($this->familyRow(['submission_channel' => 'COORDINATOR', 'submitted_by_person_id' => null]), 'chk_change_request_submitter_person');
        DB::table('change_requests')->where('type', 'HOUSEHOLD_RECONCILIATION')->delete();
        $this->assertGreaterThan(0, $this->accepts($this->reconciliation(['submitted_by_person_id' => $this->headPersonId])));
    }

    public function test_a_reconciliation_needs_its_subject_and_placement_and_no_family_yet(): void
    {
        $this->refuses($this->reconciliation(['family_id' => $this->familyId]), 'chk_change_request_family_subject');
        $this->refuses($this->reconciliation(['proposed_clan_id' => null]), 'chk_change_request_placement');
        $this->refuses($this->reconciliation(['person_id' => null]), 'chk_change_request_reconciliation_subject');
        $this->refuses($this->reconciliation(['target_membership_id' => null]), 'chk_change_request_reconciliation_subject');
    }

    public function test_draft_is_deferred_for_reconciliation(): void
    {
        $this->refuses($this->reconciliation(['status' => 'DRAFT', 'submitted_at' => null]), 'chk_change_request_reconciliation_subject');
    }

    public function test_the_created_family_is_recorded_exactly_when_applied(): void
    {
        $this->refuses($this->reconciliation(['created_family_id' => $this->familyId]), 'chk_change_request_created_family');
        $applied = $this->states()['APPLIED'];
        $this->refuses($this->reconciliation([...$applied, 'created_family_id' => null]), 'chk_change_request_created_family');
    }

    public function test_one_open_reconciliation_per_person_in_every_open_state(): void
    {
        foreach (ChangeRequestStatus::open() as $status) {
            $values = $this->states()[$status->value];
            $id = $this->accepts($this->reconciliation($values));
            $this->refuses($this->reconciliation(), self::INDEX);
            DB::table('change_requests')->where('id', $id)->delete();
        }
    }

    public function test_the_index_covers_the_engines_open_states_and_nothing_else(): void
    {
        $definition = (string) DB::table('pg_indexes')->where('indexname', self::INDEX)->value('indexdef');

        foreach (ChangeRequestStatus::open() as $status) {
            $this->assertStringContainsString("'{$status->value}'", $definition);
        }
        foreach ([...ChangeRequestStatus::terminal(), ChangeRequestStatus::DRAFT] as $status) {
            $this->assertStringNotContainsString("'{$status->value}'", $definition);
        }
    }

    public function test_terminal_states_free_the_person_and_may_repeat(): void
    {
        foreach (ChangeRequestStatus::terminal() as $status) {
            $values = $this->states()[$status->value];
            $this->accepts($this->reconciliation($values));
            $this->accepts($this->reconciliation($values));
            $id = $this->accepts($this->reconciliation());
            DB::table('change_requests')->where('id', $id)->delete();
        }
        $this->assertSame(6, DB::table('change_requests')->count());
    }

    public function test_other_people_and_other_types_are_not_limited_by_the_index(): void
    {
        $this->accepts($this->reconciliation());
        $other = FamilyMembership::factory()->create(['family_id' => $this->familyId, 'is_household_head' => false, 'is_active' => true]);
        $this->assertGreaterThan(0, $this->accepts($this->reconciliation(['person_id' => $other->person_id, 'target_membership_id' => $other->id])));

        $person = ['person_id' => $this->membership->person_id, 'target_membership_id' => $this->membership->id];
        $this->assertGreaterThan(0, $this->accepts($this->familyRow($person)));
        $this->assertGreaterThan(0, $this->accepts($this->familyRow($person)));
    }

    public function test_the_person_and_membership_cannot_be_deleted_from_under_a_request(): void
    {
        $this->accepts($this->reconciliation());
        $this->expectException(QueryException::class);
        DB::table('family_memberships')->where('id', $this->membership->id)->delete();
    }

    public function test_the_migration_rolls_back_exactly_and_reapplies_without_touching_rows(): void
    {
        $this->accepts($this->familyRow());
        $checksBefore = $this->checks();
        $rows = fn () => DB::table('change_requests')->orderBy('id')->get()->toJson();
        $before = $rows();

        DB::transaction(fn () => $this->migration()->down());

        $this->assertSame('NO', $this->nullable());
        $this->assertFalse($this->indexExists());
        $checks = $this->checks();
        $this->assertArrayNotHasKey('chk_change_request_submitter_person', $checks);
        $this->assertArrayNotHasKey('chk_change_request_reconciliation_subject', $checks);
        foreach ($checks as $name => $definition) {
            $this->assertStringNotContainsString('HOUSEHOLD_RECONCILIATION', $definition, $name);
            $this->assertStringNotContainsString('STAFF', $definition, $name);
        }
        $this->assertSame($before, $rows());

        DB::transaction(fn () => $this->migration()->up());

        $this->assertSame('YES', $this->nullable());
        $this->assertTrue($this->indexExists());
        $this->assertEqualsCanonicalizing(array_values($checksBefore), array_values($this->checks()));
        $this->assertSame($before, $rows());
    }

    public function test_the_rollback_refuses_while_a_reconciliation_exists_and_changes_nothing(): void
    {
        $this->accepts($this->reconciliation());
        $checks = $this->checks();

        try {
            $this->migration()->down();
            $this->fail('The rollback ran with a reconciliation record.');
        } catch (RuntimeException $e) {
            $this->assertSame('Cannot roll back the household reconciliation foundation: reconciliation records exist.', $e->getMessage());
        }

        $this->assertSame('YES', $this->nullable());
        $this->assertTrue($this->indexExists());
        $this->assertSame($checks, $this->checks());
        $this->assertSame(1, DB::table('change_requests')->where('type', ChangeRequestType::HOUSEHOLD_RECONCILIATION->value)->count());
    }
}
