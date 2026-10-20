<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\RequestPublicationAction;
use App\Models\NotificationFollowup;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\NotificationActionFixtures;
use Tests\Support\Notifications\NotificationActorProcesses;
use Tests\TestCase;

/**
 * FB-NOTIFY-01E.3-F2 on PostgreSQL: the follow-up engine against concurrent
 * sessions (tests/Support/Notifications/publication-actor.php, each in its own
 * process): concurrent schedulers, opening racing an acknowledgement, expiry
 * racing a late acknowledgement, a message held by the expiry sweep, and an
 * interrupted run. Lock order: recipient FOR UPDATE (SKIP LOCKED for the
 * scheduler) → its follow-ups FOR UPDATE by id → inserts.
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database). Fixtures
 * are committed (DatabaseTruncation) in the migration's AL_BREEM Clan.
 * Synthetic data only.
 */
class NotificationFollowupConcurrencyTest extends TestCase
{
    use DatabaseTruncation, NotificationActionFixtures, NotificationActorProcesses;

    /** @var list<string> reference data created by migrations */
    protected $exceptTables = ['clans', 'relationship_types'];

    private User $head;

    private NotificationMessage $message;

    private NotificationRecipient $recipient;

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        $this->setUpNotificationActions();
        $staff = $this->staffSender();
        $activated = $this->activatedHead('123456789');
        $this->head = $activated['user'];
        $draft = $this->draft($staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$activated['family']->id]], ['level' => 'IMPORTANT']);
        app(RequestPublicationAction::class)->handle($staff, $draft);
        app(NotificationPublisher::class)->publish($draft->id);
        // Published 80 hours ago: FIRST (24 h) and SECOND (72 h) are both due now.
        DB::table('notification_messages')->where('id', $draft->id)->update(['published_at' => now()->subHours(80)]);
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

    /** @return array<string, int|bool> the counters of an actor's "OK {json}" line */
    private function counters(string $line): array
    {
        $this->assertStringStartsWith('OK {', $line, $line);

        return json_decode(substr($line, 3), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> stage => status/outcome */
    private function stages(): array
    {
        return NotificationFollowup::query()->where('recipient_id', $this->recipient->id)->orderBy('stage')->get()
            ->mapWithKeys(fn (NotificationFollowup $f) => [$f->stage->value => $f->status->value.($f->outcome ? '/'.$f->outcome->value : '')])
            ->all();
    }

    private function events(string $type): int
    {
        return NotificationRecipientEvent::query()->where('recipient_id', $this->recipient->id)->where('event_type', $type)->count();
    }

    /** Expired one minute ago (still after published_at: chk_nm_expiry). */
    private function expireMessage(): void
    {
        DB::table('notification_messages')->where('id', $this->message->id)->update(['expires_at' => now()->subMinute()]);
    }

    public function test_two_concurrent_schedulers_open_each_stage_exactly_once(): void
    {
        $a = $this->spawn('followups', '100');
        $b = $this->spawn('followups', '100');
        $this->go($a);
        $this->go($b);
        [$first, $second] = [$this->counters($this->lineOf($a)), $this->counters($this->lineOf($b))];

        $this->assertSame(2, $first['opened'] + $second['opened']);
        $this->assertSame(0, $first['errors'] + $second['errors']);
        $this->assertSame(['FIRST' => 'OPEN', 'SECOND' => 'OPEN'], $this->stages());
        $this->assertSame(2, $this->events('FOLLOWUP_OPENED'));
    }

    public function test_a_scheduler_never_waits_for_another_and_skips_the_held_recipient(): void
    {
        $holder = $this->spawn('followups-pause', '100');
        $this->go($holder);
        $this->assertSame('PAUSED', $this->lineOf($holder));

        $other = $this->spawn('followups', '100');
        $this->go($other);
        $skipped = $this->counters($this->lineOf($other));
        $this->assertSame([1, 0, 1, 0], [$skipped['opening_scanned'], $skipped['opened'], $skipped['opening_skipped'], $skipped['errors']]);

        $this->go($holder);
        $this->assertSame(2, $this->counters($this->lineOf($holder))['opened']);
        $this->assertSame(['FIRST' => 'OPEN', 'SECOND' => 'OPEN'], $this->stages());
        $this->assertSame(2, $this->events('FOLLOWUP_OPENED'));
    }

    public function test_an_acknowledgement_waits_for_an_opening_and_then_satisfies_both_stages(): void
    {
        $opening = $this->spawn('followups-pause', '100');
        $this->go($opening);
        $this->assertSame('PAUSED', $this->lineOf($opening));

        // The acknowledgement blocks on the recipient row the opening holds.
        $acknowledgement = $this->spawn('family-acknowledge', (string) $this->head->id, $this->message->uuid);
        $this->go($acknowledgement);
        usleep(300_000);
        $this->go($opening);

        $this->assertSame(2, $this->counters($this->lineOf($opening))['opened']);
        $this->assertSame('OK recorded', $this->lineOf($acknowledgement));
        $this->assertSame(['FIRST' => 'CLOSED/SATISFIED', 'SECOND' => 'CLOSED/SATISFIED'], $this->stages());
        $this->assertSame([2, 2, 1], [$this->events('FOLLOWUP_OPENED'), $this->events('FOLLOWUP_CLOSED'), $this->events('ACKNOWLEDGED')]);
    }

    public function test_an_opening_racing_an_in_flight_acknowledgement_skips_and_never_opens_afterwards(): void
    {
        // A family action holds the recipient row: the scheduler skips at once.
        $family = $this->spawn('hold-recipient', (string) $this->recipient->id);
        $scheduler = $this->spawn('followups', '100');
        $this->go($family, 800);
        $this->go($scheduler);
        $this->assertSame(1, $this->counters($this->lineOf($scheduler))['opening_skipped']);
        $this->assertSame('OK', $this->lineOf($family));

        $acknowledgement = $this->spawn('family-acknowledge', (string) $this->head->id, $this->message->uuid);
        $this->go($acknowledgement);
        $this->assertSame('OK recorded', $this->lineOf($acknowledgement));

        $after = $this->spawn('followups', '100');
        $this->go($after);
        $this->assertSame([0, 0], [$this->counters($this->lineOf($after))['opening_scanned'], NotificationFollowup::query()->count()]);
        $this->assertSame(0, $this->events('FOLLOWUP_OPENED'));
    }

    public function test_expiry_racing_a_late_acknowledgement_keeps_one_expired_closure(): void
    {
        $open = $this->spawn('followups', '100');
        $this->go($open);
        $this->assertSame(2, $this->counters($this->lineOf($open))['opened']);
        $this->expireMessage();

        $expiry = $this->spawn('followups-pause', '100');
        $this->go($expiry);
        $this->assertSame('PAUSED', $this->lineOf($expiry));
        $acknowledgement = $this->spawn('family-acknowledge', (string) $this->head->id, $this->message->uuid);
        $this->go($acknowledgement);
        usleep(300_000);
        $this->go($expiry);

        $counters = $this->counters($this->lineOf($expiry));
        $this->assertSame([1, 2, 0], [$counters['expiry_scanned'], $counters['expired'], $counters['opened']]);
        $this->assertSame('OK recorded', $this->lineOf($acknowledgement));
        $this->assertSame(['FIRST' => 'CLOSED/EXPIRED', 'SECOND' => 'CLOSED/EXPIRED'], $this->stages());
        $this->assertTrue($this->recipient->fresh()->acknowledged_late);
        $this->assertSame([2, 1, 0], [$this->events('FOLLOWUP_CLOSED'), $this->events('ACKNOWLEDGED_LATE'), $this->events('ACKNOWLEDGED')]);
    }

    public function test_a_late_acknowledgement_first_leaves_expiry_to_the_scheduler(): void
    {
        $open = $this->spawn('followups', '100');
        $this->go($open);
        $this->counters($this->lineOf($open));
        $this->expireMessage();

        $acknowledgement = $this->spawn('family-acknowledge', (string) $this->head->id, $this->message->uuid);
        $this->go($acknowledgement);
        $this->assertSame('OK recorded', $this->lineOf($acknowledgement));
        $this->assertSame(['FIRST' => 'OPEN', 'SECOND' => 'OPEN'], $this->stages(), 'a late acknowledgement satisfies nothing');

        $expiry = $this->spawn('followups', '100');
        $this->go($expiry);
        $this->assertSame(2, $this->counters($this->lineOf($expiry))['expired']);
        $this->assertSame(['FIRST' => 'CLOSED/EXPIRED', 'SECOND' => 'CLOSED/EXPIRED'], $this->stages());
    }

    public function test_a_message_held_by_the_expiry_sweep_only_delays_the_event_insert(): void
    {
        // The sweep / worker lock the MESSAGE row; the engine never does, but its
        // event insert needs KEY SHARE on it: it waits (< lock_timeout), no deadlock.
        $sweep = $this->spawn('hold', (string) $this->message->id);
        $scheduler = $this->spawn('followups', '100');
        $this->go($sweep, 800);
        $this->go($scheduler);

        $this->assertSame('OK', $this->lineOf($sweep));
        $counters = $this->counters($this->lineOf($scheduler));
        $this->assertSame([2, 0], [$counters['opened'], $counters['errors']]);
        $this->assertSame(2, $this->events('FOLLOWUP_OPENED'));
    }

    public function test_an_interrupted_run_leaves_nothing_behind_and_the_next_run_opens_once(): void
    {
        $crashing = $this->spawn('followups-pause', '100');
        $this->go($crashing);
        $this->assertSame('PAUSED', $this->lineOf($crashing));
        // The worker process dies mid-transaction: PostgreSQL rolls it back.
        $this->stopActors();

        $opened = 0;
        for ($attempt = 0; $attempt < 20 && $opened === 0; $attempt++) {
            $next = $this->spawn('followups', '100');
            $this->go($next);
            $opened = $this->counters($this->lineOf($next))['opened'];
            if ($opened === 0) {
                usleep(250_000); // the server notices the lost connection
            }
        }

        $this->assertSame(2, $opened);
        $this->assertSame(['FIRST' => 'OPEN', 'SECOND' => 'OPEN'], $this->stages());
        $this->assertSame(2, $this->events('FOLLOWUP_OPENED'));
    }
}
