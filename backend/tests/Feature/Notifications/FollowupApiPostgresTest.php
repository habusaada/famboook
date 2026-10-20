<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\RequestPublicationAction;
use App\Models\Branch;
use App\Models\CoordinatorScopeAssignment;
use App\Models\NotificationFollowup;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\Notifications\NotificationFollowupEngine;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\NotificationActionFixtures;
use Tests\Support\Notifications\NotificationActorProcesses;
use Tests\TestCase;

/**
 * FB-NOTIFY-01E.3-F3.2 on PostgreSQL: the follow-up API against concurrent
 * sessions and the real database rules — idempotent contacts under
 * concurrency, a same-key different payload, lock-timeout mapping, scope
 * revocation and family acknowledgement against HTTP writes, the unique
 * index and CHECK of client_reference, and the migration's compatibility.
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database). Fixtures
 * are committed (DatabaseTruncation). Synthetic data only.
 */
class FollowupApiPostgresTest extends TestCase
{
    use DatabaseTruncation, NotificationActionFixtures, NotificationActorProcesses;

    private const COORDINATOR = '/api/v1/family/coordinator/followups';

    private const STAFF = '/api/v1/notifications/followups';

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private User $admin;

    private User $administrator;

    private User $head;

    private User $primary;

    private CoordinatorScopeAssignment $primaryAssignment;

    private Branch $testBranch;

    private NotificationMessage $message;

