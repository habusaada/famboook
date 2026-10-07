<?php

namespace Tests\Feature\ChangeRequests;

use App\Models\ChangeRequest;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestCodes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PWA-5a PostgreSQL schema: the change_requests CHECK constraints and code
 * sequence, the workflow_events CHECKs and the append-only trigger (AE-18).
 * PostgreSQL-only like every CHECK in the repository: skipped on the default
 * SQLite suite, run with phpunit.pgsql.xml against the disposable test
 * cluster (docs/08 §16a). Rows are written with the query builder so the
 * database — not a cast or the model — is what refuses them. Synthetic data.
 */
class ChangeRequestSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const HEX = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private int $familyId;

    private int $personId;

    private int $familyUser;

    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints and the append-only trigger are PostgreSQL-only.');
        }

        $head = FamilyMembership::factory()->householdHead()->create();
        $this->familyId = $head->family_id;
        $this->personId = $head->person_id;
        $this->familyUser = User::factory()->familySide()->create()->id;
        $this->staff = User::factory()->create()->id;
    }

    /** @return array<string, mixed> a valid SUBMITTED row, overridden by $o */
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

    /** @return array<string, mixed> */
    private function reviewed(array $o = []): array
    {
        return $this->row(['reviewed_by' => $this->staff, 'reviewed_at' => now(), ...$o]);
    }

    /** @return array<string, mixed> */
    private function approvedState(array $o = []): array
    {
        return $this->reviewed(['status' => 'APPROVED', 'approved_by' => $this->staff, 'approved_at' => now(), ...$o]);
    }

    /** @param array<string, mixed> $row */
    private function accepts(string $table, array $row): int
    {
        $id = DB::transaction(fn () => DB::table($table)->insertGetId($row));
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function refusesSql(callable $statement, string $expected): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());

            return;
        }
        $this->fail("The database accepted what {$expected} must refuse.");
    }

    /** @param array<string, mixed> $row */
    private function refuses(string $table, array $row, string $constraint): void
    {
        $this->refusesSql(fn () => DB::table($table)->insert($row), $constraint);
    }

    public function test_change_requests_has_the_approved_columns(): void
    {
        $this->assertSame([
            'id', 'uuid', 'request_code', 'family_id', 'type', 'payload_version', 'status', 'person_id', 'target_membership_id',
            'submitted_data', 'reason', 'base_fingerprint', 'base_key_version', 'submitted_by', 'submitted_by_person_id',
            'client_reference', 'submitted_at', 'reviewed_by', 'reviewed_at', 'approved_by', 'approved_at', 'rejected_by',
            'rejected_at', 'rejection_reason_code', 'rejection_reason', 'applied_by', 'applied_at', 'cancelled_by', 'cancelled_at',
            'apply_failure_count', 'last_apply_failed_at', 'created_at', 'updated_at',
        ], Schema::getColumnListing('change_requests'));

        $this->assertSame('jsonb', DB::selectOne(
            "select data_type from information_schema.columns where table_schema = current_schema() and table_name = 'change_requests' and column_name = 'submitted_data'"
        )->data_type);
    }

    public function test_change_requests_indexes(): void
    {
        $indexes = collect(DB::select("select indexdef from pg_indexes where schemaname = current_schema() and tablename = 'change_requests'"))
            ->pluck('indexdef')->implode("\n");

        foreach (['UNIQUE INDEX change_requests_uuid_unique', 'UNIQUE INDEX change_requests_request_code_unique',
            'UNIQUE INDEX change_requests_submitted_by_client_reference_unique', '(family_id, status)', '(status, submitted_at)',
            '(type)', '(person_id)', '(target_membership_id)'] as $expected) {
            $this->assertStringContainsString($expected, $indexes);
        }
    }

    public function test_the_request_code_sequence_issues_increasing_unique_codes(): void
    {
        $first = ChangeRequestCodes::next();
        $second = ChangeRequestCodes::next();

        $this->assertMatchesRegularExpression('/^CRQ-\d{6,}$/', $first);
        $this->assertGreaterThan((int) substr($first, 4), (int) substr($second, 4));
        $this->assertSame('change_request_code_seq', DB::selectOne("select sequencename from pg_sequences where sequencename = 'change_request_code_seq'")->sequencename);

        $this->accepts('change_requests', $this->row(['request_code' => 'CRQ-1234567']));
        $this->refuses('change_requests', $this->row(['request_code' => 'CRQ-12']), 'chk_change_request_code');
        $this->refuses('change_requests', $this->row(['request_code' => 'REQ-000001']), 'chk_change_request_code');
    }

    public function test_status_type_and_codes_are_controlled(): void
    {
        $this->accepts('change_requests', $this->row());

        $this->refuses('change_requests', $this->row(['status' => 'PENDING']), 'chk_change_request_status');
        $this->refuses('change_requests', $this->row(['type' => 'FAMILY_DATA_UPDATE']), 'chk_change_request_type');
        $this->refuses('change_requests', $this->row(['type' => 'residence_update']), 'chk_change_request_type');
        $this->refuses('change_requests', $this->row(['payload_version' => 0]), 'chk_change_request_payload_version');
        $this->refuses('change_requests', $this->row(['submitted_data' => '["not","an","object"]']), 'chk_change_request_submitted_data');
    }

    public function test_every_lifecycle_state_is_storable_with_its_columns(): void
    {
        $this->accepts('change_requests', $this->row(['status' => 'DRAFT', 'submitted_at' => null]));
        $this->accepts('change_requests', $this->row());
        $this->accepts('change_requests', $this->reviewed(['status' => 'UNDER_REVIEW']));
        $this->accepts('change_requests', $this->reviewed(['status' => 'RETURNED_FOR_CLARIFICATION']));
        $this->accepts('change_requests', $this->reviewed(['status' => 'RESUBMITTED']));
        $this->accepts('change_requests', $this->approvedState());
        $this->accepts('change_requests', $this->approvedState(['status' => 'APPLIED', 'applied_by' => $this->staff, 'applied_at' => now()]));
        $this->accepts('change_requests', $this->approvedState(['apply_failure_count' => 2, 'last_apply_failed_at' => now()]));
        $this->accepts('change_requests', $this->reviewed([
            'status' => 'REJECTED', 'rejected_by' => $this->staff, 'rejected_at' => now(), 'rejection_reason_code' => 'CANNOT_VERIFY',
            'rejection_reason' => 'تعذّر التحقق.',
        ]));
        $this->accepts('change_requests', $this->approvedState([
            'status' => 'REJECTED', 'rejected_by' => $this->staff, 'rejected_at' => now(), 'rejection_reason_code' => 'NO_LONGER_APPLICABLE',
            'apply_failure_count' => 1, 'last_apply_failed_at' => now(),
        ]));
        // Cancelled before review and after a return alike.
        $this->accepts('change_requests', $this->row(['status' => 'CANCELLED', 'cancelled_by' => $this->familyUser, 'cancelled_at' => now()]));
        $this->accepts('change_requests', $this->reviewed(['status' => 'CANCELLED', 'cancelled_by' => $this->familyUser, 'cancelled_at' => now()]));
    }

    public function test_submission_and_review_columns_are_consistent(): void
    {
        $this->refuses('change_requests', $this->row(['submitted_at' => null]), 'chk_change_request_submitted');
        $this->refuses('change_requests', $this->row(['status' => 'DRAFT']), 'chk_change_request_submitted');
        $this->refuses('change_requests', $this->row(['status' => 'UNDER_REVIEW']), 'chk_change_request_reviewed');
        $this->refuses('change_requests', $this->row(['reviewed_at' => now()]), 'chk_change_request_reviewed');
        $this->refuses('change_requests', $this->row(['reviewed_by' => $this->staff]), 'chk_change_request_reviewed');
    }

    public function test_approval_columns_are_consistent(): void
    {
        $this->refuses('change_requests', $this->reviewed(['status' => 'APPROVED']), 'chk_change_request_approved');
        $this->refuses('change_requests', $this->approvedState(['approved_by' => null]), 'chk_change_request_approved');
        $this->refuses('change_requests', $this->reviewed(['status' => 'UNDER_REVIEW', 'approved_by' => $this->staff, 'approved_at' => now()]), 'chk_change_request_approved');
        $this->refuses('change_requests', $this->reviewed(['status' => 'APPLIED', 'applied_by' => $this->staff, 'applied_at' => now()]), 'chk_change_request_approved');
    }

    public function test_applied_columns_match_applied_status(): void
    {
        $this->refuses('change_requests', $this->approvedState(['status' => 'APPLIED']), 'chk_change_request_applied');
        $this->refuses('change_requests', $this->approvedState(['status' => 'APPLIED', 'applied_at' => now()]), 'chk_change_request_applied');
        $this->refuses('change_requests', $this->approvedState(['applied_by' => $this->staff, 'applied_at' => now()]), 'chk_change_request_applied');
    }

    public function test_rejection_columns_match_rejected_status(): void
    {
        $rejected = fn (array $o = []) => $this->reviewed([
            'status' => 'REJECTED', 'rejected_by' => $this->staff, 'rejected_at' => now(), 'rejection_reason_code' => 'OTHER', ...$o,
        ]);

        $this->refuses('change_requests', $rejected(['rejection_reason_code' => null]), 'chk_change_request_rejected');
        $this->refuses('change_requests', $rejected(['rejected_by' => null]), 'chk_change_request_rejected');
        $this->refuses('change_requests', $rejected(['rejection_reason_code' => 'BECAUSE']), 'chk_change_request_rejection_reason');
        $this->refuses('change_requests', $this->row(['rejection_reason_code' => 'OTHER']), 'chk_change_request_rejected');
        // The family-visible message exists only on a rejection.
        $this->refuses('change_requests', $this->row(['rejection_reason' => 'نص']), 'chk_change_request_rejected');
    }

    public function test_a_request_rejected_after_approval_is_only_no_longer_applicable_after_a_refused_apply(): void
    {
        $approvedRejected = fn (array $o = []) => $this->approvedState([
            'status' => 'REJECTED', 'rejected_by' => $this->staff, 'rejected_at' => now(), 'rejection_reason_code' => 'NO_LONGER_APPLICABLE',
            'apply_failure_count' => 1, 'last_apply_failed_at' => now(), ...$o,
        ]);

        $this->refuses('change_requests', $approvedRejected(['rejection_reason_code' => 'DATA_CHANGED']), 'chk_change_request_rejected_after_approval');
        $this->refuses('change_requests', $approvedRejected(['apply_failure_count' => 0, 'last_apply_failed_at' => null]), 'chk_change_request_rejected_after_approval');
    }

    public function test_cancellation_columns_and_never_after_approval(): void
    {
        $this->refuses('change_requests', $this->row(['status' => 'CANCELLED']), 'chk_change_request_cancelled');
        $this->refuses('change_requests', $this->row(['status' => 'CANCELLED', 'cancelled_at' => now()]), 'chk_change_request_cancelled');
        $this->refuses('change_requests', $this->row(['cancelled_by' => $this->familyUser, 'cancelled_at' => now()]), 'chk_change_request_cancelled');
        $this->refuses('change_requests', $this->approvedState(['status' => 'CANCELLED', 'cancelled_by' => $this->familyUser, 'cancelled_at' => now()]), 'chk_change_request_approved');
    }

    public function test_base_fingerprint_and_apply_failure_pairs(): void
    {
        $this->accepts('change_requests', $this->row(['base_fingerprint' => self::HEX, 'base_key_version' => 1]));

        $this->refuses('change_requests', $this->row(['base_fingerprint' => self::HEX]), 'chk_change_request_base');
        $this->refuses('change_requests', $this->row(['base_key_version' => 1]), 'chk_change_request_base');
        // Never a readable value, never upper-case hex, never key version 0.
        $this->refuses('change_requests', $this->row(['base_fingerprint' => str_pad('0590000000', 64, ' '), 'base_key_version' => 1]), 'chk_change_request_base');
        $this->refuses('change_requests', $this->row(['base_fingerprint' => strtoupper(self::HEX), 'base_key_version' => 1]), 'chk_change_request_base');
        $this->refuses('change_requests', $this->row(['base_fingerprint' => self::HEX, 'base_key_version' => 0]), 'chk_change_request_base');

        $this->refuses('change_requests', $this->approvedState(['apply_failure_count' => 1]), 'chk_change_request_apply_failures');
        $this->refuses('change_requests', $this->approvedState(['last_apply_failed_at' => now()]), 'chk_change_request_apply_failures');
    }

    public function test_foreign_keys_restrict_deletion_of_referenced_rows(): void
    {
        $id = $this->accepts('change_requests', $this->reviewed(['status' => 'UNDER_REVIEW']));

        $this->refusesSql(fn () => DB::table('users')->where('id', $this->staff)->delete(), 'change_requests_reviewed_by_foreign');
        $this->refusesSql(fn () => DB::table('users')->where('id', $this->familyUser)->delete(), 'change_requests_submitted_by_foreign');
        $this->refuses('change_requests', $this->row(['target_membership_id' => 999_999]), 'change_requests_target_membership_id_foreign');
        $this->assertSame(1, DB::table('change_requests')->where('id', $id)->count());
    }

    // ---------------------------------------------------------------- workflow_events

    /** @return array<string, mixed> */
    private function event(int $requestId, array $o = []): array
    {
        return [
            'workflowable_type' => 'change_request', 'workflowable_id' => $requestId, 'from_status' => null, 'to_status' => 'SUBMITTED',
            'event_type' => 'SUBMITTED', 'actor_user_id' => $this->familyUser, 'actor_side' => 'FAMILY', 'created_at' => now(), ...$o,
        ];
    }

    public function test_workflow_events_has_the_approved_columns_and_no_updated_at(): void
    {
        $this->assertSame([
            'id', 'workflowable_type', 'workflowable_id', 'from_status', 'to_status', 'event_type', 'actor_user_id', 'actor_side',
            'reason_code', 'public_message', 'internal_note', 'metadata', 'created_at',
        ], Schema::getColumnListing('workflow_events'));
    }

    public function test_workflow_event_checks(): void
    {
        $request = $this->accepts('change_requests', $this->row());

        $this->accepts('workflow_events', $this->event($request));
        $this->accepts('workflow_events', $this->event($request, [
            'from_status' => 'APPROVED', 'to_status' => 'APPROVED', 'event_type' => 'APPLY_FAILED', 'actor_user_id' => $this->staff,
            'actor_side' => 'STAFF', 'internal_note' => 'ملاحظة', 'metadata' => '{"code":"X"}',
        ]));
        $this->accepts('workflow_events', $this->event($request, ['actor_user_id' => null, 'actor_side' => 'SYSTEM']));

        $this->refuses('workflow_events', $this->event($request, ['workflowable_type' => 'App\Models\ChangeRequest']), 'chk_workflow_event_workflowable');
        $this->refuses('workflow_events', $this->event($request, ['to_status' => 'DONE']), 'chk_workflow_event_statuses');
        $this->refuses('workflow_events', $this->event($request, ['event_type' => 'EDITED']), 'chk_workflow_event_type');
        $this->refuses('workflow_events', $this->event($request, ['actor_side' => 'OPERATOR']), 'chk_workflow_event_actor_side');
        $this->refuses('workflow_events', $this->event($request, ['actor_user_id' => null]), 'chk_workflow_event_actor');
        $this->refuses('workflow_events', $this->event($request, ['reason_code' => 'the mobile was 0590000000']), 'chk_workflow_event_reason_code');
        $this->refuses('workflow_events', $this->event($request, ['public_message' => str_repeat('م', 2001)]), 'chk_workflow_event_messages');
        $this->refuses('workflow_events', $this->event($request, ['public_message' => '']), 'chk_workflow_event_messages');
        $this->refuses('workflow_events', $this->event($request, ['internal_note' => 'ملاحظة']), 'chk_workflow_event_internal_note');
        $this->refuses('workflow_events', $this->event($request, ['metadata' => '["x"]']), 'chk_workflow_event_metadata');
        // A state change is never APPLY_FAILED; an unchanged state is only APPLY_FAILED.
        $this->refuses('workflow_events', $this->event($request, ['event_type' => 'APPLY_FAILED']), 'chk_workflow_event_change');
        $this->refuses('workflow_events', $this->event($request, ['from_status' => 'SUBMITTED']), 'chk_workflow_event_change');
    }

    public function test_workflow_events_accept_inserts_and_refuse_update_and_delete_at_the_database(): void
    {
        $request = $this->accepts('change_requests', $this->row());
        $event = $this->accepts('workflow_events', $this->event($request, ['public_message' => 'رسالة']));
        $this->accepts('workflow_events', $this->event($request));

        // Direct SQL — no Eloquent involved — is refused by the trigger.
        $this->refusesSql(fn () => DB::update('update workflow_events set public_message = ? where id = ?', ['تعديل', $event]), 'workflow_events is append-only: UPDATE');
        $this->refusesSql(fn () => DB::table('workflow_events')->update(['actor_side' => 'SYSTEM']), 'workflow_events is append-only: UPDATE');
        $this->refusesSql(fn () => DB::delete('delete from workflow_events where id = ?', [$event]), 'workflow_events is append-only: DELETE');
        $this->refusesSql(fn () => DB::table('workflow_events')->delete(), 'workflow_events is append-only: DELETE');

        $this->assertSame(2, DB::table('workflow_events')->count());
        $this->assertSame('رسالة', DB::table('workflow_events')->where('id', $event)->value('public_message'));
    }

    public function test_the_trigger_is_scoped_to_workflow_events_only(): void
    {
        $triggers = collect(DB::select(
            "select event_object_table as t, trigger_name as n from information_schema.triggers where trigger_name like 'trg_workflow_events%'"
        ));
        $this->assertSame(['workflow_events'], $triggers->pluck('t')->unique()->values()->all());

        // Other tables stay writable as before.
        $request = ChangeRequest::factory()->create();
        DB::table('change_requests')->where('id', $request->id)->update(['updated_at' => now()]);
        $this->addToAssertionCount(1);
    }

    public function test_a_user_who_acted_in_recorded_history_cannot_be_deleted(): void
    {
        $request = $this->accepts('change_requests', $this->row());
        $other = User::factory()->create()->id;
        $this->accepts('workflow_events', $this->event($request, [
            'from_status' => 'SUBMITTED', 'to_status' => 'UNDER_REVIEW', 'event_type' => 'REVIEW_STARTED', 'actor_user_id' => $other, 'actor_side' => 'STAFF',
        ]));

        $this->refusesSql(fn () => DB::table('users')->where('id', $other)->delete(), 'workflow_events_actor_user_id_foreign');
    }
}
