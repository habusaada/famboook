<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\RequestPublicationAction;
use App\Enums\NotificationStatus;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\Notifications\NotificationExpirySweeper;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.6b: the expiry sweep — PUBLISHED → ARCHIVED at expires_at
 * with a SYSTEM EXPIRED_ARCHIVED event; recipients, views and
 * acknowledgements untouched; idempotent; bounded; whatever the feature
 * mode; scheduled every minute. Driver-agnostic (also run on PostgreSQL,
 * where chk_nm_archived / chk_nm_archive_on_expiry apply). Synthetic data
 * only.
 */
class NotificationExpirySweepTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
        $this->staff = $this->staffSender();
    }

    private function published(?int $expiresInMinutes = 120): NotificationMessage
    {
        $draft = $this->draft($this->staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [Family::factory()->create()->id]],
            $expiresInMinutes === null ? [] : ['expires_at' => now()->addMinutes($expiresInMinutes)->toIso8601String()]);
        app(RequestPublicationAction::class)->handle($this->staff, $draft);
        app(NotificationPublisher::class)->publish($draft->id);

        return $draft->fresh();
    }

    private function sweep(int $limit = 100): array
    {
        return app(NotificationExpirySweeper::class)->run($limit);
    }

    public function test_a_published_message_is_archived_exactly_at_its_expiry_and_only_once(): void
    {
        $message = $this->published(120);
        $forever = $this->published(null);
        $recipientsBefore = DB::table('notification_recipients')->where('message_id', $message->id)->get()->toJson();

        $this->travel(120 * 60 - 1)->seconds();
        $this->assertSame(0, $this->sweep()['archived']);
        $this->travel(1)->seconds();
        $this->assertSame(['scanned' => 1, 'archived' => 1, 'skipped' => 0, 'errors' => 0], $this->sweep());

        $stored = $message->fresh();
        $this->assertSame(NotificationStatus::ARCHIVED, $stored->status);
        $this->assertSame('2026-10-19 12:00:00', $stored->archived_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($stored->published_at);
        $event = NotificationRecipientEvent::query()->where('message_id', $message->id)->where('event_type', 'EXPIRED_ARCHIVED')->sole();
        $this->assertSame(['SYSTEM', null, null], [$event->actor_side->value, $event->actor_user_id, $event->recipient_id]);
        $this->assertSame($recipientsBefore, DB::table('notification_recipients')->where('message_id', $message->id)->get()->toJson());

        // Idempotent; a message without expiry is never archived.
        $this->assertSame(['scanned' => 0, 'archived' => 0, 'skipped' => 0, 'errors' => 0], $this->sweep());
        $this->travel(365)->days();
        $this->sweep();
        $this->assertSame(NotificationStatus::PUBLISHED, $forever->fresh()->status);
        $this->assertSame(1, NotificationRecipientEvent::query()->where('event_type', 'EXPIRED_ARCHIVED')->count());
    }

    public function test_it_runs_whatever_the_feature_mode_and_only_on_published_messages(): void
    {
        $message = $this->published(90);
        $queued = $this->draft($this->staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [Family::factory()->create()->id]],
            ['expires_at' => now()->addMinutes(90)->toIso8601String()]);
        app(RequestPublicationAction::class)->handle($this->staff, $queued);
        $this->travel(2)->hours();
        config(['notifications.mode' => 'OFF']);

        $this->assertSame(1, $this->sweep()['archived']);
        $this->assertSame(NotificationStatus::ARCHIVED, $message->fresh()->status);
        $this->assertSame(NotificationStatus::QUEUED, $queued->fresh()->status, 'never published: never archived');
    }

    public function test_the_batch_limit_bounds_one_run(): void
    {
        foreach (range(1, 3) as $i) {
            $this->published(90);
        }
        $this->travel(2)->hours();

        $this->assertSame(2, $this->sweep(limit: 2)['archived']);
        $this->assertSame(1, $this->sweep(limit: 2)['archived']);
        $this->assertSame(0, NotificationMessage::query()->where('status', 'PUBLISHED')->count());
    }

    public function test_the_command_prints_counters_and_is_scheduled_every_minute(): void
    {
        $this->published(90);
        $this->travel(2)->hours();

        $this->artisan('famboook:notifications-expire')
            ->expectsOutput('scanned: 1')->expectsOutput('archived: 1')->expectsOutput('skipped: 0')->expectsOutput('errors: 0')
            ->assertExitCode(0);
        foreach (['0', '1001', 'x'] as $limit) {
            $this->artisan('famboook:notifications-expire', ['--limit' => $limit])->assertExitCode(2);
        }

        $events = array_values(array_filter(app(Schedule::class)->events(),
            fn (Event $event) => str_contains((string) $event->command, 'famboook:notifications-expire')));
        $this->assertCount(1, $events);
        $this->assertSame(['* * * * *', true, true], [$events[0]->expression, $events[0]->withoutOverlapping, $events[0]->onOneServer]);
    }
}
