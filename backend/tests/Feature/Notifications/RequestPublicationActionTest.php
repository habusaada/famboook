<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\CancelPublicationAction;
use App\Actions\Notifications\RequestPublicationAction;
use App\Enums\FamilyStatus;
use App\Enums\NotificationEventType;
use App\Enums\NotificationStatus;
use App\Enums\WorkflowActorSide;
use App\Exceptions\NotificationAudienceException;
use App\Exceptions\NotificationPublicationException;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\Notifications\NotificationActors;
use App\Support\Notifications\NotificationLifecycle;
use App\Support\Notifications\NotificationOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.4: RequestPublicationAction — DRAFT → QUEUED under the
 * row lock with its QUEUED event, only for the sender with the send
 * permission, only when the system is ready (OFF / PILOT / ON), with the
 * expiry lead and the CURRENT audience eligibility; the hand-off only after
 * commit, nothing published and no recipient written. Synthetic data only.
 */
class RequestPublicationActionTest extends TestCase
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

    private function staffDraft(array $input = []): NotificationMessage
    {
        return $this->draft($this->staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->family->id]], $input);
    }

    private function request(User $user, NotificationMessage $message): NotificationOutcome
    {
        return app(RequestPublicationAction::class)->handle($user, $message);
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

    private function assertStillDraft(NotificationMessage $message): void
    {
        $this->assertSame(NotificationStatus::DRAFT, $message->fresh()->status);
        $this->assertSame(0, NotificationRecipientEvent::query()->where('message_id', $message->id)->count());
    }

    public function test_a_ready_system_queues_the_draft_with_its_event_and_hands_it_off_after_commit(): void
    {
        Queue::fake();
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
        $message = $this->staffDraft();

        $outcome = $this->request($this->staff, $message);

        $stored = $message->fresh();
        $this->assertFalse($outcome->replayed);
        $this->assertSame(NotificationStatus::QUEUED, $stored->status);
        $this->assertSame('2026-10-19 10:00:00', $stored->queued_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $stored->publish_attempts);
        $event = NotificationRecipientEvent::query()->where('message_id', $message->id)->sole();
        $this->assertSame(NotificationEventType::QUEUED, $event->event_type);
        $this->assertSame(WorkflowActorSide::STAFF, $event->actor_side);
        $this->assertSame((int) $this->staff->id, (int) $event->actor_user_id);
        $this->assertSame(['audience_type' => 'STAFF_FAMILIES'], $event->metadata);
        // Handed off once, after commit, with the committed QUEUED state.
        $this->assertSame([['id' => (int) $message->id, 'status' => 'QUEUED', 'level' => DB::transactionLevel()]], $this->dispatcher->dispatched);
        // Nothing published, no recipient, no job on any queue.
        $this->assertNull($stored->published_at);
        $this->assertSame(0, DB::table('notification_recipients')->count());
        Queue::assertNothingPushed();
    }

    public function test_the_hand_off_waits_for_the_outermost_commit_and_never_follows_a_rollback(): void
    {
        $committed = $this->staffDraft();
        DB::transaction(function () use ($committed) {
            $this->request($this->staff, $committed);
            $this->assertSame([], $this->dispatcher->dispatched);
        });
        $this->assertSame([(int) $committed->id], $this->dispatcher->ids());

        $rolledBack = $this->staffDraft();
        try {
            DB::transaction(function () use ($rolledBack) {
                $this->request($this->staff, $rolledBack);
                throw new RuntimeException('the caller failed');
            });
        } catch (RuntimeException) {
        }
        $this->assertStillDraft($rolledBack);
        $this->assertSame([(int) $committed->id], $this->dispatcher->ids());
    }

    public function test_a_failing_audit_write_rolls_the_state_back(): void
    {
        $message = $this->staffDraft();
        DB::listen(function ($query) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'insert into "notification_recipient_events"')) {
                throw new RuntimeException('audit write failed');
            }
        });

        try {
            $this->request($this->staff, $message);
            $this->fail('Expected the audit failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit write failed', $e->getMessage());
        }
        $this->assertStillDraft($message);
        $this->assertSame([], $this->dispatcher->dispatched);
    }

    public function test_readiness_off_pilot_and_on(): void
    {
        $message = $this->staffDraft();

        config(['notifications.mode' => 'OFF']);
        $this->assertRefused(NotificationPublicationException::PUBLICATION_UNAVAILABLE, fn () => $this->request($this->staff, $message));

        config(['notifications.mode' => 'PILOT', 'notifications.pilot_sender_user_ids' => '999999']);
        $this->assertRefused(NotificationPublicationException::PUBLICATION_UNAVAILABLE, fn () => $this->request($this->staff, $message));
        $this->assertStillDraft($message);

        config(['notifications.pilot_sender_user_ids' => (string) $this->staff->id]);
        $this->assertSame(NotificationStatus::QUEUED, $this->request($this->staff, $message)->message->status);

        config(['notifications.mode' => 'ON']);
        $this->assertSame(NotificationStatus::QUEUED, $this->request($this->staff, $this->staffDraft())->message->status);
    }

    public function test_a_synchronous_queue_or_a_stale_worker_is_never_a_fallback(): void
    {
        $message = $this->staffDraft();
        config(['queue.default' => 'sync']);
        $this->assertRefused(NotificationPublicationException::PUBLICATION_UNAVAILABLE, fn () => $this->request($this->staff, $message));

        config(['queue.default' => 'database']);
        $this->travel(301)->seconds();
        $this->assertRefused(NotificationPublicationException::PUBLICATION_UNAVAILABLE, fn () => $this->request($this->staff, $message));
        $this->assertStillDraft($message);
    }

    public function test_only_the_sender_with_the_send_permission_requests(): void
    {
        $message = $this->staffDraft();
        $createOnly = $this->staffSender(NotificationActors::STAFF_CREATE);
        $this->assertRefused(NotificationPublicationException::ACTOR_NOT_ALLOWED, fn () => $this->request($createOnly, $message));
        $this->assertRefused(NotificationPublicationException::NOT_FOUND, fn () => $this->request($this->staffSender(), $message));

        $this->staff->revokePermissionTo(NotificationActors::STAFF_SEND);
        $this->assertRefused(NotificationPublicationException::ACTOR_NOT_ALLOWED, fn () => $this->request($this->staff->fresh(), $message));
        $this->assertStillDraft($message);
    }

    public function test_the_expiry_needs_an_hour_of_lead_when_publication_is_requested(): void
    {
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
        $soon = $this->staffDraft(['expires_at' => now()->addMinutes(59)->toIso8601String()]);
        $this->assertRefused(NotificationPublicationException::EXPIRY_TOO_SOON, fn () => $this->request($this->staff, $soon));
        $this->assertStillDraft($soon);

        $later = $this->staffDraft(['expires_at' => now()->addMinutes(90)->toIso8601String()]);
        $this->travel(31)->minutes();
        $this->freshHeartbeat();
        $this->assertRefused(NotificationPublicationException::EXPIRY_TOO_SOON, fn () => $this->request($this->staff, $later));

        $exact = $this->staffDraft(['expires_at' => now()->addHour()->toIso8601String()]);
        $this->assertSame(NotificationStatus::QUEUED, $this->request($this->staff, $exact)->message->status);
    }

    public function test_the_audience_is_re_validated_at_request(): void
    {
        $message = $this->staffDraft();
        $this->family->forceFill(['status' => FamilyStatus::INACTIVE->value])->save();

        $this->assertRefused(NotificationAudienceException::FAMILY_NOT_ELIGIBLE, fn () => $this->request($this->staff, $message));
        $this->assertStillDraft($message);
    }

    public function test_a_coordinators_current_scope_decides_and_is_snapshotted_again(): void
    {
        $clan = $this->clan('NTF_CLAN');
        [$first, $second] = [$this->branch($clan), $this->branch($clan)];
        $this->familyIn($clan, $first);
        $this->familyIn($clan, $second);
        $coordinator = $this->coordinatorSender($first);
        $extra = $this->assign($coordinator, $second);
        $selection = $this->draft($coordinator, ['type' => 'COORDINATOR_SELECTION', 'branch_ids' => [$second->id]]);
        $scope = $this->draft($coordinator, ['type' => 'COORDINATOR_SCOPE']);

        // The second Branch is revoked: the selection is no longer authorized …
        $extra->forceFill(['revoked_at' => now(), 'revoked_by' => $this->staff->id, 'revoke_reason' => 'ADMINISTRATIVE'])->save();
        $this->assertRefused(NotificationAudienceException::NOT_AUTHORIZED, fn () => $this->request($coordinator, $selection));
        $this->assertStillDraft($selection);

        // … and the scope message is queued with the CURRENT scope snapshot.
        $queued = $this->request($coordinator, $scope)->message->fresh();
        $this->assertSame([(int) $first->id], array_column($queued->sender_scope['assignments'], 'branch_id'));
        $this->assertSame(WorkflowActorSide::COORDINATOR, NotificationRecipientEvent::query()->where('message_id', $scope->id)->sole()->actor_side);
    }

    public function test_a_coordinator_without_a_current_context_cannot_request(): void
    {
        $clan = $this->clan('NTF_CLAN');
        $this->familyIn($clan);
        $coordinator = $this->coordinatorSender($clan);
        $message = $this->draft($coordinator, ['type' => 'COORDINATOR_SCOPE']);
        DB::table('coordinator_scope_assignments')->where('user_id', $coordinator->id)
            ->update(['revoked_at' => now(), 'revoked_by' => $this->staff->id, 'revoke_reason' => 'ADMINISTRATIVE']);

        $this->assertRefused(NotificationPublicationException::ACTOR_NOT_ALLOWED, fn () => $this->request($coordinator, $message));
    }

    public function test_only_a_draft_is_requested_and_a_repeat_is_a_replay(): void
    {
        $message = $this->staffDraft();
        $this->request($this->staff, $message);
        $again = $this->request($this->staff, $message);

        $this->assertTrue($again->replayed);
        $this->assertSame(1, NotificationRecipientEvent::query()->where('message_id', $message->id)->count());
        $this->assertCount(1, $this->dispatcher->dispatched);

        $cancelled = $this->staffDraft();
        app(CancelPublicationAction::class)->handle($this->staff, $cancelled);
        $this->assertRefused(NotificationPublicationException::INVALID_TRANSITION, fn () => $this->request($this->staff, $cancelled));

        $failed = $this->failPublication($this->staffDraft(), 'PUBLICATION_ERROR');
        $this->assertRefused(NotificationPublicationException::INVALID_TRANSITION, fn () => $this->request($this->staff, $failed));
    }

    public function test_the_lifecycle_table_allows_only_the_approved_moves(): void
    {
        $allowed = [
            'DRAFT' => ['QUEUED', 'CANCELLED'], 'QUEUED' => ['PUBLISHING', 'CANCELLED'], 'PUBLISHING' => ['PUBLISHED', 'PUBLISH_FAILED'],
            'PUBLISH_FAILED' => ['QUEUED', 'CANCELLED'], 'PUBLISHED' => ['ARCHIVED'], 'ARCHIVED' => [], 'CANCELLED' => [],
        ];
        foreach (NotificationStatus::cases() as $from) {
            foreach (NotificationStatus::cases() as $to) {
                $this->assertSame(in_array($to->value, $allowed[$from->value], true), NotificationLifecycle::allows($from, $to), "{$from->value} → {$to->value}");
            }
        }
    }
}
