<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\RequestPublicationAction;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\CoordinatorScopeAssignment;
use App\Models\NotificationFollowup;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientEvent;
use App\Models\Person;
use App\Models\User;
use App\Support\Notifications\FollowupOwnership;
use App\Support\Notifications\NotificationFollowupEngine;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\NotificationActionFixtures;
use Tests\Support\Notifications\NotificationActorProcesses;
use Tests\TestCase;

/**
 * FB-NOTIFY-01E.3-F3.1 on PostgreSQL: the follow-up management actions
 * against concurrent sessions (tests/Support/Notifications/publication-
 * actor.php): scope / role revocation, a family acknowledgement, the expiry
 * closure, a second closure, new and more specific assignments (F3.1b
 * clan-row protocol) and three-session races. Lock order (coordinator):
 * clan FOR SHARE →
 * recipient → follow-up → users FOR SHARE (actor + current candidates) →
 * their assignments FOR SHARE. A waiting session is proven through
 * pg_stat_activity (a backend waiting on a Lock), not by timing.
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database). Fixtures
 * are committed (DatabaseTruncation). Synthetic data only.
 */
class FollowupManagementConcurrencyTest extends TestCase
{
    use DatabaseTruncation, NotificationActionFixtures, NotificationActorProcesses;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private User $admin;

    private User $administrator;

    private User $head;

    private User $primary;

    private Person $primaryPerson;

    private CoordinatorScopeAssignment $primaryAssignment;

    private Clan $testClan;

    private BranchGroup $testGroup;

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
        $this->testClan = $this->clan();
        $this->testGroup = $this->group($this->testClan);
        $this->testBranch = $this->branch($this->testClan, $this->testGroup);

        $household = $this->activatedHead('123456789');
        $this->head = $household['user'];
        $household['family']->forceFill(['clan_id' => $this->testClan->id, 'branch_id' => $this->testBranch->id])->save();
        $coordinator = $this->activatedHead('111111110', ['FAMILY_USER', 'COORDINATOR']);
        [$this->primary, $this->primaryPerson] = [$coordinator['user'], $coordinator['person']];
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
        $this->assertSame(2, NotificationFollowup::query()->where('recipient_id', $this->recipient->id)->count());
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

    private function events(string $type): int
    {
        return NotificationRecipientEvent::query()->where('recipient_id', $this->recipient->id)->where('event_type', $type)->count();
    }

    /** Another session is blocked on a row lock of this test database (pg_stat_activity). */
    private function assertSomeoneWaitsOnALock(): void
    {
        $waiting = 0;
        for ($i = 0; $i < 30 && $waiting === 0; $i++) {
            usleep(100_000);
            $waiting = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock'")->n;
        }
        $this->assertGreaterThan(0, $waiting, 'the second session must be WAITING on a lock');
    }

