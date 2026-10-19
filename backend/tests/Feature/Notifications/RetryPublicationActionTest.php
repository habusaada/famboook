<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\RequestPublicationAction;
use App\Actions\Notifications\RetryPublicationAction;
use App\Enums\FamilyStatus;
use App\Enums\NotificationEventType;
use App\Enums\NotificationFailureCode;
use App\Enums\NotificationStatus;
use App\Exceptions\NotificationAudienceException;
use App\Exceptions\NotificationPublicationException;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\Notifications\NotificationOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.4: RetryPublicationAction — PUBLISH_FAILED → QUEUED with
 * REQUEUED, only for a retryable failure, within the attempt limit, with the
 * same readiness, permission, expiry and audience checks as a first request,
 * through the same after-commit hand-off. Synthetic data only.
 */
class RetryPublicationActionTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private User $staff;

    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
        $this->staff = $this->staffSender();
        $this->family = Family::factory()->create();
    }

    private function failed(NotificationFailureCode|string $code = NotificationFailureCode::PUBLICATION_ERROR, int $attempts = 1, array $input = []): NotificationMessage
    {
        $draft = $this->draft($this->staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->family->id]], $input);
        app(RequestPublicationAction::class)->handle($this->staff, $draft);

        return $this->failPublication($draft->fresh(), $code, $attempts);
    }

    private function retry(User $user, NotificationMessage $message): NotificationOutcome
    {
        return app(RetryPublicationAction::class)->handle($user, $message);
    }

    private function assertRefused(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected {$reason}.");
        } catch (NotificationPublicationException|NotificationAudienceException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    private function assertStillFailed(NotificationMessage $message): void
    {
        $this->assertSame(NotificationStatus::PUBLISH_FAILED, $message->fresh()->status);
        $this->assertSame(0, NotificationRecipientEvent::query()->where('message_id', $message->id)
            ->where('event_type', NotificationEventType::REQUEUED->value)->count());
    }

    public function test_a_retryable_failure_is_requeued_with_its_history_kept(): void
    {
        $message = $this->failed(NotificationFailureCode::STALE_PUBLISHING, attempts: 2);
        $this->dispatcher->dispatched = [];

        $this->retry($this->staff, $message);

        $stored = $message->fresh();
        $this->assertSame(NotificationStatus::QUEUED, $stored->status);
        $this->assertSame('STALE_PUBLISHING', $stored->publish_failure_code);
        $this->assertNotNull($stored->publish_failed_at);
        $this->assertSame(2, $stored->publish_attempts);
        $event = NotificationRecipientEvent::query()->where('message_id', $message->id)
            ->where('event_type', NotificationEventType::REQUEUED->value)->sole();
        $this->assertSame(['STALE_PUBLISHING', ['attempts' => 2]], [$event->reason_code, $event->metadata]);
        $this->assertSame([(int) $message->id], $this->dispatcher->ids());
        $this->assertSame('QUEUED', $this->dispatcher->dispatched[0]['status']);
        $this->assertSame(0, DB::table('notification_recipients')->count());
    }

    public function test_at_most_three_publication_attempts_in_total(): void
    {
        $this->retry($this->staff, $this->failed(attempts: 2));

        $exhausted = $this->failed(attempts: 3);
        $this->assertRefused(NotificationPublicationException::RETRY_LIMIT_REACHED, fn () => $this->retry($this->staff, $exhausted));
        $this->assertStillFailed($exhausted);

        config(['notifications.max_publish_attempts' => 'many']);
        $this->assertRefused(NotificationPublicationException::PUBLICATION_UNAVAILABLE, fn () => $this->retry($this->staff, $this->failed()));
    }

    public function test_permanent_and_unknown_failures_are_never_retried(): void
    {
        foreach ([...array_filter(NotificationFailureCode::cases(), fn ($code) => $code->isPermanent()), 'SOMETHING_ELSE'] as $code) {
            $message = $this->failed($code);
            $this->assertRefused(NotificationPublicationException::RETRY_NOT_ALLOWED, fn () => $this->retry($this->staff, $message));
            $this->assertStillFailed($message);
        }
        foreach ([NotificationFailureCode::AUDIENCE_EMPTY, NotificationFailureCode::PUBLICATION_ERROR] as $code) {
            $this->assertSame(NotificationStatus::QUEUED, $this->retry($this->staff, $this->failed($code))->message->status);
        }
    }

    public function test_only_a_failed_message_is_retried(): void
    {
        $draft = $this->draft($this->staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->family->id]]);
        $this->assertRefused(NotificationPublicationException::INVALID_TRANSITION, fn () => $this->retry($this->staff, $draft));

        $queued = app(RequestPublicationAction::class)->handle($this->staff,
            $this->draft($this->staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->family->id]]))->message;
        $this->assertTrue($this->retry($this->staff, $queued)->replayed);

        $this->assertRefused(NotificationPublicationException::NOT_FOUND, fn () => $this->retry($this->staffSender(), $this->failed()));
    }

    public function test_readiness_permission_expiry_and_audience_are_rechecked(): void
    {
        $message = $this->failed();

        config(['notifications.mode' => 'OFF']);
        $this->assertRefused(NotificationPublicationException::PUBLICATION_UNAVAILABLE, fn () => $this->retry($this->staff, $message));
        config(['notifications.mode' => 'ON']);

        $this->staff->revokePermissionTo('announcement.send');
        $this->assertRefused(NotificationPublicationException::ACTOR_NOT_ALLOWED, fn () => $this->retry($this->staff->fresh(), $message));
        $this->staff->givePermissionTo('announcement.send');

        $this->family->forceFill(['status' => FamilyStatus::INACTIVE->value])->save();
        $this->assertRefused(NotificationAudienceException::FAMILY_NOT_ELIGIBLE, fn () => $this->retry($this->staff->fresh(), $message));
        $this->assertStillFailed($message);
    }

    public function test_an_expiry_closer_than_an_hour_blocks_the_retry(): void
    {
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
        $message = $this->failed(input: ['expires_at' => now()->addMinutes(120)->toIso8601String()]);
        $this->travel(61)->minutes();
        $this->freshHeartbeat();

        $this->assertRefused(NotificationPublicationException::EXPIRY_TOO_SOON, fn () => $this->retry($this->staff, $message));
        $this->assertStillFailed($message);
    }
}