    private NotificationRecipient $recipient;

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        $this->setUpNotificationActions();
        config(['notifications.followups_enabled' => true, 'notifications.followups_cutoff' => '2000-01-01T00:00:00Z']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('SUPER_ADMIN');
        $this->administrator = User::factory()->create();
        $this->administrator->assignRole('ADMINISTRATOR');
        $clan = $this->clan();
        $this->testBranch = $this->branch($clan, $this->group($clan));

        $household = $this->activatedHead('123456789');
        $this->head = $household['user'];
        $household['family']->forceFill(['clan_id' => $clan->id, 'branch_id' => $this->testBranch->id])->save();
        $this->primary = $this->activatedHead('111111110', ['FAMILY_USER', 'COORDINATOR'])['user'];
        $this->primaryAssignment = $this->assign($this->primary, $this->testBranch);

        $staff = $this->staffSender();
        $draft = $this->draft($staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$household['family']->id]], ['level' => 'IMPORTANT']);
        app(RequestPublicationAction::class)->handle($staff, $draft);
        app(NotificationPublisher::class)->publish($draft->id);
        DB::table('notification_messages')->where('id', $draft->id)->update(['published_at' => now()->subHours(80)]);
        $this->freshHeartbeat();
        app(NotificationFollowupEngine::class)->run();
        $this->message = $draft->fresh();
        $this->recipient = NotificationRecipient::query()->where('message_id', $this->message->id)->sole();
        $this->exportActorEnvironment();
    }

    protected function tearDown(): void
    {
        $this->stopActors();
        if (isset($this->app)) {
            $this->resetConcurrencyDatabase();
        }
        parent::tearDown();
    }

    private function followup(string $stage = 'FIRST'): NotificationFollowup
    {
        return NotificationFollowup::query()->where('recipient_id', $this->recipient->id)->where('stage', $stage)->sole();
    }

    private function contacts(): int
    {
        return NotificationRecipientEvent::query()->where('recipient_id', $this->recipient->id)->where('event_type', 'CONTACT_RECORDED')->count();
    }

    private function assertSomeoneWaitsOnALock(): void
    {
        $waiting = 0;
        for ($i = 0; $i < 30 && $waiting === 0; $i++) {
            usleep(100_000);
            $waiting = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock'")->n;
        }
        $this->assertGreaterThan(0, $waiting, 'the second session must be WAITING on a lock');
    }

    // ------------------------------------------------------------ idempotency

    public function test_concurrent_identical_contacts_with_one_key_record_exactly_one_event(): void
    {
        $reference = (string) Str::uuid();
        $first = $this->spawn('followup-contact-pause', (string) $this->primary->id, $this->followup()->uuid, 'VISITED', $reference);
        $this->go($first);
        $this->assertSame('PAUSED', $this->lineOf($first));
        $second = $this->spawn('followup-contact', (string) $this->primary->id, $this->followup()->uuid, 'VISITED', $reference);
        $this->go($second);
        $this->assertSomeoneWaitsOnALock();
        $this->go($first);

        $this->assertSame('OK', $this->lineOf($first));
        $this->assertSame('OK replayed', $this->lineOf($second));
        $this->assertSame(1, $this->contacts());
    }

    public function test_a_concurrent_same_key_with_another_payload_conflicts_and_writes_nothing(): void
    {
        $reference = (string) Str::uuid();
        $first = $this->spawn('followup-contact-pause', (string) $this->primary->id, $this->followup()->uuid, 'VISITED', $reference);
        $this->go($first);
        $this->assertSame('PAUSED', $this->lineOf($first));
        $second = $this->spawn('followup-contact', (string) $this->primary->id, $this->followup()->uuid, 'CALLED_NO_ANSWER', $reference);
        $this->go($second);
        $this->assertSomeoneWaitsOnALock();
        $this->go($first);

        $this->assertSame('OK', $this->lineOf($first));
        $this->assertSame('DENIED FOLLOWUP_CLIENT_REFERENCE_CONFLICT', $this->lineOf($second));
        $this->assertSame(1, $this->contacts());
        $this->assertSame('VISITED', NotificationRecipientEvent::query()->where('event_type', 'CONTACT_RECORDED')->sole()->reason_code);
    }

    public function test_the_database_enforces_the_key_scope_and_the_contact_only_rule(): void
    {
        $this->actingAs($this->primary->fresh())->postJson(self::COORDINATOR."/{$this->followup()->uuid}/contacts",
            ['outcome' => 'OTHER', 'client_reference' => $reference = (string) Str::uuid()])->assertCreated();
        $event = NotificationRecipientEvent::query()->where('event_type', 'CONTACT_RECORDED')->sole();
        $this->assertSame($reference, $event->client_reference);
        $row = fn (array $o) => [
            'message_id' => $this->message->id, 'recipient_id' => $this->recipient->id, 'followup_id' => $this->followup()->id,
            'event_type' => 'CONTACT_RECORDED', 'actor_user_id' => $this->primary->id, 'actor_side' => 'COORDINATOR',
            'reason_code' => 'OTHER', 'metadata' => '{}', 'created_at' => now(), ...$o,
        ];

        // Unique (followup, actor, client_reference): a second row is refused (23505)…
        $this->assertQueryFails('23505', fn () => DB::table('notification_recipient_events')->insert($row(['client_reference' => $reference])));
        // …but another actor or another follow-up may use the same value.
        DB::table('notification_recipient_events')->insert($row(['client_reference' => $reference, 'actor_user_id' => $this->administrator->id, 'actor_side' => 'STAFF']));
        DB::table('notification_recipient_events')->insert($row(['client_reference' => $reference, 'followup_id' => $this->followup('SECOND')->id]));
        // chk_nre_client_reference: never on another event type, never without a person (23514).
        $this->assertQueryFails('23514', fn () => DB::table('notification_recipient_events')->insert($row([
            'client_reference' => (string) Str::uuid(), 'event_type' => 'FOLLOWUP_OPENED', 'actor_user_id' => null, 'actor_side' => 'SYSTEM', 'reason_code' => null,
        ])));
    }

    private function assertQueryFails(string $sqlState, callable $statement): void
    {
        try {
            DB::transaction($statement);
            $this->fail("expected SQLSTATE {$sqlState}");
        } catch (QueryException $e) {
            $this->assertSame($sqlState, $e->errorInfo[0] ?? null);
        }
    }

    public function test_the_migration_is_additive_and_refuses_to_drop_stored_references(): void
    {
        $migration = require database_path('migrations/2026_10_25_090000_add_client_reference_to_notification_recipient_events.php');
        $this->assertTrue(Schema::hasColumn('notification_recipient_events', 'client_reference'));

        // Without stored references it rolls back and re-applies cleanly; the history is untouched.
        $before = NotificationRecipientEvent::query()->count();
        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('notification_recipient_events', 'client_reference'));
        } finally {
            $migration->up();
        }
        $this->assertTrue(Schema::hasColumn('notification_recipient_events', 'client_reference'));
        $this->assertSame($before, NotificationRecipientEvent::query()->count());
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM pg_indexes WHERE indexname = 'notification_recipient_events_contact_reference_unique'")->n);
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conname = 'chk_nre_client_reference'")->n);

        // Once a reference is stored, rollback is refused.
        $this->actingAs($this->primary->fresh())->postJson(self::COORDINATOR."/{$this->followup()->uuid}/contacts",
            ['outcome' => 'OTHER', 'client_reference' => (string) Str::uuid()])->assertCreated();
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    // ------------------------------------------------- HTTP under concurrency

    public function test_a_lock_timeout_is_503_followup_busy_and_writes_nothing(): void
    {
        // Another session holds the recipient row longer than lock_timeout (5 s).
        $holder = $this->spawn('hold-recipient', (string) $this->recipient->id);
        $this->go($holder, 8000);
        usleep(300_000);

        $response = $this->actingAs($this->administrator->fresh())->postJson(self::STAFF."/{$this->followup()->uuid}/close", ['reason' => 'NOT_APPLICABLE']);
        $response->assertStatus(503)->assertHeader('Retry-After', '1')->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['code' => 'FOLLOWUP_BUSY']);
        $this->assertSame('OK', $this->lineOf($holder));
        $this->assertSame('OPEN', $this->followup()->status->value);
        $this->assertSame(0, NotificationRecipientEvent::query()->where('event_type', 'FOLLOWUP_CLOSED')->count());

        // Retried after the holder: it goes through.
        $this->actingAs($this->administrator->fresh())->postJson(self::STAFF."/{$this->followup()->uuid}/close", ['reason' => 'NOT_APPLICABLE'])->assertOk();
    }

    public function test_a_scope_revocation_during_an_http_contact_refuses_it(): void
    {
        // Another, unrelated scope keeps the Coordinator Space open after the revocation.
        $this->assign($this->primary, $this->clan('OTHER_CLAN'));
        $revoke = $this->spawn('hold-revoke', (string) $this->primaryAssignment->id, (string) $this->admin->id);
        $this->go($revoke, 1500);
        usleep(300_000);

        // Visible when the request starts (the revocation is not committed yet); the action
        // waits on the assignment row, then re-decides: refused, nothing written.
        $this->actingAs($this->primary->fresh())->postJson(self::COORDINATOR."/{$this->followup()->uuid}/contacts",
            ['outcome' => 'OTHER', 'client_reference' => (string) Str::uuid()])->assertForbidden()->assertJson(['code' => 'FOLLOWUP_ACTOR_NOT_ALLOWED']);
        $this->assertSame('OK', $this->lineOf($revoke));
        $this->assertSame(0, $this->contacts());
        // After it: out of scope — the same 404 as a nonexistent follow-up.
        $this->actingAs($this->primary->fresh())->getJson(self::COORDINATOR."/{$this->followup()->uuid}")->assertNotFound();
    }

    public function test_an_http_closure_and_a_family_acknowledgement_close_each_stage_once(): void
    {
        $acknowledgement = $this->spawn('hold-recipient', (string) $this->recipient->id);
        $this->go($acknowledgement, 1500);
        usleep(300_000);
        // The closure waits for the recipient row, then closes FIRST.
        $this->actingAs($this->primary->fresh())->postJson(self::COORDINATOR."/{$this->followup()->uuid}/close", ['reason' => 'INFORMED_IN_PERSON'])
            ->assertOk()->assertJson(['data' => ['outcome' => 'CLOSED_MANUALLY']]);
        $this->assertSame('OK', $this->lineOf($acknowledgement));

        $family = $this->spawn('family-acknowledge', (string) $this->head->id, $this->message->uuid);
        $this->go($family);
        $this->assertSame('OK recorded', $this->lineOf($family));
        $this->assertSame(['CLOSED_MANUALLY', 'SATISFIED'], [$this->followup()->outcome->value, $this->followup('SECOND')->outcome->value]);
        $this->actingAs($this->primary->fresh())->postJson(self::COORDINATOR."/{$this->followup('SECOND')->uuid}/close", ['reason' => 'INFORMED_IN_PERSON'])
            ->assertStatus(409)->assertJson(['code' => 'FOLLOWUP_NOT_OPEN']);
    }
}
