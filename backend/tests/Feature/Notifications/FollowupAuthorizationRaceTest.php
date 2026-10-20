<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\RequestPublicationAction;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Family;
use App\Models\NotificationFollowup;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientEvent;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\Notifications\FollowupOwnership;
use App\Support\Notifications\NotificationFollowupEngine;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\NotificationActionFixtures;
use Tests\Support\Notifications\NotificationActorProcesses;
use Tests\TestCase;

/**
 * FB-NOTIFY-01E.3-F3.1d on PostgreSQL: ownership- and authorization-changing
 * writers OTHER than assignments, against contact recording and manual
 * closure — family placement, branch activation and regrouping, the
 * coordinator's link, identity and life status, and a Staff role change —
 * each in BOTH orders, in separate processes (publication-actor.php):
 *
 *   action first   the writer WAITS on the row the action holds FOR SHARE
 *                  (proven by its tuple lock in pg_locks), the action
 *                  completes, then the writer commits;
 *   writer first   the action WAITS on the row the writer holds, then sees
 *                  the change and is refused — never a stale completion.
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database). Fixtures
 * are committed (DatabaseTruncation). Synthetic data only.
 */
class FollowupAuthorizationRaceTest extends TestCase
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

    private BranchGroup $groupOne;

    private BranchGroup $groupTwo;

    private Branch $branchOne;

    private Branch $branchTwo;

    private Family $family;

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
        $this->groupOne = $this->group($this->testClan);
        $this->groupTwo = $this->group($this->testClan);
        $this->branchOne = $this->branch($this->testClan, $this->groupOne);
        $this->branchTwo = $this->branch($this->testClan, $this->groupOne);

        $household = $this->activatedHead('123456789');
        $this->head = $household['user'];
        $this->family = $household['family'];
        $this->family->forceFill(['clan_id' => $this->testClan->id, 'branch_id' => $this->branchOne->id])->save();
        $coordinator = $this->activatedHead('111111110', ['FAMILY_USER', 'COORDINATOR']);
        [$this->primary, $this->primaryPerson] = [$coordinator['user'], $coordinator['person']];
        $this->primaryAssignment = $this->assign($this->primary, $this->branchOne);

        $staff = $this->staffSender();
        $draft = $this->draft($staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->family->id]], ['level' => 'IMPORTANT']);
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

    /** A session waits for a ROW of $table (the first waiter holds a tuple lock on it). */
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

    private function codeFor(string $action): string
    {
        return $action === 'followup-contact' ? 'CALLED_NO_ANSWER' : 'INFORMED_BY_PHONE';
    }

    /**
     * Action first: paused with every lock held; the writer waits on $table;
     * the action completes; the writer commits after it.
     *
     * @param  list<string>  $mutation
     */
    private function actionFirst(string $action, User $actor, array $mutation, string $table): void
    {
        $running = $this->spawn("{$action}-pause", (string) $actor->id, $this->followup()->uuid, $this->codeFor($action));
        $this->go($running);
        $this->assertSame('PAUSED', $this->lineOf($running));
        $writer = $this->spawn('mutate', ...$mutation);
        $this->go($writer);
        $this->assertSomeoneWaitsOnARowOf($table);
        $this->go($running);

        $this->assertSame('OK', $this->lineOf($running), 'authorized before the change: completes');
        $this->assertSame('OK', $this->lineOf($writer), 'the writer commits after the action');
        $after = $this->spawn($action, (string) $actor->id, $this->followup('SECOND')->uuid, $this->codeFor($action));
        $this->go($after);
        $this->assertSame('DENIED FOLLOWUP_ACTOR_NOT_ALLOWED', $this->lineOf($after), 'the change is effective at once');
    }

    /**
     * Writer first: held; the action waits on $table, then is refused and
     * writes nothing.
     *
     * @param  list<string>  $mutation
     */
    private function writerFirst(string $action, User $actor, array $mutation, string $table): void
    {
        $events = NotificationRecipientEvent::query()->count();
        $writer = $this->spawn('hold-mutate', ...$mutation);
        $refused = $this->spawn($action, (string) $actor->id, $this->followup()->uuid, $this->codeFor($action));
        $this->go($writer, 1500);
        $this->go($refused);
        $this->assertSomeoneWaitsOnARowOf($table);

        $this->assertSame('OK', $this->lineOf($writer));
        $this->assertSame('DENIED FOLLOWUP_ACTOR_NOT_ALLOWED', $this->lineOf($refused), 'never under the replaced authorization');
        $this->assertSame($events, NotificationRecipientEvent::query()->count());
        $this->assertSame('OPEN', $this->followup()->status->value);
    }

    /** The second coordinator covering branch two (the new primary after a move). */
    private function branchTwoCoordinator(): User
    {
        $other = $this->activatedHead('211111110', ['FAMILY_USER', 'COORDINATOR'])['user'];
        $this->assign($other, $this->branchTwo);

        return $other;
    }

    private function primaryThroughGroupOne(): void
    {
        $this->primaryAssignment->forceFill(['revoked_at' => now(), 'revoked_by' => $this->admin->id, 'revoke_reason' => 'ADMINISTRATIVE'])->save();
        $this->assign($this->primary, $this->groupOne);
    }

    private function primaryLink(): UserPersonLink
    {
        return UserPersonLink::query()->where('user_id', $this->primary->id)->current()->sole();
    }

    // -------------------------------------------------------- family placement

    public function test_family_placement_vs_contact_action_first(): void
    {
        $other = $this->branchTwoCoordinator();
        $this->actionFirst('followup-contact', $this->primary, ['update-family', (string) $this->family->id, $this->branchTwo->code], 'families');
        $this->assertTrue(app(FollowupOwnership::class)->isPrimary($other->fresh(), $this->family->fresh()));
    }

    public function test_family_placement_vs_contact_writer_first(): void
    {
        $this->branchTwoCoordinator();
        $this->writerFirst('followup-contact', $this->primary, ['update-family', (string) $this->family->id, $this->branchTwo->code], 'families');
    }

    public function test_family_placement_vs_manual_closure_action_first(): void
    {
        $this->branchTwoCoordinator();
        $this->actionFirst('followup-close', $this->primary, ['update-family', (string) $this->family->id, $this->branchTwo->code], 'families');
        $this->assertSame([$this->primary->id, 'CLOSED_MANUALLY'], [$this->followup()->closed_by_user_id, $this->followup()->outcome->value]);
    }

    public function test_family_placement_vs_manual_closure_writer_first(): void
    {
        $this->branchTwoCoordinator();
        $this->writerFirst('followup-close', $this->primary, ['update-family', (string) $this->family->id, $this->branchTwo->code], 'families');
    }

    // ----------------------------------------------------- structure changes

    public function test_branch_deactivation_vs_contact_action_first(): void
    {
        $this->actionFirst('followup-contact', $this->primary, ['update-branch', (string) $this->branchOne->id, 'inactive'], 'branches');
    }

    public function test_branch_deactivation_vs_contact_writer_first(): void
    {
        $this->writerFirst('followup-contact', $this->primary, ['update-branch', (string) $this->branchOne->id, 'inactive'], 'branches');
    }

    public function test_branch_regrouping_vs_manual_closure_action_first(): void
    {
        $this->primaryThroughGroupOne();
        $this->actionFirst('followup-close', $this->primary, ['update-branch', (string) $this->branchOne->id, 'group:'.$this->groupTwo->id], 'branches');
    }

    public function test_branch_regrouping_vs_manual_closure_writer_first(): void
    {
        $this->primaryThroughGroupOne();
        $this->writerFirst('followup-close', $this->primary, ['update-branch', (string) $this->branchOne->id, 'group:'.$this->groupTwo->id], 'branches');
    }

    // ------------------------------------------------- coordinator eligibility

    public function test_link_suspension_vs_contact_action_first(): void
    {
        $this->actionFirst('followup-contact', $this->primary, ['suspend-link', (string) $this->primaryLink()->id, (string) $this->admin->id], 'user_person_links');
    }

    public function test_link_suspension_vs_contact_writer_first(): void
    {
        $this->writerFirst('followup-contact', $this->primary, ['suspend-link', (string) $this->primaryLink()->id, (string) $this->admin->id], 'user_person_links');
    }

    public function test_identity_suspension_vs_manual_closure_action_first(): void
    {
        $this->actionFirst('followup-close', $this->primary, ['suspend-identity', (string) $this->primaryPerson->id], 'persons');
    }

    public function test_identity_suspension_vs_manual_closure_writer_first(): void
    {
        $this->writerFirst('followup-close', $this->primary, ['suspend-identity', (string) $this->primaryPerson->id], 'persons');
    }

    public function test_an_eligibility_change_waits_for_an_already_running_action(): void
    {
        // The coordinator dies while their closure runs: the death waits, the closure completes.
        $this->actionFirst('followup-close', $this->primary, ['record-death', (string) $this->primaryPerson->id], 'persons');
    }

    public function test_an_action_waits_for_a_running_eligibility_change_and_is_refused(): void
    {
        $this->writerFirst('followup-contact', $this->primary, ['record-death', (string) $this->primaryPerson->id], 'persons');
    }

    // ------------------------------------------------------------- Staff role

    public function test_staff_role_revocation_vs_manual_closure_action_first(): void
    {
        $this->actionFirst('followup-close', $this->administrator, ['staff-role', (string) $this->administrator->id, 'DATA_ENTRY', (string) $this->admin->id], 'users');
        $this->assertTrue($this->administrator->fresh()->hasRole('DATA_ENTRY'));
    }

    public function test_staff_role_revocation_vs_manual_closure_writer_first(): void
    {
        $this->writerFirst('followup-close', $this->administrator, ['staff-role', (string) $this->administrator->id, 'DATA_ENTRY', (string) $this->admin->id], 'users');
    }

    // ------------------------------------------------------------ three sessions

    public function test_three_sessions_placement_change_action_and_acknowledgement_in_both_orders(): void
    {
        $other = $this->branchTwoCoordinator();

        // Order 1 — the action holds the family row and the recipient: the
        // placement change and the acknowledgement both wait; all complete.
        $running = $this->spawn('followup-close-pause', (string) $this->primary->id, $this->followup()->uuid, 'FAMILY_UNREACHABLE');
        $this->go($running);
        $this->assertSame('PAUSED', $this->lineOf($running));
        $move = $this->spawn('mutate', 'update-family', (string) $this->family->id, $this->branchTwo->code);
        $acknowledgement = $this->spawn('family-acknowledge', (string) $this->head->id, $this->message->uuid);
        $this->go($move);
        $this->go($acknowledgement);
        $this->assertSomeoneWaitsOnARowOf('families');
        $this->assertSomeoneWaitsOnARowOf('notification_recipients');
        $this->go($running);
        $this->assertSame('OK', $this->lineOf($running));
        $this->assertSame('OK', $this->lineOf($move));
        $this->assertSame('OK recorded', $this->lineOf($acknowledgement));
        $this->assertSame(['CLOSED_MANUALLY', 'SATISFIED'], [$this->followup()->outcome->value, $this->followup('SECOND')->outcome->value]);
        $this->assertTrue(app(FollowupOwnership::class)->isPrimary($other->fresh(), $this->family->fresh()));
    }

    public function test_three_sessions_an_action_waiting_for_a_placement_change_never_holds_up_the_family(): void
    {
        $this->branchTwoCoordinator();

        // Order 2 — the placement change holds the family row: the action waits on
        // it WITHOUT the recipient, so the family's acknowledgement goes through.
        $move = $this->spawn('hold-mutate', 'update-family', (string) $this->family->id, $this->branchTwo->code);
        $close = $this->spawn('followup-close', (string) $this->primary->id, $this->followup()->uuid, 'FAMILY_UNREACHABLE');
        $this->go($move, 4000);
        $this->go($close);
        $this->assertSomeoneWaitsOnARowOf('families');
        $acknowledgement = $this->spawn('family-acknowledge', (string) $this->head->id, $this->message->uuid);
        $this->go($acknowledgement);
        $this->assertSame('OK recorded', $this->lineOf($acknowledgement), 'not blocked by the waiting action');

        $this->assertSame('OK', $this->lineOf($move));
        $this->assertStringStartsWith('DENIED ', $this->lineOf($close));
        $this->assertSame(['SATISFIED', 'SATISFIED'], [$this->followup()->outcome->value, $this->followup('SECOND')->outcome->value]);
    }
}
