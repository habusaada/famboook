<?php

namespace Tests\Feature\Notifications;

use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\Notifications\NotificationCodes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2a PostgreSQL schema: the CHECK constraints, the notice-code
 * sequence, the unique and composite foreign keys, the append-only trigger
 * and the migrations' refusing down(). PostgreSQL-only like every CHECK in
 * the repository: skipped on the default SQLite suite, run with
 * phpunit.pgsql.xml against the disposable test cluster. Rows are written
 * with the query builder so the database, not a model, is what refuses
 * them. Synthetic data only.
 */
class NotificationSchemaTest extends TestCase
{
    use RefreshDatabase;

    private int $familyId;

    private int $headPersonId;

    private int $otherFamilyId;

    private int $familyUser;

    private int $staff;

    private int $coordinator;

    private int $originEvent;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints, the sequence and the append-only trigger are PostgreSQL-only.');
        }

        $head = FamilyMembership::factory()->householdHead()->create();
        $this->familyId = $head->family_id;
        $this->headPersonId = $head->person_id;
        $this->otherFamilyId = Family::factory()->create()->id;
        $this->familyUser = User::factory()->familySide()->create()->id;
        $this->coordinator = User::factory()->familySide()->create()->id;
        $this->staff = User::factory()->create()->id;
        $this->originEvent = $this->workflowEvent();
    }

    private function workflowEvent(): int
    {
        $request = ChangeRequest::factory()->create(['family_id' => $this->familyId]);

        return WorkflowEvent::create([
            'workflowable_type' => 'change_request', 'workflowable_id' => $request->id, 'from_status' => null,
            'to_status' => 'SUBMITTED', 'event_type' => 'SUBMITTED', 'actor_user_id' => $request->submitted_by, 'actor_side' => 'FAMILY',
        ])->id;
    }

    /** @return array<string, mixed> a valid Staff DRAFT announcement, overridden by $o */
    private function message(array $o = []): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'notice_code' => NotificationCodes::next(), 'kind' => 'ANNOUNCEMENT',
            'sender_side' => 'STAFF', 'level' => 'NORMAL', 'status' => 'DRAFT', 'title' => 'إعلان تجريبي',
            'body' => 'نص إعلان تجريبي.', 'sender_user_id' => $this->staff, 'created_at' => now(), 'updated_at' => now(),
            ...$o,
        ];
    }

    /** @return array<string, mixed> */
    private function systemMessage(array $o = []): array
    {
        return $this->message(['kind' => 'SYSTEM', 'sender_side' => 'SYSTEM', 'sender_user_id' => null,
            'origin_workflow_event_id' => $this->originEvent, 'status' => 'QUEUED', 'queued_at' => now(), ...$o]);
    }

    /** @return array<string, mixed> */
    private function coordinatorMessage(array $o = []): array
    {
        return $this->message(['sender_side' => 'COORDINATOR', 'sender_user_id' => $this->coordinator,
            'sender_scope' => '{"assignments":[{"scope_type":"CLAN"}]}', ...$o]);
    }

    /** @return array<string, mixed> */
    private function published(array $o = []): array
    {
        return $this->message(['status' => 'PUBLISHED', 'queued_at' => now()->subMinutes(2),
            'publishing_started_at' => now()->subMinute(), 'publishing_token' => (string) Str::uuid(), 'publish_attempts' => 1,
            'published_at' => now(), 'recipient_count' => 1, ...$o]);
    }

    /** @return array<string, mixed> */
    private function recipient(int $messageId, array $o = []): array
    {
        return [
            'message_id' => $messageId, 'family_id' => $this->familyId, 'family_status_at_publication' => 'ACTIVE',
            'head_person_id' => $this->headPersonId, 'had_family_account' => true, 'created_at' => now(), 'updated_at' => now(),
            ...$o,
        ];
    }

    /** @return array<string, mixed> */
    private function followup(int $recipientId, array $o = []): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'recipient_id' => $recipientId, 'stage' => 'FIRST', 'trigger_reason' => 'UNVIEWED',
            'due_at' => now(), 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now(), ...$o,
        ];
    }

    /** @return array<string, mixed> */
    private function event(int $messageId, array $o = []): array
    {
        return ['message_id' => $messageId, 'event_type' => 'QUEUED', 'actor_side' => 'SYSTEM', 'metadata' => '{}', ...$o];
    }

    /** @return array{0: int, 1: int, 2: int} a published message, its recipient and an open follow-up */
    private function chain(): array
    {
        $message = $this->accepts('notification_messages', $this->published());
        $recipient = $this->accepts('notification_recipients', $this->recipient($message));
        $followup = $this->accepts('notification_followups', $this->followup($recipient));

        return [$message, $recipient, $followup];
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
            DB::transaction($statement); // a savepoint: the test transaction stays usable
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

    public function test_the_four_tables_exist_and_no_laravel_notifications_table(): void
    {
        $this->assertSame([
            'id', 'uuid', 'notice_code', 'kind', 'sender_side', 'level', 'status', 'title', 'body', 'action_path',
            'sender_user_id', 'sender_scope', 'audience', 'client_reference', 'origin_workflow_event_id', 'recipient_count',
            'queued_at', 'publishing_started_at', 'publishing_token', 'publish_attempts', 'publish_failed_at',
            'publish_failure_code', 'published_at', 'expires_at', 'archived_at', 'cancelled_at', 'cancelled_by_user_id',
            'cancellation_reason_code', 'created_at', 'updated_at',
        ], Schema::getColumnListing('notification_messages'));
        $this->assertSame([
            'id', 'message_id', 'family_id', 'family_status_at_publication', 'head_person_id', 'had_family_account',
            'viewed_at', 'viewed_by_user_id', 'acknowledged_at', 'acknowledged_by_user_id', 'acknowledged_late',
            'created_at', 'updated_at',
        ], Schema::getColumnListing('notification_recipients'));
        $this->assertSame([
            'id', 'uuid', 'recipient_id', 'stage', 'trigger_reason', 'due_at', 'status', 'outcome', 'closed_at',
            'closed_by_user_id', 'close_reason_code', 'created_at', 'updated_at',
        ], Schema::getColumnListing('notification_followups'));
        $this->assertSame([
            'id', 'message_id', 'recipient_id', 'followup_id', 'event_type', 'actor_user_id', 'actor_side', 'reason_code',
            'internal_note', 'corrects_event_id', 'metadata', 'created_at',
            // FB-NOTIFY-01E.3-F3.2 (2026_10_25_090000): contact idempotency.
            'client_reference',
        ], Schema::getColumnListing('notification_recipient_events'));

        $this->assertFalse(Schema::hasTable('notifications'));
        foreach (['audience', 'sender_scope'] as $column) {
            $this->assertSame('jsonb', DB::selectOne(
                "select data_type from information_schema.columns where table_schema = current_schema() and table_name = 'notification_messages' and column_name = ?",
                [$column],
            )->data_type);
        }
    }

    public function test_indexes_and_the_code_sequence(): void
    {
        $indexes = collect(DB::select("select indexdef from pg_indexes where schemaname = current_schema() and tablename like 'notification_%'"))
            ->pluck('indexdef')->implode("\n");

        foreach ([
            'UNIQUE INDEX notification_messages_uuid_unique', 'UNIQUE INDEX notification_messages_notice_code_unique',
            'UNIQUE INDEX notification_messages_origin_workflow_event_id_unique',
            'UNIQUE INDEX notification_messages_sender_user_id_client_reference_unique',
            'UNIQUE INDEX notification_recipients_message_id_family_id_unique',
            'UNIQUE INDEX notification_followups_recipient_id_stage_unique',
            'notification_recipients_unviewed_index ON public.notification_recipients USING btree (family_id) WHERE (viewed_at IS NULL)',
            "notification_followups_open_due_index ON public.notification_followups USING btree (due_at) WHERE ((status)::text = 'OPEN'::text)",
        ] as $expected) {
            $this->assertStringContainsString($expected, $indexes);
        }

        $first = NotificationCodes::next();
        $this->assertMatchesRegularExpression('/^NTF-\d{6}$/', $first);
        $this->assertSame((int) substr($first, 4) + 1, (int) substr(NotificationCodes::next(), 4));
    }

    public function test_every_check_constraint_is_installed(): void
    {
        $installed = collect(DB::select(
            "select conrelid::regclass::text as t, conname as c from pg_constraint where contype = 'c' and conrelid::regclass::text like 'notification_%'"
        ))->groupBy('t')->map(fn ($rows) => $rows->pluck('c')->sort()->values()->all())->all();

        $expected = [
            'notification_messages' => ['chk_nm_action_path', 'chk_nm_archive_on_expiry', 'chk_nm_archived', 'chk_nm_attempts', 'chk_nm_body',
                'chk_nm_cancel_actor', 'chk_nm_cancelled', 'chk_nm_codes', 'chk_nm_draft', 'chk_nm_expiry', 'chk_nm_failed', 'chk_nm_failure',
                'chk_nm_json', 'chk_nm_kind', 'chk_nm_kind_side', 'chk_nm_level', 'chk_nm_notice_code', 'chk_nm_origin', 'chk_nm_published',
                'chk_nm_publishing', 'chk_nm_queued', 'chk_nm_recipient_count', 'chk_nm_sender_scope', 'chk_nm_sender_side', 'chk_nm_sender_user',
                'chk_nm_status', 'chk_nm_system_no_draft', 'chk_nm_system_reference', 'chk_nm_title'],
            'notification_recipients' => ['chk_nr_ack_actor', 'chk_nr_ack_requires_view', 'chk_nr_family_status', 'chk_nr_late', 'chk_nr_view_actor'],
            'notification_followups' => ['chk_nf_close_reason', 'chk_nf_closed', 'chk_nf_manual', 'chk_nf_outcome', 'chk_nf_stage',
                'chk_nf_stage_reason', 'chk_nf_status', 'chk_nf_trigger_reason'],
            'notification_recipient_events' => ['chk_nre_actor', 'chk_nre_actor_side', 'chk_nre_client_reference', 'chk_nre_correction', 'chk_nre_correction_actor',
                'chk_nre_event_type', 'chk_nre_family_actor', 'chk_nre_internal_note', 'chk_nre_metadata', 'chk_nre_reason_code', 'chk_nre_scope'],
        ];
        ksort($installed);
        ksort($expected);
        $this->assertSame($expected, $installed);
    }

    public function test_the_three_valid_sender_shapes_and_lifecycle_states_are_accepted(): void
    {
        $this->accepts('notification_messages', $this->message());
        $this->accepts('notification_messages', $this->coordinatorMessage());
        $this->accepts('notification_messages', $this->systemMessage());
        $this->accepts('notification_messages', $this->published(['level' => 'IMPORTANT', 'recipient_count' => 1800]));

        // A family-relative link to an authorized view.
        $this->accepts('notification_messages', $this->published(['action_path' => '/family/requests/'.Str::uuid()]));
        // PUBLISHING carries the worker token.
        $this->accepts('notification_messages', $this->message(['status' => 'PUBLISHING', 'queued_at' => now(),
            'publishing_started_at' => now(), 'publishing_token' => (string) Str::uuid(), 'publish_attempts' => 1]));
        // Archived by the expiry sweep, at or after expires_at.
        $this->accepts('notification_messages', $this->published(['status' => 'ARCHIVED', 'published_at' => now()->subDays(3),
            'expires_at' => now()->subDay(), 'archived_at' => now()]));
        // D3: failure history stays after a retry, a success, or a cancellation.
        $failure = ['publish_failed_at' => now()->subMinutes(5), 'publish_failure_code' => 'STALE'];
        $this->accepts('notification_messages', $this->message(['status' => 'PUBLISH_FAILED', 'queued_at' => now(), 'publish_attempts' => 1, ...$failure]));
        $this->accepts('notification_messages', $this->message(['status' => 'QUEUED', 'queued_at' => now(), 'publish_attempts' => 1, ...$failure]));
        $this->accepts('notification_messages', $this->published($failure));
        $this->accepts('notification_messages', $this->message(['status' => 'CANCELLED', 'queued_at' => now(), 'publish_attempts' => 1,
            'cancelled_at' => now(), 'cancelled_by_user_id' => $this->staff, 'cancellation_reason_code' => 'SENDER_WITHDREW', ...$failure]));
        // Cancelled straight from DRAFT; a SYSTEM message cancelled after a failure.
        $this->accepts('notification_messages', $this->message(['status' => 'CANCELLED', 'cancelled_at' => now(), 'cancelled_by_user_id' => $this->staff]));
        $this->accepts('notification_messages', $this->systemMessage(['origin_workflow_event_id' => $this->workflowEvent(),
            'status' => 'CANCELLED', 'cancelled_at' => now(), 'cancelled_by_user_id' => $this->staff, ...$failure]));
    }

    public function test_invalid_kind_and_sender_combinations_are_refused(): void
    {
        $this->refuses('notification_messages', $this->message(['sender_side' => 'SYSTEM', 'sender_user_id' => null]), 'chk_nm_kind_side');
        $this->refuses('notification_messages', $this->systemMessage(['sender_side' => 'STAFF', 'sender_user_id' => $this->staff]), 'chk_nm_kind_side');
        $this->refuses('notification_messages', $this->message(['sender_user_id' => null]), 'chk_nm_sender_user');
        $this->refuses('notification_messages', $this->systemMessage(['sender_user_id' => $this->staff]), 'chk_nm_sender_user');
        $this->refuses('notification_messages', $this->coordinatorMessage(['sender_scope' => null]), 'chk_nm_sender_scope');
        $this->refuses('notification_messages', $this->message(['sender_scope' => '{"assignments":[]}']), 'chk_nm_sender_scope');
        $this->refuses('notification_messages', $this->systemMessage(['origin_workflow_event_id' => null]), 'chk_nm_origin');
        $this->refuses('notification_messages', $this->message(['origin_workflow_event_id' => $this->originEvent]), 'chk_nm_origin');
        $this->refuses('notification_messages', $this->systemMessage(['status' => 'DRAFT', 'queued_at' => null]), 'chk_nm_system_no_draft');
        $this->refuses('notification_messages', $this->systemMessage(['client_reference' => (string) Str::uuid()]), 'chk_nm_system_reference');
        $this->refuses('notification_messages', $this->message(['kind' => 'NOTICE']), 'chk_nm_kind');
        $this->refuses('notification_messages', $this->message(['sender_side' => 'FAMILY']), 'chk_nm_sender_side');
        $this->refuses('notification_messages', $this->message(['level' => 'URGENT']), 'chk_nm_level');
        $this->refuses('notification_messages', $this->coordinatorMessage(['sender_scope' => '[1]']), 'chk_nm_json');
    }

    public function test_invalid_publication_states_are_refused(): void
    {
        $this->refuses('notification_messages', $this->message(['status' => 'SENT', 'queued_at' => now()]), 'chk_nm_status');
        $this->refuses('notification_messages', $this->published(['published_at' => null]), 'chk_nm_published');
        $this->refuses('notification_messages', $this->message(['status' => 'QUEUED', 'queued_at' => now(), 'published_at' => now()]), 'chk_nm_published');
        $this->refuses('notification_messages', $this->published(['recipient_count' => null]), 'chk_nm_recipient_count');
        $this->refuses('notification_messages', $this->published(['recipient_count' => 0]), 'chk_nm_recipient_count');
        $this->refuses('notification_messages', $this->message(['status' => 'QUEUED', 'queued_at' => now(), 'recipient_count' => 3]), 'chk_nm_recipient_count');
        $this->refuses('notification_messages', $this->message(['queued_at' => now()]), 'chk_nm_draft');
        $this->refuses('notification_messages', $this->message(['status' => 'QUEUED']), 'chk_nm_queued');
        $this->refuses('notification_messages', $this->message(['status' => 'PUBLISHING', 'queued_at' => now(), 'publishing_started_at' => now()]), 'chk_nm_publishing');
        $this->refuses('notification_messages', $this->message(['status' => 'PUBLISH_FAILED', 'queued_at' => now()]), 'chk_nm_failed');
        $this->refuses('notification_messages', $this->message(['status' => 'PUBLISH_FAILED', 'queued_at' => now(), 'publish_failed_at' => now()]), 'chk_nm_failure');
        $this->refuses('notification_messages', $this->message(['status' => 'PUBLISH_FAILED', 'queued_at' => now(),
            'publish_failed_at' => now(), 'publish_failure_code' => 'database exploded']), 'chk_nm_codes');
        $this->refuses('notification_messages', $this->published(['expires_at' => now()->subMinute()]), 'chk_nm_expiry');
        $this->refuses('notification_messages', $this->published(['expires_at' => now()]), 'chk_nm_expiry');
        // D4: no manual archive — ARCHIVED needs a reached expiry.
        $this->refuses('notification_messages', $this->published(['status' => 'ARCHIVED', 'archived_at' => now()]), 'chk_nm_archive_on_expiry');
        $this->refuses('notification_messages', $this->published(['status' => 'ARCHIVED', 'expires_at' => now()->addDay(), 'archived_at' => now()]), 'chk_nm_archive_on_expiry');
        $this->refuses('notification_messages', $this->published(['status' => 'ARCHIVED', 'expires_at' => now()->addHour()]), 'chk_nm_archived');
        $this->refuses('notification_messages', $this->published(['expires_at' => now()->addHour(), 'archived_at' => now()->addHours(2)]), 'chk_nm_archived');
        // Never cancelled after publication; always by a user.
        $this->refuses('notification_messages', $this->published(['status' => 'CANCELLED', 'cancelled_at' => now(),
            'cancelled_by_user_id' => $this->staff, 'recipient_count' => null]), 'chk_nm_published');
        $this->refuses('notification_messages', $this->message(['status' => 'CANCELLED', 'cancelled_at' => now()]), 'chk_nm_cancel_actor');
        $this->refuses('notification_messages', $this->message(['status' => 'CANCELLED']), 'chk_nm_cancelled');
        $this->refuses('notification_messages', $this->message(['cancelled_at' => now(), 'cancelled_by_user_id' => $this->staff]), 'chk_nm_cancelled');
        $this->refuses('notification_messages', $this->message(['cancellation_reason_code' => 'SENDER_WITHDREW']), 'chk_nm_cancel_actor');
    }

    public function test_content_and_link_rules(): void
    {
        $this->refuses('notification_messages', $this->message(['title' => '   ']), 'chk_nm_title');
        $this->refuses('notification_messages', $this->message(['body' => '']), 'chk_nm_body');
        $this->refuses('notification_messages', $this->message(['body' => str_repeat('ن', 2001)]), 'chk_nm_body');
        $this->accepts('notification_messages', $this->message(['body' => str_repeat('ن', 2000)]));
        foreach (['https://example.test/family/x', '/staff/families/1', '/family/../staff', '/family', '/family/requests?x=1', '//family/x'] as $path) {
            $this->refuses('notification_messages', $this->message(['action_path' => $path]), 'chk_nm_action_path');
        }
        $this->refuses('notification_messages', $this->message(['notice_code' => 'NTF-12']), 'chk_nm_notice_code');
    }

    public function test_uniqueness_of_codes_uuids_origins_and_client_references(): void
    {
        $code = NotificationCodes::next();
        $uuid = (string) Str::uuid();
        $this->accepts('notification_messages', $this->message(['notice_code' => $code, 'uuid' => $uuid]));
        $this->refuses('notification_messages', $this->message(['notice_code' => $code]), 'notification_messages_notice_code_unique');
        $this->refuses('notification_messages', $this->message(['uuid' => $uuid]), 'notification_messages_uuid_unique');

        // ADR-09: one SYSTEM message per origin event.
        $this->accepts('notification_messages', $this->systemMessage());
        $this->refuses('notification_messages', $this->systemMessage(), 'notification_messages_origin_workflow_event_id_unique');

        // Idempotent publication request per sender; no reference never collides.
        $reference = (string) Str::uuid();
        $this->accepts('notification_messages', $this->message(['client_reference' => $reference]));
        $this->refuses('notification_messages', $this->message(['client_reference' => $reference]), 'notification_messages_sender_user_id_client_reference_unique');
        $this->accepts('notification_messages', $this->coordinatorMessage(['client_reference' => $reference]));
        $this->accepts('notification_messages', $this->message());
        $this->accepts('notification_messages', $this->message());
    }

    public function test_recipient_uniqueness_and_view_acknowledgement_integrity(): void
    {
        $message = $this->accepts('notification_messages', $this->published(['level' => 'IMPORTANT', 'recipient_count' => 2]));
        $this->accepts('notification_recipients', $this->recipient($message));
        $this->refuses('notification_recipients', $this->recipient($message), 'notification_recipients_message_id_family_id_unique');
        // An accountless family without a head is kept (ADR-03).
        $this->accepts('notification_recipients', $this->recipient($message, ['family_id' => $this->otherFamilyId,
            'head_person_id' => null, 'had_family_account' => false, 'family_status_at_publication' => 'INACTIVE']));

        $other = fn (array $o) => $this->recipient($this->accepts('notification_messages', $this->published()), $o);
        $this->refuses('notification_recipients', $other(['family_status_at_publication' => 'DELETED']), 'chk_nr_family_status');
        $this->refuses('notification_recipients', $other(['viewed_at' => now()]), 'chk_nr_view_actor');
        $this->refuses('notification_recipients', $other(['viewed_by_user_id' => $this->familyUser]), 'chk_nr_view_actor');
        $this->refuses('notification_recipients', $other(['acknowledged_by_user_id' => $this->familyUser]), 'chk_nr_ack_actor');
        $this->refuses('notification_recipients', $other(['acknowledged_at' => now(), 'acknowledged_by_user_id' => $this->familyUser]), 'chk_nr_ack_requires_view');
        $this->refuses('notification_recipients', $other(['viewed_at' => now(), 'viewed_by_user_id' => $this->familyUser,
            'acknowledged_at' => now()->subMinute(), 'acknowledged_by_user_id' => $this->familyUser]), 'chk_nr_ack_requires_view');
        $this->refuses('notification_recipients', $other(['acknowledged_late' => true]), 'chk_nr_late');

        // Viewed, then acknowledged late.
        $this->accepts('notification_recipients', $other(['viewed_at' => now()->subHour(), 'viewed_by_user_id' => $this->familyUser,
            'acknowledged_at' => now(), 'acknowledged_by_user_id' => $this->familyUser, 'acknowledged_late' => true]));
    }

    public function test_followup_uniqueness_and_state_integrity(): void
    {
        [, $recipient, $first] = $this->chain();
        $this->refuses('notification_followups', $this->followup($recipient), 'notification_followups_recipient_id_stage_unique');
        // D12: FIRST and SECOND coexist.
        $this->accepts('notification_followups', $this->followup($recipient, ['stage' => 'SECOND', 'trigger_reason' => 'UNACKNOWLEDGED']));
        $this->assertGreaterThan(0, $first);

        [, $r] = $this->chain();
        $this->refuses('notification_followups', $this->followup($r, ['stage' => 'SECOND']), 'chk_nf_stage_reason');
        $this->refuses('notification_followups', $this->followup($r, ['stage' => 'THIRD', 'trigger_reason' => 'UNACKNOWLEDGED']), 'chk_nf_stage');
        $this->refuses('notification_followups', $this->followup($r, ['trigger_reason' => 'IGNORED']), 'chk_nf_trigger_reason');
        $this->refuses('notification_followups', $this->followup($r, ['status' => 'DONE']), 'chk_nf_status');
        $this->refuses('notification_followups', $this->followup($r, ['status' => 'CLOSED', 'closed_at' => now(), 'outcome' => 'RESOLVED']), 'chk_nf_outcome');
        $this->refuses('notification_followups', $this->followup($r, ['status' => 'CLOSED', 'closed_at' => now()]), 'chk_nf_closed');
        $this->refuses('notification_followups', $this->followup($r, ['outcome' => 'SATISFIED']), 'chk_nf_closed');
        $this->refuses('notification_followups', $this->followup($r, ['status' => 'CLOSED', 'outcome' => 'SATISFIED']), 'chk_nf_closed');
        $this->refuses('notification_followups', $this->followup($r, ['status' => 'CLOSED', 'closed_at' => now(), 'outcome' => 'CLOSED_MANUALLY',
            'close_reason_code' => 'FAMILY_CONTACTED_OFFLINE']), 'chk_nf_manual');
        $this->refuses('notification_followups', $this->followup($r, ['status' => 'CLOSED', 'closed_at' => now(), 'outcome' => 'CLOSED_MANUALLY',
            'closed_by_user_id' => $this->staff]), 'chk_nf_manual');
        $this->refuses('notification_followups', $this->followup($r, ['status' => 'CLOSED', 'closed_at' => now(), 'outcome' => 'SATISFIED',
            'closed_by_user_id' => $this->staff]), 'chk_nf_manual');
        $this->refuses('notification_followups', $this->followup($r, ['closed_by_user_id' => $this->staff]), 'chk_nf_manual');

        $this->accepts('notification_followups', $this->followup($r, ['stage' => 'SECOND', 'trigger_reason' => 'UNACKNOWLEDGED',
            'status' => 'CLOSED', 'closed_at' => now(), 'outcome' => 'CLOSED_MANUALLY', 'closed_by_user_id' => $this->staff,
            'close_reason_code' => 'FAMILY_CONTACTED_OFFLINE']));
    }

    public function test_event_scope_actor_and_correction_rules(): void
    {
        [$message, $recipient, $followup] = $this->chain();
        $family = ['actor_side' => 'FAMILY', 'actor_user_id' => $this->familyUser];
        $staff = ['actor_side' => 'STAFF', 'actor_user_id' => $this->staff];

        $this->accepts('notification_recipient_events', $this->event($message, ['event_type' => 'PUBLISHED', 'metadata' => '{"recipient_count":1}']));
        $this->accepts('notification_recipient_events', $this->event($message, ['event_type' => 'VIEWED', 'recipient_id' => $recipient, ...$family]));
        $this->accepts('notification_recipient_events', $this->event($message, ['event_type' => 'FOLLOWUP_OPENED', 'recipient_id' => $recipient, 'followup_id' => $followup]));
        $note = $this->accepts('notification_recipient_events', $this->event($message, ['event_type' => 'CONTACT_RECORDED',
            'recipient_id' => $recipient, 'followup_id' => $followup, 'reason_code' => 'NO_ANSWER', 'internal_note' => 'ملاحظة تجريبية', ...$staff]));
        $this->accepts('notification_recipient_events', $this->event($message, ['event_type' => 'CONTACT_RECORDED',
            'recipient_id' => $recipient, 'followup_id' => $followup, 'actor_side' => 'COORDINATOR', 'actor_user_id' => $this->coordinator]));
        $this->accepts('notification_recipient_events', $this->event($message, ['event_type' => 'NOTE_CORRECTION', 'recipient_id' => $recipient,
            'followup_id' => $followup, 'corrects_event_id' => $note, 'reason_code' => 'SENSITIVE_CONTENT', ...$staff]));

        // Scope.
        $this->refuses('notification_recipient_events', $this->event($message, ['recipient_id' => $recipient]), 'chk_nre_scope');
        $this->refuses('notification_recipient_events', $this->event($message, ['event_type' => 'VIEWED', ...$family]), 'chk_nre_scope');
        $this->refuses('notification_recipient_events', $this->event($message, ['event_type' => 'CONTACT_RECORDED', 'recipient_id' => $recipient, ...$staff]), 'chk_nre_scope');
        $this->refuses('notification_recipient_events', $this->event($message, ['event_type' => 'OPENED']), 'chk_nre_');
        // No acting for the family, and the family writes nothing else.
        $this->refuses('notification_recipient_events', $this->event($message, ['event_type' => 'ACKNOWLEDGED', 'recipient_id' => $recipient, ...$staff]), 'chk_nre_family_actor');
        $this->refuses('notification_recipient_events', $this->event($message, ['event_type' => 'VIEWED', 'recipient_id' => $recipient,
            'actor_side' => 'COORDINATOR', 'actor_user_id' => $this->coordinator]), 'chk_nre_family_actor');
        $this->refuses('notification_recipient_events', $this->event($message, ['event_type' => 'CONTACT_RECORDED', 'recipient_id' => $recipient,
            'followup_id' => $followup, ...$family]), 'chk_nre_family_actor');
        // Actor attribution.
        $this->refuses('notification_recipient_events', $this->event($message, ['actor_user_id' => $this->staff]), 'chk_nre_actor');
        $this->refuses('notification_recipient_events', $this->event($message, ['event_type' => 'CANCELLED', 'actor_side' => 'STAFF']), 'chk_nre_actor');
        // Notes: Staff / coordinator, on contact or closure, bounded.
        $this->refuses('notification_recipient_events', $this->event($message, ['event_type' => 'VIEWED', 'recipient_id' => $recipient,
            'internal_note' => 'x', ...$family]), 'chk_nre_internal_note');
        $this->refuses('notification_recipient_events', $this->event($message, ['event_type' => 'CONTACT_RECORDED', 'recipient_id' => $recipient,
            'followup_id' => $followup, 'internal_note' => str_repeat('ن', 1001), ...$staff]), 'chk_nre_internal_note');
        $this->refuses('notification_recipient_events', $this->event($message, ['event_type' => 'PUBLISHED', 'reason_code' => 'bad code']), 'chk_nre_reason_code');
        $this->refuses('notification_recipient_events', $this->event($message, ['metadata' => '[]']), 'chk_nre_metadata');
        // Corrections: a reference, by Staff, within the same follow-up.
        $this->refuses('notification_recipient_events', $this->event($message, ['event_type' => 'NOTE_CORRECTION', 'recipient_id' => $recipient,
            'followup_id' => $followup, ...$staff]), 'chk_nre_correction');
        $this->refuses('notification_recipient_events', $this->event($message, ['event_type' => 'CONTACT_RECORDED', 'recipient_id' => $recipient,
            'followup_id' => $followup, 'corrects_event_id' => $note, ...$staff]), 'chk_nre_correction');
        $this->refuses('notification_recipient_events', $this->event($message, ['event_type' => 'NOTE_CORRECTION', 'recipient_id' => $recipient,
            'followup_id' => $followup, 'corrects_event_id' => $note, 'actor_side' => 'COORDINATOR', 'actor_user_id' => $this->coordinator]), 'chk_nre_correction_actor');

        [$otherMessage, $otherRecipient, $otherFollowup] = $this->chain();
        $this->refuses('notification_recipient_events', $this->event($message, ['event_type' => 'NOTE_CORRECTION', 'recipient_id' => $otherRecipient,
            'followup_id' => $otherFollowup, 'corrects_event_id' => $note, ...$staff]), 'foreign key');
        // A recipient of another message, a follow-up of another recipient.
        $this->refuses('notification_recipient_events', $this->event($otherMessage, ['event_type' => 'VIEWED', 'recipient_id' => $recipient, ...$family]), 'foreign key');
        $this->refuses('notification_recipient_events', $this->event($message, ['event_type' => 'FOLLOWUP_OPENED', 'recipient_id' => $recipient,
            'followup_id' => $otherFollowup]), 'foreign key');
    }

    public function test_json_columns_accept_only_objects(): void
    {
        $this->accepts('notification_messages', $this->message(['audience' => '{}']));
        $this->accepts('notification_messages', $this->coordinatorMessage(['sender_scope' => '{}']));
        foreach (['[]', '["ALL"]', '5', '"ALL"', 'null', 'true'] as $json) {
            $this->refuses('notification_messages', $this->message(['audience' => $json]), 'chk_nm_json');
            $this->refuses('notification_messages', $this->coordinatorMessage(['sender_scope' => $json]), 'chk_nm_json');
        }
        $this->refuses('notification_messages', $this->message(['audience' => null]), 'not-null');

        [$message] = $this->chain();
        foreach (['[]', '5', 'null'] as $json) {
            $this->refuses('notification_recipient_events', $this->event($message, ['metadata' => $json]), 'chk_nre_metadata');
        }
        $this->refuses('notification_recipient_events', $this->event($message, ['metadata' => null]), 'not-null');
    }

    public function test_attempts_are_never_negative_and_an_event_never_corrects_itself(): void
    {
        $this->refuses('notification_messages', $this->message(['status' => 'QUEUED', 'queued_at' => now(), 'publish_attempts' => -1]), 'chk_nm_attempts');

        [$message, $recipient, $followup] = $this->chain();
        $id = (int) DB::scalar("select nextval(pg_get_serial_sequence('notification_recipient_events', 'id'))") + 100;
        $this->refuses('notification_recipient_events', $this->event($message, ['id' => $id, 'event_type' => 'NOTE_CORRECTION',
            'recipient_id' => $recipient, 'followup_id' => $followup, 'corrects_event_id' => $id, 'actor_side' => 'STAFF',
            'actor_user_id' => $this->staff]), 'chk_nre_correction');
    }

    public function test_notice_codes_come_from_the_sequence_with_gaps_never_reused(): void
    {
        // A rolled-back insert consumes its number: the next code is higher, never reused.
        $lost = null;
        try {
            DB::transaction(function () use (&$lost) {
                $lost = NotificationCodes::next();
                DB::table('notification_messages')->insert($this->message(['notice_code' => $lost]));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }
        $this->assertFalse(DB::table('notification_messages')->where('notice_code', $lost)->exists());
        $this->assertGreaterThan((int) substr($lost, 4), (int) substr(NotificationCodes::next(), 4));

        // A write that bypasses the model hook gets no code, and is refused.
        $row = $this->message();
        unset($row['notice_code']);
        $this->refuses('notification_messages', $row, 'not-null');

        // Past NTF-999999 the code simply grows.
        $this->accepts('notification_messages', $this->message(['notice_code' => 'NTF-1000000']));
    }

    public function test_events_are_append_only_on_postgresql(): void
    {
        [$message] = $this->chain();
        $event = $this->accepts('notification_recipient_events', $this->event($message, ['event_type' => 'PUBLISHED']));
        $this->accepts('notification_recipient_events', $this->event($message, ['event_type' => 'EXPIRED_ARCHIVED']));

        // Direct SQL, no Eloquent involved, is refused by the trigger.
        $this->refusesSql(fn () => DB::update('update notification_recipient_events set reason_code = ? where id = ?', ['CHANGED', $event]),
            'notification_recipient_events is append-only: UPDATE');
        $this->refusesSql(fn () => DB::table('notification_recipient_events')->update(['metadata' => '{}']), 'notification_recipient_events is append-only: UPDATE');
        $this->refusesSql(fn () => DB::delete('delete from notification_recipient_events where id = ?', [$event]), 'notification_recipient_events is append-only: DELETE');
        $this->refusesSql(fn () => DB::table('notification_recipient_events')->delete(), 'notification_recipient_events is append-only: DELETE');
        $this->assertSame(2, DB::table('notification_recipient_events')->count());

        $triggers = collect(DB::select(
            "select event_object_table as t, action_timing as timing, event_manipulation as op from information_schema.triggers where trigger_name = 'trg_notification_recipient_events_append_only'"
        ));
        $this->assertSame(['notification_recipient_events'], $triggers->pluck('t')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['DELETE', 'UPDATE'], $triggers->pluck('op')->all());
        $this->assertSame(['BEFORE'], $triggers->pluck('timing')->unique()->values()->all());

        // The other notification tables stay mutable state (no trigger).
        $this->assertSame(1, DB::table('notification_messages')->where('id', $message)->update(['updated_at' => now()]));
    }

    public function test_foreign_keys_restrict_deletes(): void
    {
        // A family, a user and a message referenced ONLY by notification rows,
        // so the refusing constraint is the notification one.
        $family = Family::factory()->create()->id;
        $sender = User::factory()->create()->id;
        $message = $this->accepts('notification_messages', $this->published(['sender_user_id' => $sender]));
        $this->accepts('notification_recipients', $this->recipient($message, ['family_id' => $family, 'head_person_id' => null]));

        $this->refusesSql(fn () => DB::table('families')->where('id', $family)->delete(), 'notification_recipients_family_id_foreign');
        $this->refusesSql(fn () => DB::table('users')->where('id', $sender)->delete(), 'notification_messages_sender_user_id_foreign');
        $this->refusesSql(fn () => DB::table('notification_messages')->where('id', $message)->delete(), 'notification_recipients_message_id_foreign');

        // No cascade anywhere: every notification foreign key restricts deletes.
        $actions = collect(DB::select(
            "select conname, confdeltype from pg_constraint where contype = 'f' and conrelid::regclass::text like 'notification_%'"
        ));
        $this->assertCount(15, $actions);
        $this->assertSame(['r'], $actions->pluck('confdeltype')->unique()->values()->all());
    }

    public function test_migrations_refuse_to_drop_history(): void
    {
        [$message] = $this->chain();
        $this->accepts('notification_recipient_events', $this->event($message, ['event_type' => 'PUBLISHED']));

        foreach ([
            '2026_10_24_090003_create_notification_recipient_events_table.php' => 'notification audit history exists',
            '2026_10_24_090002_create_notification_followups_table.php' => 'follow-up history exists',
            '2026_10_24_090001_create_notification_recipients_table.php' => 'notification history exists',
            '2026_10_24_090000_create_notification_messages_table.php' => 'notification history exists',
        ] as $file => $reason) {
            $migration = require database_path('migrations/'.$file);
            try {
                $migration->down();
                $this->fail("{$file} dropped existing history.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($reason, $e->getMessage());
            }
        }

        foreach (['notification_messages', 'notification_recipients', 'notification_followups', 'notification_recipient_events'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }
}