    /**
     * A session waits for a ROW of $table: PostgreSQL gives the first waiter
     * of a row lock a tuple lock on that relation while it waits.
     */
    private function assertSomeoneWaitsOnARowOf(string $table): void
    {
        $waiting = 0;
        for ($i = 0; $i < 30 && $waiting === 0; $i++) {
            usleep(100_000);
            $waiting = (int) DB::selectOne(
                "SELECT count(*) AS n FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid
                 WHERE l.locktype = 'tuple' AND l.relation = ?::regclass AND a.wait_event_type = 'Lock'", [$table])->n;
        }
        $this->assertGreaterThan(0, $waiting, "a session must be WAITING on a {$table} row");
    }

    /** Starts an action paused at its event INSERT, every lock held. */
    private function paused(string ...$args): int
    {
        $child = $this->spawn(...$args);
        $this->go($child);
        $this->assertSame('PAUSED', $this->lineOf($child));

        return $child;
    }

    /** A second coordinator (household head + COORDINATOR) of a family outside the test structure. */
    private function coordinatorAccount(string $nationalId): array
    {
        $account = $this->activatedHead($nationalId, ['FAMILY_USER', 'COORDINATOR']);

        return [$account['user'], $account['person']];
    }

    // ------------------------------------------------------------ revocation

    public function test_a_scope_revocation_waits_for_a_running_contact_and_then_ends_management(): void
    {
        $contact = $this->paused('followup-contact-pause', (string) $this->primary->id, $this->followup()->uuid, 'CALLED_NO_ANSWER');
        $revoke = $this->spawn('revoke-scope', (string) $this->primaryAssignment->id, (string) $this->admin->id);
        $this->go($revoke);
        $this->assertSomeoneWaitsOnALock();
        $this->go($contact);

        $this->assertSame('OK', $this->lineOf($contact));
        $this->assertSame('OK', $this->lineOf($revoke));
        $after = $this->spawn('followup-contact', (string) $this->primary->id, $this->followup()->uuid, 'CALLED_NO_ANSWER');
        $this->go($after);
        $this->assertSame('DENIED FOLLOWUP_ACTOR_NOT_ALLOWED', $this->lineOf($after));
        $this->assertSame(1, $this->events('CONTACT_RECORDED'));
    }

    public function test_a_contact_waits_for_a_running_scope_revocation_and_is_then_refused(): void
    {
        $revoke = $this->spawn('hold-revoke', (string) $this->primaryAssignment->id, (string) $this->admin->id);
        $contact = $this->spawn('followup-contact', (string) $this->primary->id, $this->followup()->uuid, 'CALLED_NO_ANSWER');
        $this->go($revoke, 1500);
        $this->go($contact);
        $this->assertSomeoneWaitsOnALock();

        $this->assertSame('OK', $this->lineOf($revoke));
        $this->assertSame('DENIED FOLLOWUP_ACTOR_NOT_ALLOWED', $this->lineOf($contact));
        $this->assertSame(0, $this->events('CONTACT_RECORDED'));
    }

    public function test_a_role_revocation_waits_for_a_running_contact_and_a_contact_waits_for_a_running_role_revocation(): void
    {
        $contact = $this->paused('followup-contact-pause', (string) $this->primary->id, $this->followup()->uuid, 'VISITED');
        $revoke = $this->spawn('revoke-role', (string) $this->primaryPerson->id, (string) $this->admin->id);
        $this->go($revoke);
        $this->assertSomeoneWaitsOnALock();
        $this->go($contact);
        $this->assertSame('OK', $this->lineOf($contact));
        $this->assertSame('OK', $this->lineOf($revoke));
        $this->assertSame(1, $this->events('CONTACT_RECORDED'));

        // The reverse order with a second primary coordinator.
        [$second, $secondPerson] = $this->coordinatorAccount('211111110');
        $this->assign($second, $this->testBranch);
        $hold = $this->spawn('hold-revoke-role', (string) $secondPerson->id, (string) $this->admin->id);
        $late = $this->spawn('followup-contact', (string) $second->id, $this->followup()->uuid, 'VISITED');
        $this->go($hold, 1500);
        $this->go($late);
        $this->assertSomeoneWaitsOnALock();
        $this->assertSame('OK', $this->lineOf($hold));
        $this->assertSame('DENIED FOLLOWUP_ACTOR_NOT_ALLOWED', $this->lineOf($late));
        $this->assertSame(1, $this->events('CONTACT_RECORDED'));
    }

    public function test_a_manual_closure_racing_a_scope_revocation_either_closes_first_or_is_refused(): void
    {
        $close = $this->paused('followup-close-pause', (string) $this->primary->id, $this->followup()->uuid, 'INFORMED_BY_PHONE');
        $revoke = $this->spawn('revoke-scope', (string) $this->primaryAssignment->id, (string) $this->admin->id);
        $this->go($revoke);
        $this->assertSomeoneWaitsOnALock();
        $this->go($close);
        $this->assertSame('OK', $this->lineOf($close));
        $this->assertSame('OK', $this->lineOf($revoke));
        $this->assertSame([$this->primary->id, 'CLOSED_MANUALLY'], [$this->followup()->closed_by_user_id, $this->followup()->outcome->value]);

        // Revocation first: the closure of SECOND is refused, nothing written.
        $again = $this->assign($this->primary, $this->testBranch);
        $hold = $this->spawn('hold-revoke', (string) $again->id, (string) $this->admin->id);
        $refused = $this->spawn('followup-close', (string) $this->primary->id, $this->followup('SECOND')->uuid, 'INFORMED_BY_PHONE');
        $this->go($hold, 1500);
        $this->go($refused);
        $this->assertSomeoneWaitsOnALock();
        $this->assertSame('OK', $this->lineOf($hold));
        $this->assertSame('DENIED FOLLOWUP_ACTOR_NOT_ALLOWED', $this->lineOf($refused));
        $this->assertSame('OPEN', $this->followup('SECOND')->status->value);
        $this->assertSame(1, $this->events('FOLLOWUP_CLOSED'));
    }

    // ------------------------------------------- acknowledgement, expiry, twice

    public function test_a_family_acknowledgement_waits_for_a_manual_closure_and_satisfies_only_what_stays_open(): void
    {
        $close = $this->paused('followup-close-pause', (string) $this->administrator->id, $this->followup()->uuid, 'INFORMED_IN_PERSON');
        $acknowledgement = $this->spawn('family-acknowledge', (string) $this->head->id, $this->message->uuid);
        $this->go($acknowledgement);
        $this->assertSomeoneWaitsOnALock();
        $this->go($close);

        $this->assertSame('OK', $this->lineOf($close));
        $this->assertSame('OK recorded', $this->lineOf($acknowledgement));
        $this->assertSame(['CLOSED_MANUALLY', 'SATISFIED'], [$this->followup()->outcome->value, $this->followup('SECOND')->outcome->value]);
        $this->assertSame([2, 1], [$this->events('FOLLOWUP_CLOSED'), $this->events('ACKNOWLEDGED')]);

        // Acknowledged first: nothing is OPEN any more.
        $late = $this->spawn('followup-close', (string) $this->administrator->id, $this->followup('SECOND')->uuid, 'INFORMED_IN_PERSON');
        $this->go($late);
        $this->assertSame('DENIED FOLLOWUP_NOT_OPEN', $this->lineOf($late));
    }

    public function test_a_manual_closure_and_the_expiry_closure_never_both_close_a_stage(): void
    {
        // Wide enough for a slow actor start under load; then wait for the real expiry instant.
        $expiresAt = now()->addSeconds(15);
        DB::table('notification_messages')->where('id', $this->message->id)->update(['expires_at' => $expiresAt]);
        $close = $this->paused('followup-close-pause', (string) $this->administrator->id, $this->followup()->uuid, 'NOT_APPLICABLE');
        $this->assertTrue(now()->lt($expiresAt), 'the closure was authorized before expiry');
        usleep(max(0, $expiresAt->getTimestamp() - now()->getTimestamp() + 1) * 1_000_000);
        // The scheduler skips the held recipient (SKIP LOCKED) — it never waits.
        $busy = $this->spawn('followups', '100');
        $this->go($busy);
        $this->assertStringContainsString('"expiry_skipped":1', $this->lineOf($busy));
        $this->go($close);
        $this->assertSame('OK', $this->lineOf($close));

        $expiry = $this->spawn('followups', '100');
        $this->go($expiry);
        $this->assertStringContainsString('"expired":1', $this->lineOf($expiry));
        $this->assertSame(['CLOSED_MANUALLY', 'EXPIRED'], [$this->followup()->outcome->value, $this->followup('SECOND')->outcome->value]);
        $this->assertSame(2, $this->events('FOLLOWUP_CLOSED'));

        // Expired first: a manual closure is refused, nothing written.
        $late = $this->spawn('followup-close', (string) $this->administrator->id, $this->followup('SECOND')->uuid, 'NOT_APPLICABLE');
        $this->go($late);
        $this->assertSame('DENIED FOLLOWUP_NOT_OPEN', $this->lineOf($late));
        $this->assertSame(2, $this->events('FOLLOWUP_CLOSED'));
    }

    public function test_two_concurrent_manual_closures_close_once(): void
    {
        $first = $this->paused('followup-close-pause', (string) $this->administrator->id, $this->followup()->uuid, 'INFORMED_BY_PHONE');
        $second = $this->spawn('followup-close', (string) $this->admin->id, $this->followup()->uuid, 'FAMILY_UNREACHABLE');
        $this->go($second);
        $this->assertSomeoneWaitsOnALock();
        $this->go($first);

        $this->assertSame('OK', $this->lineOf($first));
        $this->assertSame('DENIED FOLLOWUP_NOT_OPEN', $this->lineOf($second));
        $this->assertSame([$this->administrator->id, 'INFORMED_BY_PHONE'], [$this->followup()->closed_by_user_id, $this->followup()->close_reason_code]);
        $this->assertSame(1, $this->events('FOLLOWUP_CLOSED'));
    }

    // ------------------------------------------------------ ownership change

    /** The primary coordinator through a BRANCH_GROUP assignment instead of the BRANCH one. */
    private function primaryThroughTheGroup(): void
    {
        $this->primaryAssignment->forceFill(['revoked_at' => now(), 'revoked_by' => $this->admin->id, 'revoke_reason' => 'ADMINISTRATIVE'])->save();
        $this->assign($this->primary, $this->testGroup);
    }

    public function test_a_more_specific_assignment_of_a_current_candidate_waits_for_the_running_action(): void
    {
        $this->primaryThroughTheGroup();
        [$candidate, $candidatePerson] = $this->coordinatorAccount('211111110');
        $this->assign($candidate, $this->testClan);

        $close = $this->paused('followup-close-pause', (string) $this->primary->id, $this->followup()->uuid, 'INFORMED_BY_PHONE');
        $assign = $this->spawn('assign-scope', (string) $candidatePerson->id, 'BRANCH:'.$this->testBranch->id, (string) $this->admin->id);
        $this->go($assign);
        $this->assertSomeoneWaitsOnALock();
        $this->go($close);

        $this->assertSame('OK', $this->lineOf($close));
        $this->assertSame('OK', $this->lineOf($assign));
        $this->assertTrue(app(FollowupOwnership::class)->isPrimary($candidate->fresh(), $this->recipient->family));
        $refused = $this->spawn('followup-contact', (string) $this->primary->id, $this->followup('SECOND')->uuid, 'OTHER');
        $this->go($refused);
        $this->assertSame('DENIED FOLLOWUP_ACTOR_NOT_ALLOWED', $this->lineOf($refused));
    }

    /**
     * F3.1b clan-row protocol: a NEW, more specific assignment of a coordinator
     * who is not a candidate yet (the former phantom owner) — action first: the
     * assignment waits on the clan row until the action commits; assignment
     * first: the action waits on the clan row, then sees the new primary and
     * is refused. Never a stale-owner completion after the assignment committed.
     *
     * @param  'followup-contact'|'followup-close'  $action
     */
    private function newAssignmentRace(string $action, string $code): void
    {
        $this->primaryThroughTheGroup();
        [$newcomer, $newcomerPerson] = $this->coordinatorAccount('211111110');
        $family = $this->recipient->family;

        // Action first.
        $running = $this->paused("{$action}-pause", (string) $this->primary->id, $this->followup()->uuid, $code);
        $assign = $this->spawn('assign-scope', (string) $newcomerPerson->id, 'BRANCH:'.$this->testBranch->id, (string) $this->admin->id);
        $this->go($assign);
        $this->assertSomeoneWaitsOnARowOf('clans');
        $this->go($running);
        $this->assertSame('OK', $this->lineOf($running), 'the action completed while it was the primary coordinator');
        $this->assertSame('OK', $this->lineOf($assign), 'the assignment committed only after the action');
        $this->assertTrue(app(FollowupOwnership::class)->isPrimary($newcomer->fresh(), $family));

        // Assignment first (a second newcomer, more specific than nothing: the first newcomer is primary now).
        [$second, $secondPerson] = $this->coordinatorAccount('222222220');
        $narrower = $this->branch($this->testClan, $this->testGroup);
        $this->recipient->family->forceFill(['branch_id' => $narrower->id])->save();
        $this->assertTrue(app(FollowupOwnership::class)->isPrimary($this->primary->fresh(), $family->fresh()), 'only the GROUP coordinator covers the new branch');
        $hold = $this->spawn('hold-assign', (string) $secondPerson->id, 'BRANCH:'.$narrower->id, (string) $this->admin->id);
        $refused = $this->spawn($action, (string) $this->primary->id, $this->followup('SECOND')->uuid, $code);
        $this->go($hold, 1500);
        $this->go($refused);
        $this->assertSomeoneWaitsOnARowOf('clans');
        $this->assertSame('OK', $this->lineOf($hold));
        $this->assertSame('DENIED FOLLOWUP_ACTOR_NOT_ALLOWED', $this->lineOf($refused), 'never under the stale owner');
        $this->assertTrue(app(FollowupOwnership::class)->isPrimary($second->fresh(), $family->fresh()));
    }

    public function test_a_new_more_specific_assignment_and_a_contact_are_serialized_in_both_orders(): void
    {
        $this->newAssignmentRace('followup-contact', 'CALLED_NO_ANSWER');
        $this->assertSame(1, $this->events('CONTACT_RECORDED'));
        $this->assertSame('OPEN', $this->followup('SECOND')->status->value);
    }

    public function test_a_new_more_specific_assignment_and_a_manual_closure_are_serialized_in_both_orders(): void
    {
        $this->newAssignmentRace('followup-close', 'INFORMED_BY_PHONE');
        $this->assertSame([$this->primary->id, 'CLOSED_MANUALLY'], [$this->followup()->closed_by_user_id, $this->followup()->outcome->value]);
        $this->assertSame('OPEN', $this->followup('SECOND')->status->value);
        $this->assertSame(1, $this->events('FOLLOWUP_CLOSED'));
    }

    public function test_a_role_revocation_and_a_manual_closure_are_serialized_in_both_orders(): void
    {
        $close = $this->paused('followup-close-pause', (string) $this->primary->id, $this->followup()->uuid, 'INFORMED_BY_PHONE');
        $revoke = $this->spawn('revoke-role', (string) $this->primaryPerson->id, (string) $this->admin->id);
        $this->go($revoke);
        $this->assertSomeoneWaitsOnALock();
        $this->go($close);
        $this->assertSame('OK', $this->lineOf($close));
        $this->assertSame('OK', $this->lineOf($revoke));

        [$second, $secondPerson] = $this->coordinatorAccount('211111110');
        $this->assign($second, $this->testBranch);
        $hold = $this->spawn('hold-revoke-role', (string) $secondPerson->id, (string) $this->admin->id);
        $refused = $this->spawn('followup-close', (string) $second->id, $this->followup('SECOND')->uuid, 'INFORMED_BY_PHONE');
        $this->go($hold, 1500);
        $this->go($refused);
        $this->assertSomeoneWaitsOnALock();
        $this->assertSame('OK', $this->lineOf($hold));
        $this->assertSame('DENIED FOLLOWUP_ACTOR_NOT_ALLOWED', $this->lineOf($refused));
        $this->assertSame(['CLOSED_MANUALLY', 'OPEN'], [$this->followup()->outcome->value, $this->followup('SECOND')->status->value]);
        $this->assertSame(1, $this->events('FOLLOWUP_CLOSED'));
    }

    public function test_clan_and_group_level_assignments_take_the_clan_lock_too(): void
    {
        [, $clanPerson] = $this->coordinatorAccount('211111110');
        [, $groupPerson] = $this->coordinatorAccount('222222220');
        $contact = $this->paused('followup-contact-pause', (string) $this->primary->id, $this->followup()->uuid, 'OTHER');

        $assigns = [];
        foreach (['CLAN:'.$this->testClan->id => $clanPerson, 'GROUP:'.$this->testGroup->id => $groupPerson] as $target => $person) {
            $assigns[] = $assign = $this->spawn('assign-scope', (string) $person->id, $target, (string) $this->admin->id);
            $this->go($assign);
            $this->assertSomeoneWaitsOnARowOf('clans');
        }
        $this->assertSame(0, CoordinatorScopeAssignment::query()->active()->whereIn('scope_type', ['CLAN', 'BRANCH_GROUP'])->count(), 'neither committed during the action');
        $this->go($contact);
        $this->assertSame('OK', $this->lineOf($contact));
        foreach ($assigns as $assign) {
            $this->assertSame('OK', $this->lineOf($assign));
        }
        $this->assertSame(2, CoordinatorScopeAssignment::query()->active()->whereIn('scope_type', ['CLAN', 'BRANCH_GROUP'])->count());
    }

    public function test_two_concurrent_assignments_in_one_clan_are_serialized_and_a_duplicate_is_refused(): void
    {
        [$firstUser, $firstPerson] = $this->coordinatorAccount('211111110');
        [, $secondPerson] = $this->coordinatorAccount('222222220');

        $hold = $this->spawn('hold-assign', (string) $firstPerson->id, 'BRANCH:'.$this->testBranch->id, (string) $this->admin->id);
        $other = $this->spawn('assign-scope', (string) $secondPerson->id, 'GROUP:'.$this->testGroup->id, (string) $this->admin->id);
        $duplicate = $this->spawn('assign-scope', (string) $firstPerson->id, 'BRANCH:'.$this->testBranch->id, (string) $this->admin->id);
        $this->go($hold, 1500);
        $this->go($other);
        $this->go($duplicate);
        $this->assertSomeoneWaitsOnALock();

        $this->assertSame('OK', $this->lineOf($hold));
        $this->assertSame('OK', $this->lineOf($other));
        $this->assertSame('REFUSED SCOPE_EXISTS', $this->lineOf($duplicate));
        $this->assertSame(1, CoordinatorScopeAssignment::query()->active()->where('user_id', $firstUser->id)->count());
    }

    public function test_three_sessions_assignment_action_and_acknowledgement_never_deadlock_in_either_order(): void
    {
        $this->primaryThroughTheGroup();
        [$newcomer, $newcomerPerson] = $this->coordinatorAccount('211111110');

        // Order 1 — the action holds the clan row and the recipient: both others wait on it.
        $close = $this->paused('followup-close-pause', (string) $this->primary->id, $this->followup()->uuid, 'FAMILY_UNREACHABLE');
        $assign = $this->spawn('assign-scope', (string) $newcomerPerson->id, 'BRANCH:'.$this->testBranch->id, (string) $this->admin->id);
        $acknowledgement = $this->spawn('family-acknowledge', (string) $this->head->id, $this->message->uuid);
        $this->go($assign);
        $this->go($acknowledgement);
        $waiting = 0;
        for ($i = 0; $i < 40 && $waiting < 2; $i++) {
            usleep(100_000);
            $waiting = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock'")->n;
        }
        $this->assertSame(2, $waiting, 'the assignment and the acknowledgement both wait');
        $this->go($close);
        $this->assertSame('OK', $this->lineOf($close));
        $this->assertSame('OK', $this->lineOf($assign));
        $this->assertSame('OK recorded', $this->lineOf($acknowledgement));
        $this->assertSame(['CLOSED_MANUALLY', 'SATISFIED'], [$this->followup()->outcome->value, $this->followup('SECOND')->outcome->value]);
        $this->assertTrue(app(FollowupOwnership::class)->isPrimary($newcomer->fresh(), $this->recipient->family));
    }

    public function test_three_sessions_an_action_waiting_for_an_assignment_never_holds_up_the_family(): void
    {
        $this->primaryThroughTheGroup();
        [, $newcomerPerson] = $this->coordinatorAccount('211111110');

        // Order 2 — the assignment holds the clan row: the action waits on it WITHOUT the
        // recipient, so the family's acknowledgement goes through at once.
        $hold = $this->spawn('hold-assign', (string) $newcomerPerson->id, 'BRANCH:'.$this->testBranch->id, (string) $this->admin->id);
        $close = $this->spawn('followup-close', (string) $this->primary->id, $this->followup()->uuid, 'FAMILY_UNREACHABLE');
        $this->go($hold, 4000);
        $this->go($close);
        $this->assertSomeoneWaitsOnALock();
        $acknowledgement = $this->spawn('family-acknowledge', (string) $this->head->id, $this->message->uuid);
        $this->go($acknowledgement);
        $this->assertSame('OK recorded', $this->lineOf($acknowledgement), 'not blocked by the waiting action');

        $this->assertSame('OK', $this->lineOf($hold));
        $this->assertStringStartsWith('DENIED ', $this->lineOf($close), 'refused after the assignment: never a stale owner');
        $this->assertSame(['SATISFIED', 'SATISFIED'], [$this->followup()->outcome->value, $this->followup('SECOND')->outcome->value]);
        $this->assertSame(0, NotificationRecipientEvent::query()->where('recipient_id', $this->recipient->id)->where('event_type', 'FOLLOWUP_CLOSED')->whereNotNull('actor_user_id')->count());
    }
}
