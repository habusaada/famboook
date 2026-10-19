<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\RequestPublicationAction;
use App\Actions\Notifications\RetryPublicationAction;
use App\Enums\FamilyStatus;
use App\Enums\NotificationEventType;
use App\Enums\NotificationFailureCode;
use App\Enums\NotificationStatus;
use App\Enums\WorkflowActorSide;
use App\Exceptions\NotificationAudienceException;
use App\Exceptions\NotificationPublicationException;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\Notifications\NotificationEventRecorder;
use App\Support\Notifications\NotificationOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.5b: RetryPublicationAction is the sender's ONE manual
 * retry — PUBLISH_FAILED → QUEUED with a REQUEUED event attributed to the
 * sender — under NotificationRetryPolicy: never for a permanent failure, for
 * a transient one only after the 3 automatic attempts, for a manual-only one
 * at once, never twice, never past 4 worker claims; readiness, permission,
 * expiry and audience re-checked; the hand-off after commit. Synthetic data
 * only.
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

    private function failed(NotificationFailureCode|string $code = NotificationFailureCode::PUBLICATION_ERROR, int $attempts = 3, array $input = [], ?User $sender = null, ?array $audience = null): NotificationMessage
    {
        $sender ??= $this->staff;
        $draft = $this->draft($sender, $audience ?? ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->family->id]], $input);
        app(RequestPublicationAction::class)->handle($sender, $draft);

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

    /** @return list<array{0: string, 1: int|null}> the message's REQUEUED events: actor side and user */
    private function requeues(NotificationMessage $message): array
    {
        return NotificationRecipientEvent::query()->where('message_id', $message->id)
            ->where('event_type', NotificationEventType::REQUEUED->value)->orderBy('id')->get()
            ->map(fn ($e) => [$e->actor_side->value, $e->actor_user_id === null ? null : (int) $e->actor_user_id])->all();
    }

    private function assertStillFailed(NotificationMessage $message, int $requeues = 0): void
    {
        $this->assertSame(NotificationStatus::PUBLISH_FAILED, $message->fresh()->status);
        $this->assertCount($requeues, $this->requeues($message));
    }

    /** The reconciliation worker's automatic requeue (2b.5d), written row by row: a SYSTEM REQUEUED event. */
    private function automaticRequeue(NotificationMessage $message): void
    {
        DB::transaction(function () use ($message) {
            $message->forceFill(['status' => NotificationStatus::QUEUED, 'queued_at' => now()])->save();
            app(NotificationEventRecorder::class)->message($message, NotificationEventType::REQUEUED, WorkflowActorSide::SYSTEM,
                reasonCode: (string) $message->publish_failure_code, metadata: ['retry' => 'AUTOMATIC']);
        });
    }

    public function test_after_the_automatic_attempts_the_sender_retries_once_atomically(): void
    {
        $message = $this->failed(NotificationFailureCode::STALE_PUBLISHING, attempts: 3);
        $this->dispatcher->dispatched = [];

        $this->retry($this->staff, $message);

        $stored = $message->fresh();
        $this->assertSame(NotificationStatus::QUEUED, $stored->status);
        $this->assertSame(3, $stored->publish_attempts);
        $this->assertSame('STALE_PUBLISHING', $stored->publish_failure_code);
        $this->assertNotNull($stored->publish_failed_at);
        $event = NotificationRecipientEvent::query()->where('message_id', $message->id)
            ->where('event_type', NotificationEventType::REQUEUED->value)->sole();
        $metadata = $event->metadata;
        ksort($metadata); // jsonb stores keys in its own order
        $this->assertSame([WorkflowActorSide::STAFF, (int) $this->staff->id, 'STALE_PUBLISHING', ['attempts' => 3, 'retry' => 'MANUAL']],
            [$event->actor_side, (int) $event->actor_user_id, $event->reason_code, $metadata]);
        $this->assertSame([['id' => (int) $message->id, 'status' => 'QUEUED', 'level' => DB::transactionLevel()]], $this->dispatcher->dispatched);
        $this->assertSame(0, DB::table('notification_recipients')->count());
    }

    public function test_a_transient_failure_with_automatic_attempts_left_is_not_retried_manually(): void
    {
        foreach ([1, 2] as $attempts) {
            $message = $this->failed(NotificationFailureCode::PUBLICATION_ERROR, $attempts);
            $this->assertRefused(NotificationPublicationException::AUTOMATIC_RETRY_PENDING, fn () => $this->retry($this->staff, $message));
            $this->assertStillFailed($message);
        }
    }

    public function test_a_manual_only_failure_is_retried_at_once_but_only_once(): void
    {
        $message = $this->failed(NotificationFailureCode::AUDIENCE_EMPTY, attempts: 1);
        $this->retry($this->staff, $message);
        $this->assertSame(NotificationStatus::QUEUED, $message->fresh()->status);

        // The next claim fails the same way: the manual allowance is used.
        $again = $this->failPublication($message->fresh(), NotificationFailureCode::AUDIENCE_EMPTY, attempts: 2);
        $this->assertRefused(NotificationPublicationException::RETRY_LIMIT_REACHED, fn () => $this->retry($this->staff, $again));
        $this->assertStillFailed($again, requeues: 1);
    }

    public function test_a_second_manual_retry_is_refused_and_four_claims_is_the_limit(): void
    {
        $message = $this->failed(NotificationFailureCode::PUBLICATION_ERROR, attempts: 3);
        $this->retry($this->staff, $message);

        $fourth = $this->failPublication($message->fresh(), NotificationFailureCode::PUBLICATION_ERROR, attempts: 4);
        $this->assertRefused(NotificationPublicationException::RETRY_LIMIT_REACHED, fn () => $this->retry($this->staff, $fourth));
        $this->assertStillFailed($fourth, requeues: 1);

        // Even with the manual allowance unused, no fifth claim.
        $exhausted = $this->failed(NotificationFailureCode::PUBLICATION_ERROR, attempts: 4);
        $this->assertRefused(NotificationPublicationException::RETRY_LIMIT_REACHED, fn () => $this->retry($this->staff, $exhausted));
    }

    public function test_permanent_and_unknown_failures_are_never_retried(): void
    {
        foreach ([...array_filter(NotificationFailureCode::cases(), fn ($code) => $code->isPermanent()), 'SOMETHING_ELSE'] as $code) {
            foreach ([1, 3] as $attempts) {
                $message = $this->failed($code, $attempts);
                $this->assertRefused(NotificationPublicationException::RETRY_NOT_ALLOWED, fn () => $this->retry($this->staff, $message));
                $this->assertStillFailed($message);
            }
        }
    }

    public function test_automatic_requeues_are_system_and_never_consume_the_manual_retry(): void
    {
        $message = $this->failed(NotificationFailureCode::PUBLICATION_ERROR, attempts: 1);
        $this->automaticRequeue($message->fresh());
        $message = $this->failPublication($message->fresh(), NotificationFailureCode::PUBLICATION_ERROR, attempts: 2);
        $this->automaticRequeue($message);
        $message = $this->failPublication($message->fresh(), NotificationFailureCode::STALE_PUBLISHING, attempts: 3);

        $this->retry($this->staff, $message);

        $this->assertSame([['SYSTEM', null], ['SYSTEM', null], ['STAFF', (int) $this->staff->id]], $this->requeues($message));
    }

    public function test_a_coordinators_manual_retry_is_attributed_to_the_coordinator_and_counted(): void
    {
        $clan = $this->clan('NTF_CLAN');
        $this->familyIn($clan);
        $coordinator = $this->coordinatorSender($clan);
        $message = $this->failed(NotificationFailureCode::AUDIENCE_EMPTY, 1, sender: $coordinator, audience: ['type' => 'COORDINATOR_SCOPE']);

        $this->retry($coordinator, $message);
        $this->assertSame([['COORDINATOR', (int) $coordinator->id]], $this->requeues($message));

        $again = $this->failPublication($message->fresh(), NotificationFailureCode::AUDIENCE_EMPTY, attempts: 2);
        $this->assertRefused(NotificationPublicationException::RETRY_LIMIT_REACHED, fn () => $this->retry($coordinator, $again));
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

    public function test_readiness_permission_and_audience_are_rechecked(): void
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

    public function test_invalid_retry_configuration_fails_closed(): void
    {
        $message = $this->failed();
        foreach ([['automatic_publish_attempts', 4], ['automatic_publish_attempts', 'many'], ['manual_publish_retries', 2], ['manual_publish_retries', -1]] as [$key, $value]) {
            $this->notificationDefaults();
            config(['notifications.mode' => 'ON', "notifications.{$key}" => $value]);
            $this->assertRefused(NotificationPublicationException::PUBLICATION_UNAVAILABLE, fn () => $this->retry($this->staff, $message));
        }

        // A manual allowance of 0 is valid: then no manual retry at all.
        $this->notificationDefaults();
        config(['notifications.mode' => 'ON', 'notifications.manual_publish_retries' => 0]);
        $this->assertRefused(NotificationPublicationException::RETRY_LIMIT_REACHED, fn () => $this->retry($this->staff, $message));
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

    public function test_a_failing_audit_write_rolls_the_retry_back(): void
    {
        $message = $this->failed();
        $this->dispatcher->dispatched = [];
        DB::listen(function ($query) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'insert into "notification_recipient_events"')) {
                throw new RuntimeException('audit write failed');
            }
        });

        try {
            $this->retry($this->staff, $message);
            $this->fail('Expected the audit failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit write failed', $e->getMessage());
        }
        $this->assertStillFailed($message);
        $this->assertSame([], $this->dispatcher->dispatched);
    }
}
