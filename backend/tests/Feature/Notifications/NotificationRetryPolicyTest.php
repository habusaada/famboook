<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationFailureCategory;
use App\Enums\NotificationFailureCode;
use App\Enums\NotificationRetryDecision as Decision;
use App\Enums\NotificationStatus;
use App\Enums\WorkflowActorSide;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Support\Notifications\NotificationConfig;
use App\Support\Notifications\NotificationEventRecorder;
use App\Support\Notifications\NotificationRetryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.5b: NotificationRetryPolicy — the decision service the
 * reconciliation worker (2b.5d) and the manual retry share. 3 automatic
 * attempts with a 1 / 5 minute backoff, then one manual retry; manual-only
 * failures go to the sender at once; permanent and unknown failures never;
 * at most 4 worker claims; manual retries counted from REQUEUED events by
 * STAFF / COORDINATOR. It reads only. Messages are put into their states row
 * by row (the worker is 2b.5c). Synthetic data only.
 */
class NotificationRetryPolicyTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private NotificationRetryPolicy $policy;

    private User $staff;

    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
        $this->policy = app(NotificationRetryPolicy::class);
        $this->staff = $this->staffSender();
        $this->family = Family::factory()->create();
        $this->travelTo('2026-10-19 10:00:00');
    }

    /** A message that failed at now() after $attempts claims. */
    private function failedAfter(int $attempts, NotificationFailureCode|string $code = NotificationFailureCode::PUBLICATION_ERROR, array $input = []): NotificationMessage
    {
        $message = $this->draft($this->staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [$this->family->id]], $input);

        return $this->failPublication($message, $code, $attempts);
    }

    private function requeue(NotificationMessage $message, WorkflowActorSide $side, ?User $actor = null): void
    {
        DB::transaction(fn () => app(NotificationEventRecorder::class)->message($message, NotificationEventType::REQUEUED, $side, $actor,
            (string) $message->publish_failure_code));
    }

    public function test_the_failure_codes_are_classified(): void
    {
        $classes = [];
        foreach (NotificationFailureCode::cases() as $code) {
            $classes[$code->category()->value][] = $code->value;
        }

        $this->assertSame([
            NotificationFailureCategory::PERMANENT->value => ['SENDER_NOT_AUTHORIZED', 'AUDIENCE_INVALID', 'EXPIRED_BEFORE_PUBLICATION'],
            NotificationFailureCategory::MANUAL_ONLY->value => ['AUDIENCE_EMPTY'],
            NotificationFailureCategory::TRANSIENT->value => ['PUBLICATION_ERROR', 'STALE_PUBLISHING'],
        ], $classes);
    }

    public function test_automatic_attempts_one_and_two_are_retried_after_their_backoff_and_three_is_exhausted(): void
    {
        foreach ([1 => 60, 2 => 300] as $attempts => $backoff) {
            $message = $this->failedAfter($attempts);
            $this->assertSame(now()->addSeconds($backoff)->toDateTimeString(), $this->policy->automaticRetryAt($message)->toDateTimeString());

            $this->travel($backoff - 1)->seconds();
            $this->assertSame(Decision::BACKOFF_PENDING, $this->policy->automatic($message), "attempt {$attempts}, 1 s early");
            $this->travel(1)->seconds();
            $this->assertSame(Decision::ALLOWED, $this->policy->automatic($message), "attempt {$attempts}, at the boundary");
            $this->travelTo('2026-10-19 10:00:00');
        }

        $exhausted = $this->failedAfter(3);
        $this->travel(1)->hours();
        $this->assertSame(Decision::AUTOMATIC_ATTEMPTS_EXHAUSTED, $this->policy->automatic($exhausted));
    }

    public function test_the_automatic_decision_refuses_what_is_not_a_transient_failure(): void
    {
        $this->assertSame(Decision::MANUAL_ONLY_FAILURE, $this->policy->automatic($this->failedAfter(1, NotificationFailureCode::AUDIENCE_EMPTY)));
        foreach ([NotificationFailureCode::SENDER_NOT_AUTHORIZED, NotificationFailureCode::AUDIENCE_INVALID, NotificationFailureCode::EXPIRED_BEFORE_PUBLICATION] as $code) {
            $this->assertSame(Decision::PERMANENT_FAILURE, $this->policy->automatic($this->failedAfter(1, $code)));
            $this->assertSame(Decision::PERMANENT_FAILURE, $this->policy->manual($this->failedAfter(3, $code)));
        }
        $this->assertSame(Decision::UNKNOWN_FAILURE, $this->policy->automatic($this->failedAfter(1, 'NOT_A_CODE')));
        $this->assertSame(Decision::UNKNOWN_FAILURE, $this->policy->manual($this->failedAfter(3, 'NOT_A_CODE')));
    }

    public function test_only_a_failed_message_is_ever_retried(): void
    {
        $message = $this->failedAfter(1);
        foreach ([NotificationStatus::QUEUED, NotificationStatus::PUBLISHING, NotificationStatus::CANCELLED] as $status) {
            $message->status = $status;
            $this->assertSame(Decision::NOT_FAILED, $this->policy->automatic($message));
            $this->assertSame(Decision::NOT_FAILED, $this->policy->manual($message));
        }
    }

    public function test_the_manual_decision(): void
    {
        $this->assertSame(Decision::AUTOMATIC_RETRY_PENDING, $this->policy->manual($this->failedAfter(1)));
        $this->assertSame(Decision::AUTOMATIC_RETRY_PENDING, $this->policy->manual($this->failedAfter(2)));
        $this->assertSame(Decision::ALLOWED, $this->policy->manual($this->failedAfter(3)));
        $this->assertSame(Decision::ALLOWED, $this->policy->manual($this->failedAfter(1, NotificationFailureCode::AUDIENCE_EMPTY)));
        $this->assertSame(Decision::CLAIM_LIMIT_REACHED, $this->policy->manual($this->failedAfter(4)));

        $used = $this->failedAfter(3);
        $this->requeue($used, WorkflowActorSide::STAFF, $this->staff);
        $this->assertSame(Decision::MANUAL_RETRIES_EXHAUSTED, $this->policy->manual($used));
    }

    public function test_four_claims_at_most_along_the_longest_path(): void
    {
        // Claims 1–3 fail transiently and are requeued automatically; claim 4 is the manual one.
        $message = $this->failedAfter(1);
        foreach ([1, 2] as $claim) {
            $this->travel(10)->minutes();
            $this->assertSame(Decision::ALLOWED, $this->policy->automatic($message), "after claim {$claim}");
            $this->requeue($message, WorkflowActorSide::SYSTEM);
            $message = $this->failPublication($message->fresh(), NotificationFailureCode::PUBLICATION_ERROR, $claim + 1);
        }
        $this->travel(10)->minutes();
        $this->assertSame(Decision::AUTOMATIC_ATTEMPTS_EXHAUSTED, $this->policy->automatic($message));
        $this->assertSame(Decision::ALLOWED, $this->policy->manual($message));
        $this->requeue($message, WorkflowActorSide::STAFF, $this->staff);
        $message = $this->failPublication($message->fresh(), NotificationFailureCode::PUBLICATION_ERROR, 4);

        $this->travel(10)->minutes();
        $this->assertSame(Decision::AUTOMATIC_ATTEMPTS_EXHAUSTED, $this->policy->automatic($message));
        $this->assertSame(Decision::MANUAL_RETRIES_EXHAUSTED, $this->policy->manual($message));
        $this->assertSame(4, $this->policy->claimLimit());
    }

    public function test_after_a_manual_retry_of_an_empty_audience_the_automatic_budget_still_applies(): void
    {
        // Claim 1: empty audience → the manual retry is used early.
        $message = $this->failedAfter(1, NotificationFailureCode::AUDIENCE_EMPTY);
        $this->assertSame(Decision::ALLOWED, $this->policy->manual($message));
        $this->requeue($message, WorkflowActorSide::STAFF, $this->staff);

        // Claim 2 fails transiently: the remaining automatic budget applies.
        $message = $this->failPublication($message->fresh(), NotificationFailureCode::PUBLICATION_ERROR, 2);
        $this->travel(5)->minutes();
        $this->assertSame(Decision::ALLOWED, $this->policy->automatic($message));
        $this->assertSame(Decision::MANUAL_RETRIES_EXHAUSTED, $this->policy->manual($message));
        $this->requeue($message, WorkflowActorSide::SYSTEM);

        // Claim 3 fails transiently: the automatic budget is used up and the
        // manual retry is gone — 3 claims in all, never a 4th on this path.
        $message = $this->failPublication($message->fresh(), NotificationFailureCode::PUBLICATION_ERROR, 3);
        $this->travel(10)->minutes();
        $this->assertSame(Decision::AUTOMATIC_ATTEMPTS_EXHAUSTED, $this->policy->automatic($message));
        $this->assertSame(Decision::MANUAL_RETRIES_EXHAUSTED, $this->policy->manual($message));
    }

    public function test_the_replaced_total_attempts_key_changes_no_decision(): void
    {
        $automatic = $this->failedAfter(2);
        $manual = $this->failedAfter(3);
        $limit = $this->failedAfter(4);
        $this->travel(10)->minutes();

        foreach ([null, '1', '3', '10', 'many'] as $legacy) {
            config(['notifications.legacy_max_publish_attempts' => $legacy]);
            $this->assertSame(4, $this->policy->claimLimit(), (string) $legacy);
            $this->assertSame(Decision::ALLOWED, $this->policy->automatic($automatic), (string) $legacy);
            $this->assertSame(Decision::ALLOWED, $this->policy->manual($manual), (string) $legacy);
            $this->assertSame(Decision::CLAIM_LIMIT_REACHED, $this->policy->manual($limit), (string) $legacy);
            $this->assertNotContains('legacy_max_publish_attempts', NotificationConfig::invalidKeys());
        }
    }

    public function test_only_staff_and_coordinator_requeues_are_manual(): void
    {
        $message = $this->failedAfter(3);
        $this->requeue($message, WorkflowActorSide::SYSTEM);
        $this->requeue($message, WorkflowActorSide::SYSTEM);
        $this->assertSame(0, $this->policy->manualRetriesUsed($message));

        $clan = $this->clan('NTF_CLAN');
        $coordinator = $this->coordinatorSender($clan);
        $this->requeue($message, WorkflowActorSide::COORDINATOR, $coordinator);
        $this->assertSame(1, $this->policy->manualRetriesUsed($message));
        $this->requeue($message, WorkflowActorSide::STAFF, $this->staff);
        $this->assertSame(2, $this->policy->manualRetriesUsed($message));
    }

    public function test_the_expiry_lead_stops_both_retries(): void
    {
        // Failed at 10:00, expires 11:05: retryable until 10:05 (one hour of lead).
        $soon = $this->failedAfter(1, input: ['expires_at' => now()->addMinutes(65)->toIso8601String()]);
        $this->travel(5)->minutes();
        $this->assertSame(Decision::ALLOWED, $this->policy->automatic($soon));
        $this->travel(1)->seconds();
        $this->assertSame(Decision::EXPIRY_TOO_SOON, $this->policy->automatic($soon));

        $manual = $this->failedAfter(3, input: ['expires_at' => now()->addMinutes(30)->toIso8601String()]);
        $this->assertSame(Decision::EXPIRY_TOO_SOON, $this->policy->manual($manual));
        $manual->expires_at = now()->subMinute();
        $this->assertSame(Decision::EXPIRY_TOO_SOON, $this->policy->manual($manual));

        $later = $this->failedAfter(3, input: ['expires_at' => now()->addHours(2)->toIso8601String()]);
        $this->assertSame(Decision::ALLOWED, $this->policy->manual($later));
    }

    public function test_invalid_configuration_allows_nothing(): void
    {
        $automatic = $this->failedAfter(1);
        $manual = $this->failedAfter(3);
        $this->travel(1)->hours();

        foreach ([['automatic_publish_attempts', 0], ['automatic_publish_attempts', 4], ['automatic_publish_attempts', 'three'],
            ['manual_publish_retries', 2], ['manual_publish_retries', '1.0'], ['manual_publish_retries', null]] as [$key, $value]) {
            $this->notificationDefaults();
            config(["notifications.{$key}" => $value]);
            $this->assertNull($this->policy->claimLimit(), $key);
            $this->assertSame(Decision::CONFIG_INVALID, $this->policy->automatic($automatic), $key);
            $this->assertSame(Decision::CONFIG_INVALID, $this->policy->manual($manual), $key);
        }

        $this->notificationDefaults();
        config(['notifications.automatic_publish_attempts' => '1', 'notifications.manual_publish_retries' => '0']);
        $this->assertSame(1, $this->policy->claimLimit());
        $this->assertSame(Decision::AUTOMATIC_ATTEMPTS_EXHAUSTED, $this->policy->automatic($automatic));
        $this->assertSame(Decision::MANUAL_RETRIES_EXHAUSTED, $this->policy->manual($manual));
    }

    public function test_deciding_writes_nothing_and_dispatches_nothing(): void
    {
        Queue::fake();
        $message = $this->failedAfter(1);
        $before = DB::table('notification_messages')->where('id', $message->id)->first();

        $writes = $this->writesDuring(function () use ($message) {
            $this->policy->automatic($message);
            $this->policy->manual($message);
            $this->policy->manualRetriesUsed($message);
        });

        $this->assertSame([], $writes);
        Queue::assertNothingPushed();
        $this->assertSame([], $this->dispatcher->dispatched);
        $this->assertEquals($before, DB::table('notification_messages')->where('id', $message->id)->first());
    }
}
