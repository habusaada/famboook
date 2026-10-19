<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\RequestPublicationAction;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\NotificationActionFixtures;
use Tests\Support\Notifications\NotificationActorProcesses;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.6b on PostgreSQL: concurrent views and acknowledgements by
 * the household head (two sessions, tests/Support/Notifications/
 * publication-actor.php) serialize on the recipient row — first write wins,
 * one VIEWED and at most one ACKNOWLEDGED event, an acknowledgement never
 * before its view.
 *
 * PostgreSQL ONLY (phpunit.pgsql.xml, a reachable *_test database). Fixtures
 * are committed (DatabaseTruncation) in the migration's AL_BREEM Clan.
 * Synthetic data only.
 */
class FamilyNotificationConcurrencyTest extends TestCase
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

    /** Runs two family actions together behind a held recipient row; returns their outcomes, sorted. */
    private function race(string $first, string $second): array
    {
        $gate = $this->spawn('hold-recipient', (string) $this->recipient->id);
        $a = $this->spawn($first, (string) $this->head->id, $this->message->uuid);
        $b = $this->spawn($second, (string) $this->head->id, $this->message->uuid);
        $this->go($a);
        $this->go($b);
        $this->go($gate, 800);

        $outcomes = [$this->lineOf($a), $this->lineOf($b)];
        $this->assertSame('OK', $this->lineOf($gate));

        return $outcomes;
    }

    private function events(string $type): int
    {
        return NotificationRecipientEvent::query()->where('recipient_id', $this->recipient->id)->where('event_type', $type)->count();
    }

    public function test_two_concurrent_views_record_one_view(): void
    {
        $outcomes = $this->race('family-view', 'family-view');
        sort($outcomes);

        $this->assertSame(['OK recorded', 'OK replayed'], $outcomes);
        $this->assertSame(1, $this->events('VIEWED'));
    }

    public function test_two_concurrent_acknowledgements_record_one_view_and_one_acknowledgement(): void
    {
        $outcomes = $this->race('family-acknowledge', 'family-acknowledge');
        sort($outcomes);

        $this->assertSame(['OK recorded', 'OK replayed'], $outcomes);
        $this->assertSame([1, 1], [$this->events('VIEWED'), $this->events('ACKNOWLEDGED')]);
    }

    public function test_a_view_racing_an_acknowledgement_keeps_one_view_before_the_acknowledgement(): void
    {
        [$view, $acknowledgement] = $this->race('family-view', 'family-acknowledge');

        // The acknowledgement always records; the view is a replay if the
        // acknowledgement (which includes the view) got the row first.
        $this->assertSame('OK recorded', $acknowledgement);
        $this->assertContains($view, ['OK recorded', 'OK replayed']);
        $this->assertSame([1, 1], [$this->events('VIEWED'), $this->events('ACKNOWLEDGED')]);
        $stored = $this->recipient->fresh();
        $this->assertNotNull($stored->acknowledged_at);
        $this->assertTrue($stored->acknowledged_at->greaterThanOrEqualTo($stored->viewed_at));
    }
}
