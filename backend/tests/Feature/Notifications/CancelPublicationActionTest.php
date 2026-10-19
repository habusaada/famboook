<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\CancelPublicationAction;
use App\Actions\Notifications\CreateAnnouncementDraftAction;
use App\Actions\Notifications\RequestPublicationAction;
use App\Actions\Notifications\RetryPublicationAction;
use App\Contracts\NotificationPublicationDispatcher;
use App\Enums\NotificationEventType;
use App\Enums\NotificationFailureCode;
use App\Enums\NotificationStatus;
use App\Exceptions\NotificationPublicationException;
use App\Jobs\PublishNotificationJob;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\FamilyAuth\CoordinatorScopes;
use App\Support\Notifications\NotificationActors;
use App\Support\Notifications\NotificationLifecycle;
use App\Support\Notifications\NotificationOutcome;
use App\Support\Notifications\QueueNotificationPublicationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.4: CancelPublicationAction — DRAFT, QUEUED or
 * PUBLISH_FAILED → CANCELLED with its actor and event; PUBLISHING and
 * PUBLISHED are conflicts; a message cancelled after its hand-off can never
 * be claimed by the worker. Synthetic data only.
 */
class CancelPublicationActionTest extends TestCase
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

    private function staffDraft(): NotificationMessage
    {
        return $this->draft($this->staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->family->id]]);
    }

    private function queued(): NotificationMessage
    {
        return app(RequestPublicationAction::class)->handle($this->staff, $this->staffDraft())->message->fresh();
    }

    private function cancel(User $user, NotificationMessage $message, ?string $reason = null): NotificationOutcome
    {
        return app(CancelPublicationAction::class)->handle($user, $message, $reason);
    }

    private function assertRefused(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected {$reason}.");
        } catch (NotificationPublicationException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_a_draft_is_cancelled_with_the_create_permission_alone(): void
    {
        $creator = $this->staffSender(NotificationActors::STAFF_CREATE);
        $message = $this->draft($creator, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->family->id]]);

        $this->cancel($creator, $message, 'WRONG_AUDIENCE');

        $stored = $message->fresh();
        $this->assertSame(NotificationStatus::CANCELLED, $stored->status);
        $this->assertSame((int) $creator->id, (int) $stored->cancelled_by_user_id);
        $this->assertSame('WRONG_AUDIENCE', $stored->cancellation_reason_code);
        $this->assertNotNull($stored->cancelled_at);
        $event = NotificationRecipientEvent::query()->where('message_id', $message->id)->sole();
        $this->assertSame([NotificationEventType::CANCELLED, 'WRONG_AUDIENCE', ['from_status' => 'DRAFT']],
            [$event->event_type, $event->reason_code, $event->metadata]);
    }

    public function test_a_queued_message_cancelled_after_its_hand_off_can_never_be_claimed(): void
    {
        $message = $this->queued();
        $this->assertSame([(int) $message->id], $this->dispatcher->ids());

        $this->cancel($this->staff, $message);

        // The worker's claim under the row lock: a stale job publishes nothing.
        $locked = DB::transaction(fn () => NotificationLifecycle::lock($message));
        $this->assertFalse(NotificationLifecycle::mayStartPublishing($locked));
        $this->assertSame(NotificationStatus::CANCELLED, $locked->status);
        $this->assertSame([NotificationEventType::QUEUED, NotificationEventType::CANCELLED],
            NotificationRecipientEvent::query()->where('message_id', $message->id)->orderBy('id')->pluck('event_type')->all());
        $this->assertSame(0, DB::table('notification_recipients')->count());
    }

    public function test_once_queued_the_send_permission_is_needed_to_cancel(): void
    {
        $message = $this->queued();
        $this->staff->revokePermissionTo(NotificationActors::STAFF_SEND);

        $this->assertRefused(NotificationPublicationException::ACTOR_NOT_ALLOWED, fn () => $this->cancel($this->staff->fresh(), $message));
        $this->assertSame(NotificationStatus::QUEUED, $message->fresh()->status);
    }

    public function test_a_failed_message_is_cancelled_with_its_failure_history_kept(): void
    {
        $message = $this->failPublication($this->queued(), NotificationFailureCode::SENDER_NOT_AUTHORIZED, attempts: 2);

        $this->cancel($this->staff, $message);

        $stored = $message->fresh();
        $this->assertSame(NotificationStatus::CANCELLED, $stored->status);
        $this->assertSame('SENDER_NOT_AUTHORIZED', $stored->publish_failure_code);
        $this->assertNotNull($stored->publish_failed_at);
        $this->assertSame(2, $stored->publish_attempts);
    }

    public function test_publishing_and_published_messages_are_conflicts(): void
    {
        $publishing = $this->queued();
        $publishing->forceFill(['status' => NotificationStatus::PUBLISHING, 'publishing_started_at' => now(),
            'publishing_token' => (string) Str::uuid(), 'publish_attempts' => 1])->save();
        $published = $this->queued();
        $published->forceFill(['status' => NotificationStatus::PUBLISHED, 'publishing_started_at' => now(),
            'publishing_token' => (string) Str::uuid(), 'publish_attempts' => 1, 'published_at' => now(), 'recipient_count' => 1])->save();

        foreach ([$publishing, $published] as $message) {
            $this->assertRefused(NotificationPublicationException::INVALID_TRANSITION, fn () => $this->cancel($this->staff, $message));
            $this->assertNotSame(NotificationStatus::CANCELLED, $message->fresh()->status);
            $this->assertSame(0, NotificationRecipientEvent::query()->where('message_id', $message->id)
                ->where('event_type', NotificationEventType::CANCELLED->value)->count());
        }
    }

    public function test_a_repeated_cancel_is_a_replay_and_only_the_sender_cancels(): void
    {
        $message = $this->staffDraft();
        $this->assertRefused(NotificationPublicationException::NOT_FOUND, fn () => $this->cancel($this->staffSender(), $message));

        $this->cancel($this->staff, $message);
        $this->assertTrue($this->cancel($this->staff, $message)->replayed);
        $this->assertSame(1, NotificationRecipientEvent::query()->where('message_id', $message->id)->count());
    }

    public function test_cancelling_needs_no_readiness_and_validates_the_reason_code(): void
    {
        $message = $this->queued();
        config(['notifications.mode' => 'OFF', 'queue.default' => 'sync']);

        try {
            $this->cancel($this->staff, $message, 'changed my mind');
            $this->fail('Expected a validation error.');
        } catch (ValidationException) {
            $this->assertSame(NotificationStatus::QUEUED, $message->fresh()->status);
        }

        $this->assertSame(NotificationStatus::CANCELLED, $this->cancel($this->staff, $message)->message->status);
    }

    public function test_a_coordinator_cancels_their_own_message_in_their_current_context(): void
    {
        $clan = $this->clan('NTF_CLAN');
        $this->familyIn($clan);
        $coordinator = $this->coordinatorSender($clan);
        $message = $this->draft($coordinator, ['type' => 'COORDINATOR_SCOPE']);

        $this->assertRefused(NotificationPublicationException::NOT_FOUND, fn () => $this->cancel($this->staff, $message));
        $this->cancel($coordinator, $message);

        $event = NotificationRecipientEvent::query()->where('message_id', $message->id)->sole();
        $this->assertSame('COORDINATOR', $event->actor_side->value);
    }

    // ------------------------------------- withdrawal after scope revocation

    /** @return array{0: User, 1: NotificationMessage, 2: NotificationMessage, 3: NotificationMessage} drafted, queued and failed by a coordinator */
    private function coordinatorMessages(): array
    {
        $clan = $this->clan('NTF_CLAN');
        $this->familyIn($clan);
        $coordinator = $this->coordinatorSender($clan);
        $draft = $this->draft($coordinator, ['type' => 'COORDINATOR_SCOPE']);
        $queued = app(RequestPublicationAction::class)->handle($coordinator, $this->draft($coordinator, ['type' => 'COORDINATOR_SCOPE']))->message;
        $failed = app(RequestPublicationAction::class)->handle($coordinator, $this->draft($coordinator, ['type' => 'COORDINATOR_SCOPE']))->message;
        $failed = $this->failPublication($failed->fresh(), NotificationFailureCode::PUBLICATION_ERROR);

        return [$coordinator, $draft, $queued->fresh(), $failed];
    }

    private function revokeAllAssignments(User $coordinator): void
    {
        DB::table('coordinator_scope_assignments')->where('user_id', $coordinator->id)
            ->update(['revoked_at' => now(), 'revoked_by' => $this->staff->id, 'revoke_reason' => 'ADMINISTRATIVE']);
    }

    public function test_a_coordinator_withdraws_their_own_messages_after_their_assignments_are_revoked(): void
    {
        [$coordinator, $draft, $queued, $failed] = $this->coordinatorMessages();
        $this->revokeAllAssignments($coordinator);
        $this->assertFalse(app(CoordinatorScopes::class)->context($coordinator)->allowed());

        foreach ([$draft, $queued, $failed] as $message) {
            $from = $message->status->value;
            $this->cancel($coordinator, $message, 'SCOPE_REVOKED');

            $stored = $message->fresh();
            $this->assertSame(NotificationStatus::CANCELLED, $stored->status);
            $this->assertSame((int) $coordinator->id, (int) $stored->cancelled_by_user_id);
            $event = NotificationRecipientEvent::query()->where('message_id', $message->id)
                ->where('event_type', NotificationEventType::CANCELLED->value)->sole();
            $this->assertSame(['COORDINATOR', (int) $coordinator->id, ['from_status' => $from]],
                [$event->actor_side->value, (int) $event->actor_user_id, $event->metadata]);
        }
        // The queued one can no longer be claimed by a worker.
        $this->assertFalse(NotificationLifecycle::mayStartPublishing($queued->fresh()));
    }

    public function test_another_coordinator_never_cancels_the_message(): void
    {
        [, $draft, $queued] = $this->coordinatorMessages();
        $sameScope = $this->coordinatorSender($this->clan('NTF_CLAN'), '222222220');

        foreach ([$draft, $queued] as $message) {
            $this->assertRefused(NotificationPublicationException::NOT_FOUND, fn () => $this->cancel($sameScope, $message));
            $this->assertNotSame(NotificationStatus::CANCELLED, $message->fresh()->status);
        }
    }

    public function test_an_inactive_account_cannot_cancel(): void
    {
        [$coordinator, $draft] = $this->coordinatorMessages();
        $staffDraft = $this->staffDraft();
        $coordinator->forceFill(['is_active' => false])->save();
        $this->staff->forceFill(['is_active' => false])->save();

        $this->assertRefused(NotificationPublicationException::ACTOR_NOT_ALLOWED, fn () => $this->cancel($coordinator->fresh(), $draft));
        $this->assertRefused(NotificationPublicationException::ACTOR_NOT_ALLOWED, fn () => $this->cancel($this->staff->fresh(), $staffDraft));
        $this->assertSame(NotificationStatus::DRAFT, $draft->fresh()->status);
        $this->assertSame(NotificationStatus::DRAFT, $staffDraft->fresh()->status);
    }

    public function test_a_revoked_coordinator_still_cannot_cancel_publishing_or_published(): void
    {
        [$coordinator, , $queued, $failed] = $this->coordinatorMessages();
        $queued->forceFill(['status' => NotificationStatus::PUBLISHING, 'publishing_started_at' => now(),
            'publishing_token' => (string) Str::uuid(), 'publish_attempts' => 1])->save();
        $failed->forceFill(['status' => NotificationStatus::PUBLISHED, 'publishing_started_at' => now(),
            'publishing_token' => (string) Str::uuid(), 'published_at' => now(), 'recipient_count' => 1])->save();
        $this->revokeAllAssignments($coordinator);

        foreach ([$queued, $failed] as $message) {
            $this->assertRefused(NotificationPublicationException::INVALID_TRANSITION, fn () => $this->cancel($coordinator, $message));
        }
    }

    public function test_withdrawing_grants_no_drafting_publication_or_retry(): void
    {
        [$coordinator, $draft, , $failed] = $this->coordinatorMessages();
        $other = $this->draft($coordinator, ['type' => 'COORDINATOR_SCOPE']);
        $this->revokeAllAssignments($coordinator);
        $this->cancel($coordinator, $draft);

        $this->assertRefused(NotificationPublicationException::ACTOR_NOT_ALLOWED,
            fn () => app(RequestPublicationAction::class)->handle($coordinator, $other));
        $this->assertRefused(NotificationPublicationException::ACTOR_NOT_ALLOWED,
            fn () => app(RetryPublicationAction::class)->handle($coordinator, $failed));
        $this->assertRefused(NotificationPublicationException::ACTOR_NOT_ALLOWED,
            fn () => app(CreateAnnouncementDraftAction::class)->handle($coordinator, ['title' => 'إعلان', 'body' => 'نص.', 'audience' => ['type' => 'COORDINATOR_SCOPE']]));
        $this->assertSame(NotificationStatus::DRAFT, $other->fresh()->status);
        $this->assertSame(NotificationStatus::PUBLISH_FAILED, $failed->fresh()->status);

        // Without the send permission a queued or failed message stays as it is.
        $coordinator->revokePermissionTo(NotificationActors::COORDINATOR_SEND);
        $this->assertRefused(NotificationPublicationException::ACTOR_NOT_ALLOWED, fn () => $this->cancel($coordinator->fresh(), $failed));
    }

    public function test_the_queue_dispatcher_is_bound_and_only_queues_the_job(): void
    {
        $this->assertSame('OFF', (require config_path('notifications.php'))['mode']);
        $this->app->forgetInstance(NotificationPublicationDispatcher::class);
        $bound = $this->app->make(NotificationPublicationDispatcher::class);
        $this->assertInstanceOf(QueueNotificationPublicationDispatcher::class, $bound);

        Queue::fake();
        $message = app(RequestPublicationAction::class)->handle($this->staff, $this->staffDraft())->message;

        // A job on the notifications queue — no publication in this process.
        Queue::assertPushedOn('notifications', PublishNotificationJob::class, fn (PublishNotificationJob $job) => $job->messageId === (int) $message->id);
        Queue::assertCount(1);
        $this->assertSame(NotificationStatus::QUEUED, $message->fresh()->status);
        $this->assertNull($message->fresh()->published_at);
        $this->assertSame(0, DB::table('notification_recipients')->count());
        $this->assertSame([NotificationEventType::QUEUED],
            NotificationRecipientEvent::query()->where('message_id', $message->id)->pluck('event_type')->all());
    }
}
