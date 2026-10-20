<?php

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\AcknowledgeNotificationAction;
use App\Actions\Notifications\RecordNotificationViewAction;
use App\Actions\Notifications\RequestPublicationAction;
use App\Enums\FamilyStatus;
use App\Enums\NotificationFollowupOutcome;
use App\Enums\NotificationFollowupStatus;
use App\Models\Family;
use App\Models\NotificationFollowup;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\Notifications\NotificationExpirySweeper;
use App\Support\Notifications\NotificationFollowupEngine;
use App\Support\Notifications\NotificationPublisher;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01E.3-F2: the follow-up engine — opening (NORMAL 72 h unviewed;
 * IMPORTANT 24 h / 72 h unacknowledged), satisfaction by the family's own
 * view / acknowledgement, expiry, the gate and cutoff, family eligibility,
 * audit atomicity and the bounded command. Driver-agnostic (also run on
 * PostgreSQL, where the CHECKs and the append-only trigger apply); the
 * multi-session races are in NotificationFollowupConcurrencyTest. Synthetic
 * data only.
 */
class NotificationFollowupEngineTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private User $staff;

    private User $head;

    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
        $this->travelTo('2026-10-19 10:00:00');
        $this->freshHeartbeat();
        config(['notifications.followups_enabled' => true, 'notifications.followups_cutoff' => '2026-10-01T00:00:00Z']);
        $this->staff = $this->staffSender();
        $activated = $this->activatedHead('123456789');
        $this->head = $activated['user'];
        $this->family = $activated['family'];
    }

    /** @return array{0: NotificationMessage, 1: NotificationRecipient} */
    private function publish(string $level = 'NORMAL', ?int $expiresInHours = null, ?Family $family = null): array
    {
        $input = ['level' => $level] + ($expiresInHours === null ? [] : ['expires_at' => now()->addHours($expiresInHours)->toIso8601String()]);
        $draft = $this->draft($this->staff, ['type' => 'STAFF_FAMILIES', 'family_ids' => [($family ?? $this->family)->id]], $input);
        app(RequestPublicationAction::class)->handle($this->staff, $draft);
        app(NotificationPublisher::class)->publish($draft->id);
        $message = $draft->fresh();

        return [$message, NotificationRecipient::query()->where('message_id', $message->id)->sole()];
    }

    /** One run with a live worker (a fresh heartbeat, as in production). */
    private function sweep(int $limit = 100): array
    {
        $this->freshHeartbeat();

        return app(NotificationFollowupEngine::class)->run($limit);
    }

    /** @return array<string, string> stage => status/outcome */
    private function stages(NotificationRecipient $recipient): array
    {
        return NotificationFollowup::query()->where('recipient_id', $recipient->id)->orderBy('stage')->get()
            ->mapWithKeys(fn (NotificationFollowup $f) => [$f->stage->value => $f->status->value.($f->outcome ? '/'.$f->outcome->value : '')])
            ->all();
    }

    private function events(string $type, ?NotificationRecipient $recipient = null): int
    {
        return NotificationRecipientEvent::query()->where('event_type', $type)
            ->when($recipient, fn ($q) => $q->where('recipient_id', $recipient->id))->count();
    }

    private function familyView(NotificationMessage $message): bool
    {
        return app(RecordNotificationViewAction::class)->handle($this->context(), $message->uuid)[1];
    }

    private function acknowledge(NotificationMessage $message): bool
    {
        return app(AcknowledgeNotificationAction::class)->handle($this->context(), $message->uuid)[1];
    }

    private function context()
    {
        return app(FamilyAccessResolver::class)->familyContext($this->head->fresh());
    }

    // ------------------------------------------------------------ deadlines

    public function test_normal_opens_first_exactly_72_hours_after_publication_and_only_once(): void
    {
        [$message, $recipient] = $this->publish('NORMAL');

        $this->travel(72 * 3600 - 1)->seconds();
        $this->assertSame(0, $this->sweep()['opened']);
        $this->travel(1)->seconds();
        $counts = $this->sweep();
        $this->assertSame([true, 1, 1], [$counts['opening_ready'], $counts['opening_scanned'], $counts['opened']]);

        $followup = NotificationFollowup::query()->where('recipient_id', $recipient->id)->sole();
        $this->assertSame(['FIRST', 'UNVIEWED', 'OPEN'], [$followup->stage->value, $followup->trigger_reason->value, $followup->status->value]);
        $this->assertSame($message->published_at->copy()->addHours(72)->toDateTimeString(), $followup->due_at->toDateTimeString());
        $event = NotificationRecipientEvent::query()->where('followup_id', $followup->id)->sole();
        $this->assertSame(['FOLLOWUP_OPENED', 'SYSTEM', null, null], [$event->event_type->value, $event->actor_side->value, $event->actor_user_id, $event->reason_code]);
        $this->assertEquals(['stage' => 'FIRST', 'trigger_reason' => 'UNVIEWED', 'level' => 'NORMAL', 'had_family_account' => true], $event->metadata, 'jsonb key order is not significant');

        // Idempotent; NORMAL never has a SECOND stage.
        $this->travel(30)->days();
        $this->assertSame(0, $this->sweep()['opened']);
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($recipient));
        $this->assertSame(1, $this->events('FOLLOWUP_OPENED'));
    }

    public function test_important_opens_first_at_24_hours_and_second_at_72_hours(): void
    {
        [$message, $recipient] = $this->publish('IMPORTANT');

        $this->travel(24 * 3600 - 1)->seconds();
        $this->sweep();
        $this->assertSame([], $this->stages($recipient));
        $this->travel(1)->seconds();
        $this->sweep();
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($recipient));

        // Viewing alone satisfies nothing for IMPORTANT.
        $this->assertTrue($this->familyView($message));
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($recipient));

        $this->travel(48 * 3600 - 1)->seconds();
        $this->sweep();
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($recipient));
        $this->travel(1)->seconds();
        $this->sweep();
        $this->assertSame(['FIRST' => 'OPEN', 'SECOND' => 'OPEN'], $this->stages($recipient));
        $second = NotificationFollowup::query()->where('recipient_id', $recipient->id)->where('stage', 'SECOND')->sole();
        $this->assertSame('UNACKNOWLEDGED', $second->trigger_reason->value);
        $this->assertSame($message->published_at->copy()->addHours(72)->toDateTimeString(), $second->due_at->toDateTimeString());
    }

    public function test_after_a_scheduler_outage_both_important_stages_open_at_once_with_publication_based_due_times(): void
    {
        [$message, $recipient] = $this->publish('IMPORTANT');

        $this->travel(100)->hours();
        $this->assertSame(2, $this->sweep()['opened']);

        $due = NotificationFollowup::query()->where('recipient_id', $recipient->id)->orderBy('stage')->pluck('due_at', 'stage')
            ->map(fn ($at) => $at->toDateTimeString())->all();
        $this->assertSame([
            'FIRST' => $message->published_at->copy()->addHours(24)->toDateTimeString(),
            'SECOND' => $message->published_at->copy()->addHours(72)->toDateTimeString(),
        ], $due);
        $this->assertSame(2, $this->events('FOLLOWUP_OPENED', $recipient));
    }

    public function test_a_manually_closed_first_never_reopens_and_never_suppresses_second(): void
    {
        [, $recipient] = $this->publish('IMPORTANT');
        $this->travel(24)->hours();
        $this->sweep();
        // A manual closure (its Domain Action is a later stage): the follow-up row only.
        NotificationFollowup::query()->where('recipient_id', $recipient->id)->sole()->forceFill([
            'status' => NotificationFollowupStatus::CLOSED, 'outcome' => NotificationFollowupOutcome::CLOSED_MANUALLY,
            'closed_at' => now(), 'closed_by_user_id' => $this->staff->id, 'close_reason_code' => 'INFORMED_BY_PHONE',
        ])->save();
        $recipientBefore = $recipient->fresh()->only(['viewed_at', 'acknowledged_at', 'acknowledged_late']);

        $this->travel(48)->hours();
        $this->sweep();
        $this->sweep();

        $this->assertSame(['FIRST' => 'CLOSED/CLOSED_MANUALLY', 'SECOND' => 'OPEN'], $this->stages($recipient));
        $this->assertSame($recipientBefore, $recipient->fresh()->only(['viewed_at', 'acknowledged_at', 'acknowledged_late']));
        $this->assertSame(2, $this->events('FOLLOWUP_OPENED', $recipient));
    }

    // ---------------------------------------------------------- satisfaction

    public function test_the_family_view_satisfies_an_open_normal_follow_up_once(): void
    {
        [$message, $recipient] = $this->publish('NORMAL');
        $this->travel(72)->hours();
        $this->sweep();

        $this->assertTrue($this->familyView($message));
        $this->assertFalse($this->familyView($message), 'a replay changes nothing');

        $this->assertSame(['FIRST' => 'CLOSED/SATISFIED'], $this->stages($recipient));
        $followup = NotificationFollowup::query()->where('recipient_id', $recipient->id)->sole();
        $this->assertNull($followup->closed_by_user_id);
        $this->assertNull($followup->close_reason_code);
        $this->assertSame($recipient->fresh()->viewed_at->toDateTimeString(), $followup->closed_at->toDateTimeString());
        $closed = NotificationRecipientEvent::query()->where('followup_id', $followup->id)->where('event_type', 'FOLLOWUP_CLOSED')->sole();
        $this->assertSame(['SYSTEM', null], [$closed->actor_side->value, $closed->actor_user_id]);
        $this->assertEquals(['stage' => 'FIRST', 'outcome' => 'SATISFIED', 'closed_by' => 'VIEWED'], $closed->metadata, 'jsonb key order is not significant');
        // One VIEWED by the family — the closure never records a view for anyone.
        $this->assertSame(1, $this->events('VIEWED'));
    }

    public function test_the_acknowledgement_satisfies_every_open_important_stage(): void
    {
        [$message, $recipient] = $this->publish('IMPORTANT');
        $this->travel(80)->hours();
        $this->sweep();
        $this->assertSame(['FIRST' => 'OPEN', 'SECOND' => 'OPEN'], $this->stages($recipient));

        $this->assertTrue($this->acknowledge($message));
        $this->assertFalse($this->acknowledge($message));

        $this->assertSame(['FIRST' => 'CLOSED/SATISFIED', 'SECOND' => 'CLOSED/SATISFIED'], $this->stages($recipient));
        $this->assertSame(2, $this->events('FOLLOWUP_CLOSED', $recipient));
        $this->assertSame([1, 1], [$this->events('VIEWED'), $this->events('ACKNOWLEDGED')]);
    }

    public function test_meeting_the_condition_before_the_deadline_opens_nothing(): void
    {
        [$normal, $normalRecipient] = $this->publish('NORMAL');
        [$important, $importantRecipient] = $this->publish('IMPORTANT');
        $this->travel(10)->hours();
        $this->familyView($normal);
        $this->acknowledge($important);

        $this->travel(100)->hours();
        $this->assertSame(0, $this->sweep()['opened']);
        $this->assertSame([[], []], [$this->stages($normalRecipient), $this->stages($importantRecipient)]);
        $this->assertSame(0, $this->events('FOLLOWUP_OPENED'));
    }

    // ---------------------------------------------------------------- expiry

    public function test_expiry_closes_open_stages_and_a_late_acknowledgement_changes_nothing(): void
    {
        [$message, $recipient] = $this->publish('IMPORTANT', expiresInHours: 48);
        $this->travel(24)->hours();
        $this->sweep();
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($recipient));

        // At expires_at, before the scheduler ran: the acknowledgement is late and satisfies nothing.
        $this->travel(24)->hours();
        $this->assertTrue($this->acknowledge($message));
        $this->assertTrue($recipient->fresh()->acknowledged_late);
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($recipient));

        $counts = $this->sweep();
        $this->assertSame([1, 1], [$counts['expiry_scanned'], $counts['expired']]);
        $this->assertSame(['FIRST' => 'CLOSED/EXPIRED'], $this->stages($recipient));
        $closed = NotificationRecipientEvent::query()->where('recipient_id', $recipient->id)->where('event_type', 'FOLLOWUP_CLOSED')->sole();
        $this->assertEquals(['stage' => 'FIRST', 'outcome' => 'EXPIRED', 'closed_by' => 'EXPIRES_AT'], $closed->metadata, 'jsonb key order is not significant');

        // No SECOND for an expired message; nothing reopens; idempotent.
        $this->travel(48)->hours();
        $counts = $this->sweep();
        $this->assertSame([0, 0], [$counts['expired'], $counts['opened']]);
        $this->assertSame(['FIRST' => 'CLOSED/EXPIRED'], $this->stages($recipient));
        $this->assertSame(1, $this->events('ACKNOWLEDGED_LATE'));
    }

    public function test_expiry_closes_follow_ups_of_archived_messages_whatever_the_gate(): void
    {
        [, $recipient] = $this->publish('NORMAL', expiresInHours: 100);
        $this->travel(72)->hours();
        $this->sweep();
        $this->travel(28)->hours();
        app(NotificationExpirySweeper::class)->run();
        config(['notifications.mode' => 'OFF', 'notifications.followups_enabled' => false, 'notifications.followups_cutoff' => null]);

        $counts = $this->sweep();
        $this->assertFalse($counts['opening_ready']);
        $this->assertSame(1, $counts['expired']);
        $this->assertSame(['FIRST' => 'CLOSED/EXPIRED'], $this->stages($recipient));
    }

    public function test_no_follow_up_ever_opens_for_a_message_that_expires_before_the_deadline(): void
    {
        [, $recipient] = $this->publish('NORMAL', expiresInHours: 50);
        $this->travel(72)->hours();
        $this->assertSame([0, 0], [$this->sweep()['opened'], $this->sweep()['expired']]);
        $this->assertSame([], $this->stages($recipient));
    }

    // --------------------------------------------------------- gate, cutoff

    public function test_opening_needs_the_mode_the_gate_a_valid_cutoff_and_a_ready_worker(): void
    {
        [, $recipient] = $this->publish('NORMAL');
        $this->travel(72)->hours();

        $closed = [
            'mode OFF' => ['notifications.mode' => 'OFF'],
            'gate off' => ['notifications.followups_enabled' => false],
            'cutoff missing' => ['notifications.followups_cutoff' => null],
            'cutoff invalid' => ['notifications.followups_cutoff' => '2026-10-01'],
            'synchronous queue' => ['queue.default' => 'sync'],
        ];
        foreach ($closed as $why => $settings) {
            $saved = array_map(fn ($key) => config($key), array_combine(array_keys($settings), array_keys($settings)));
            config($settings);
            $counts = $this->sweep();
            $this->assertSame([false, 0], [$counts['opening_ready'], $counts['opened']], $why);
            config($saved);
        }
        $this->assertSame([], $this->stages($recipient));

        // PILOT opens like ON once everything is ready (no per-sender restriction for follow-ups).
        config(['notifications.mode' => 'PILOT']);
        $this->assertSame(1, $this->sweep()['opened']);
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($recipient));
    }

    public function test_a_stale_heartbeat_closes_opening(): void
    {
        [, $recipient] = $this->publish('NORMAL');
        $this->travel(72)->hours();
        $counts = app(NotificationFollowupEngine::class)->run();
        $this->assertSame([false, 0], [$counts['opening_ready'], $counts['opened']]);
        $this->assertSame([], $this->stages($recipient));
    }

    public function test_nothing_published_before_the_cutoff_is_ever_backfilled(): void
    {
        [$atCutoff, $atRecipient] = $this->publish('NORMAL');
        [$before, $beforeRecipient] = $this->publish('NORMAL');
        DB::table('notification_messages')->where('id', $atCutoff->id)->update(['published_at' => '2026-10-19 10:00:00']);
        DB::table('notification_messages')->where('id', $before->id)->update(['published_at' => '2026-10-19 09:59:59']);
        config(['notifications.followups_cutoff' => '2026-10-19T13:00:00+03:00']);

        $this->travel(30)->days();
        $this->assertSame(1, $this->sweep()['opened']);
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($atRecipient));
        $this->assertSame([], $this->stages($beforeRecipient));
    }

    // ---------------------------------------------------- family eligibility

    public function test_an_inactive_family_gets_no_new_follow_up_but_keeps_an_open_one(): void
    {
        [, $first] = $this->publish('IMPORTANT');
        $other = $this->familyIn($this->clan());
        [, $atPublication] = $this->publish('NORMAL', family: $other);
        DB::table('notification_recipients')->where('id', $atPublication->id)->update(['family_status_at_publication' => FamilyStatus::INACTIVE->value]);

        $this->travel(24)->hours();
        $this->sweep();
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($first));

        // The family leaves ACTIVE: no SECOND; FIRST stays OPEN (never EXPIRED for inactivity).
        Family::query()->whereKey($this->family->id)->update(['status' => FamilyStatus::INACTIVE->value]);
        $this->travel(48)->hours();
        $counts = $this->sweep();
        $this->assertSame([0, 0], [$counts['opened'], $counts['expired']]);
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($first));
        // Not ACTIVE at publication: never.
        $this->assertSame([], $this->stages($atPublication));
    }

    public function test_a_family_without_an_activated_account_is_followed_up_and_marked_so(): void
    {
        $accountless = $this->familyIn($this->clan());
        [, $recipient] = $this->publish('NORMAL', family: $accountless);
        $this->travel(72)->hours();
        $this->sweep();

        $event = NotificationRecipientEvent::query()->where('recipient_id', $recipient->id)->where('event_type', 'FOLLOWUP_OPENED')->sole();
        $this->assertFalse($event->metadata['had_family_account']);
    }

    public function test_had_family_account_reflects_the_account_at_opening_and_never_decides_eligibility(): void
    {
        [, $recipient] = $this->publish('NORMAL');
        $this->assertTrue($recipient->had_family_account, 'the publication snapshot');
        // The household head's account is deactivated before the deadline.
        $this->head->forceFill(['is_active' => false])->save();
        $this->travel(72)->hours();
        $this->sweep();

        $event = NotificationRecipientEvent::query()->where('recipient_id', $recipient->id)->where('event_type', 'FOLLOWUP_OPENED')->sole();
        $this->assertFalse($event->metadata['had_family_account'], 'the state at opening, not the snapshot');
        $this->assertTrue($recipient->fresh()->had_family_account, 'the snapshot is never rewritten');
    }

    // -------------------------------------------- atomicity, bounds, command

    /** A database trigger refusing one follow-up event type (test database only). */
    private function refuseEvent(string $type, ?int $recipientId = null): void
    {
        $when = "NEW.event_type = '{$type}'".($recipientId === null ? '' : ' AND NEW.recipient_id = '.$recipientId);
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION test_refuse_followup_event() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'refused'; END; \$\$");
            DB::unprepared("CREATE TRIGGER test_refuse_followup_event BEFORE INSERT ON notification_recipient_events FOR EACH ROW WHEN ({$when}) EXECUTE FUNCTION test_refuse_followup_event()");
        } else {
            DB::unprepared("CREATE TRIGGER test_refuse_followup_event BEFORE INSERT ON notification_recipient_events WHEN {$when} BEGIN SELECT RAISE(ABORT, 'refused'); END");
        }
    }

    private function allowEvents(): void
    {
        DB::unprepared('DROP TRIGGER test_refuse_followup_event'.(DB::getDriverName() === 'pgsql' ? ' ON notification_recipient_events' : ''));
    }

    public function test_an_opening_whose_event_fails_rolls_back_and_is_retried_once(): void
    {
        [, $recipient] = $this->publish('IMPORTANT');
        $this->travel(80)->hours();
        $this->refuseEvent('FOLLOWUP_OPENED');

        $this->freshHeartbeat();
        $this->artisan('famboook:notifications-followups')->expectsOutputToContain('errors: 1')->assertExitCode(1);
        $this->assertSame([], $this->stages($recipient), 'no follow-up without its event');

        // Deferred, not retried in a tight loop; then retried once the backoff passed.
        $counts = $this->sweep();
        $this->assertSame([0, 1, 0], [$counts['opening_scanned'], $counts['opening_deferred'], $counts['errors']]);
        $this->allowEvents();
        $this->travel(NotificationFollowupEngine::RETRY_BACKOFF_SECONDS)->seconds();
        $counts = $this->sweep();
        $this->assertSame([2, 0], [$counts['opened'], $counts['opening_deferred']]);
        $this->assertSame(0, $this->sweep()['opened']);
        $this->assertSame(2, $this->events('FOLLOWUP_OPENED', $recipient));
    }

    public function test_a_failing_recipient_never_blocks_a_healthy_one_behind_it_and_is_retried_until_it_recovers(): void
    {
        [, $failing] = $this->publish('NORMAL', family: $this->familyIn($this->clan()));
        $this->travel(1)->minutes();
        [, $healthy] = $this->publish('NORMAL', family: $this->familyIn($this->clan()));
        $this->travel(72)->hours();
        $this->refuseEvent('FOLLOWUP_OPENED', $failing->id);

        // Oldest first: the failing recipient takes the only slot once — tried once, counted.
        $counts = $this->sweep(1);
        $this->assertSame([1, 0, 1], [$counts['opening_scanned'], $counts['opened'], $counts['errors']]);
        // Next run: it is deferred, so the healthy recipient behind it progresses.
        $counts = $this->sweep(1);
        $this->assertSame([1, 1, 1, 0], [$counts['opening_scanned'], $counts['opened'], $counts['opening_deferred'], $counts['errors']]);
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($healthy));
        // While deferred: neither lost nor retried.
        $counts = $this->sweep(1);
        $this->assertSame([0, 1, 0], [$counts['opening_scanned'], $counts['opening_deferred'], $counts['errors']]);

        // Still failing after the backoff: retried once, deferred again.
        $this->travel(NotificationFollowupEngine::RETRY_BACKOFF_SECONDS)->seconds();
        $this->assertSame([1, 1], [$this->sweep(1)['opening_scanned'], $this->sweep(1)['opening_deferred']]);

        // Recovered: opened at the next retry, nothing left deferred.
        $this->allowEvents();
        $this->travel(NotificationFollowupEngine::RETRY_BACKOFF_SECONDS)->seconds();
        $counts = $this->sweep(1);
        $this->assertSame([1, 0, 0], [$counts['opened'], $counts['opening_deferred'], $counts['errors']]);
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($failing));
        $this->assertSame(2, $this->events('FOLLOWUP_OPENED'));
    }

    private const OPENING_BACKOFF = 'notifications:followups:retry-backoff:opening';

    private const EXPIRY_BACKOFF = 'notifications:followups:retry-backoff:expiry';

    public function test_with_the_production_database_cache_store_a_concurrent_deferral_is_merged_too(): void
    {
        // CACHE_STORE=database (deploy/env template): the cache table and the cache_locks atomic locks.
        config(['cache.default' => 'database']);
        $this->freshHeartbeat();
        $this->test_a_concurrent_runs_deferral_is_merged_never_overwritten();
        $this->assertTrue(DB::table('cache')->where('key', 'like', '%retry-backoff:opening')->exists());
        $this->assertSame(0, DB::table('cache_locks')->count(), 'the merge lock is released');
    }

    public function test_a_concurrent_runs_deferral_is_merged_never_overwritten(): void
    {
        [, $failing] = $this->publish('NORMAL');
        $this->travel(72)->hours();
        $this->refuseEvent('FOLLOWUP_OPENED', $failing->id);
        // Another run records its deferral WHILE this run works (after this run loaded the list).
        $foreignUntil = now()->getTimestamp() + 300;
        $written = false;
        DB::listen(function ($query) use (&$written, $foreignUntil) {
            if (! $written && str_contains($query->sql, 'notification_followups')) {
                $written = true;
                Cache::put(self::OPENING_BACKOFF, [987654 => $foreignUntil], 3600);
            }
        });

        $this->assertSame(1, $this->sweep()['errors']);

        $stored = Cache::get(self::OPENING_BACKOFF);
        $this->assertTrue($written);
        $this->assertSame($foreignUntil, $stored[987654] ?? null, 'the other run\'s deferral survives');
        $this->assertSame(now()->getTimestamp() + NotificationFollowupEngine::RETRY_BACKOFF_SECONDS, $stored[$failing->id] ?? null);
    }

    public function test_nothing_is_written_without_a_failure_and_off_mode_never_touches_the_opening_state(): void
    {
        [, $recipient] = $this->publish('NORMAL', expiresInHours: 80);
        $this->travel(72)->hours();
        $this->assertSame(1, $this->sweep()['opened']);
        $this->assertFalse(Cache::has(self::OPENING_BACKOFF));
        $this->assertFalse(Cache::has(self::EXPIRY_BACKOFF));

        // OFF: the opening phase never runs — an existing opening deferral is neither read into
        // a write nor changed; expiry without failures writes nothing.
        config(['notifications.mode' => 'OFF']);
        $this->travel(8)->hours();
        $existing = [123456 => now()->getTimestamp() + 120];
        Cache::put(self::OPENING_BACKOFF, $existing, 3600);
        $counts = $this->sweep();
        $this->assertSame([false, 1, 0], [$counts['opening_ready'], $counts['expired'], $counts['opening_deferred']]);
        $this->assertSame($existing, Cache::get(self::OPENING_BACKOFF));
        $this->assertFalse(Cache::has(self::EXPIRY_BACKOFF));
        $this->assertSame(['FIRST' => 'CLOSED/EXPIRED'], $this->stages($recipient));
    }

    public function test_expired_corrupt_or_far_future_deferrals_never_suppress_a_recipient(): void
    {
        [, $recipient] = $this->publish('NORMAL');
        $this->travel(72)->hours();
        Cache::put(self::OPENING_BACKOFF, [$recipient->id => now()->getTimestamp() + 365 * 86400], 3600);
        $this->assertSame([1, 0], [$this->sweep()['opened'], 0], 'a deferral beyond one backoff period is ignored');

        [, $second] = $this->publish('NORMAL', family: $this->familyIn($this->clan()));
        $this->travel(72)->hours();
        foreach (['not an array', [$second->id => 'soon'], [$second->id => now()->getTimestamp()]] as $corrupt) {
            Cache::put(self::OPENING_BACKOFF, $corrupt, 3600);
            $this->assertSame(0, $this->sweep()['opening_deferred']);
        }
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($second));
    }

    public function test_an_unavailable_lock_only_means_an_earlier_retry(): void
    {
        [, $failing] = $this->publish('NORMAL');
        $this->travel(72)->hours();
        $this->refuseEvent('FOLLOWUP_OPENED', $failing->id);
        // Another owner holds the deferral lock: this run waits at most 5 s, then records nothing.
        $held = Cache::lock(self::OPENING_BACKOFF.':lock', 60);
        $this->assertTrue($held->get());

        $this->assertSame(1, $this->sweep()['errors']);
        $this->assertFalse(Cache::has(self::OPENING_BACKOFF));
        $held->release();
        // Not suppressed: retried by the very next run.
        $counts = $this->sweep();
        $this->assertSame([1, 0, 1], [$counts['opening_scanned'], $counts['opening_deferred'], $counts['errors']]);
    }

    public function test_the_deferral_list_is_bounded_and_keeps_the_latest_failures(): void
    {
        [, $failing] = $this->publish('NORMAL');
        $this->travel(72)->hours();
        $now = now()->getTimestamp();
        $full = [];
        foreach (range(1, NotificationFollowupEngine::RETRY_BACKOFF_MAX) as $i) {
            $full[1_000_000 + $i] = $now + 1 + ($i % 500);
        }
        Cache::put(self::OPENING_BACKOFF, $full, 3600);
        $this->refuseEvent('FOLLOWUP_OPENED', $failing->id);

        $this->assertSame(1, $this->sweep()['errors']);
        $stored = Cache::get(self::OPENING_BACKOFF);
        $this->assertCount(NotificationFollowupEngine::RETRY_BACKOFF_MAX, $stored);
        $this->assertArrayHasKey($failing->id, $stored, 'the newest failure is kept; an earliest-expiring entry is dropped (retried earlier)');
    }

    public function test_a_failing_expiry_is_deferred_too_and_retried_later(): void
    {
        [, $failing] = $this->publish('NORMAL', expiresInHours: 80, family: $this->familyIn($this->clan()));
        [, $healthy] = $this->publish('NORMAL', expiresInHours: 80, family: $this->familyIn($this->clan()));
        $this->travel(72)->hours();
        $this->sweep();
        $this->travel(8)->hours();
        $this->refuseEvent('FOLLOWUP_CLOSED', $failing->id);
        config(['notifications.followups_enabled' => false]);

        $this->assertSame(1, $this->sweep(1)['errors']);
        $counts = $this->sweep(1);
        $this->assertSame([1, 1, 0], [$counts['expired'], $counts['expiry_deferred'], $counts['errors']]);
        $this->assertSame(['FIRST' => 'CLOSED/EXPIRED'], $this->stages($healthy));
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($failing));

        $this->allowEvents();
        $this->travel(NotificationFollowupEngine::RETRY_BACKOFF_SECONDS)->seconds();
        $this->assertSame(1, $this->sweep(1)['expired']);
        $this->assertSame(['FIRST' => 'CLOSED/EXPIRED'], $this->stages($failing));
    }

    public function test_a_satisfaction_whose_event_fails_rolls_back_the_family_action(): void
    {
        [$message, $recipient] = $this->publish('NORMAL');
        $this->travel(72)->hours();
        $this->sweep();
        $this->refuseEvent('FOLLOWUP_CLOSED');

        try {
            $this->familyView($message);
            $this->fail('the view must fail with its follow-up closure');
        } catch (QueryException) {
            // expected: one transaction
        }
        $this->assertNull($recipient->fresh()->viewed_at);
        $this->assertSame(0, $this->events('VIEWED'));
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($recipient));

        $this->allowEvents();
        $this->assertTrue($this->familyView($message));
        $this->assertSame(['FIRST' => 'CLOSED/SATISFIED'], $this->stages($recipient));
    }

    public function test_a_run_is_bounded_and_the_rest_waits_for_the_next(): void
    {
        $recipients = [];
        foreach (range(1, 3) as $ignored) {
            $recipients[] = $this->publish('NORMAL', family: $this->familyIn($this->clan()))[1];
        }
        $this->travel(72)->hours();

        $this->assertSame([1, 1], [$this->sweep(1)['opening_scanned'], NotificationFollowup::query()->count()]);
        $this->assertSame(1, $this->sweep(1)['opened']);
        $this->assertSame(1, $this->sweep(1)['opened']);
        $this->assertSame(0, $this->sweep(1)['opening_scanned']);
        foreach ($recipients as $recipient) {
            $this->assertSame(['FIRST' => 'OPEN'], $this->stages($recipient));
        }
    }

    public function test_the_command_validates_its_limit_and_prints_counters_only(): void
    {
        foreach (['0', '1001', 'x', '-1'] as $bad) {
            $this->artisan('famboook:notifications-followups', ['--limit' => $bad])->assertExitCode(2);
        }
        $this->publish('NORMAL');
        $this->travel(72)->hours();
        $this->freshHeartbeat();

        $this->artisan('famboook:notifications-followups', ['--limit' => '10'])
            ->expectsOutputToContain('opening: READY')
            ->expectsOutputToContain('opened: 1')
            ->expectsOutputToContain('expired: 0')
            ->expectsOutputToContain('errors: 0')
            ->assertExitCode(0);
        config(['notifications.followups_enabled' => false]);
        $this->artisan('famboook:notifications-followups')->expectsOutputToContain('opening: CLOSED')->assertExitCode(0);
    }

    public function test_it_is_scheduled_every_minute_without_overlapping_on_one_server(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->command, 'famboook:notifications-followups'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
    }

    public function test_the_health_check_reports_follow_up_counts_without_warning(): void
    {
        [, $normal] = $this->publish('NORMAL', expiresInHours: 80);
        $this->publish('IMPORTANT', family: $this->familyIn($this->clan()));
        $this->travel(72)->hours();
        $this->freshHeartbeat();
        config(['notifications.followups_enabled' => false]);

        // A backlog while opening is not ready is expected: reported, never a warning.
        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('open_followups: 0')
            ->expectsOutputToContain('expired_open: 0')
            ->expectsOutputToContain('due_unopened: 3 (expected while opening is not ready: FOLLOWUPS_DISABLED)')
            ->doesntExpectOutputToContain('WARN')
            ->assertExitCode(0);

        config(['notifications.followups_enabled' => true]);
        $this->sweep();
        $this->travel(8)->hours();
        $this->freshHeartbeat();
        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('open_followups: 3')
            ->expectsOutputToContain('expired_open: 1')
            ->expectsOutputToContain('due_unopened: 0 (opening ready: opened by famboook:notifications-followups)')
            ->doesntExpectOutputToContain('WARN')
            ->assertExitCode(0);
        $this->assertSame(['FIRST' => 'OPEN'], $this->stages($normal));

        // Gate on without a cutoff: the existing F1 warning (exit 1) — and it never
        // leaks into the next run of the same command instance.
        config(['notifications.followups_cutoff' => null]);
        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('due_unopened: not applicable (follow-up cutoff not set or invalid)')
            ->expectsOutputToContain('WARN: Follow-ups are enabled but not ready: FOLLOWUPS_CUTOFF_MISSING.')
            ->assertExitCode(1);

        // Mode OFF (the release default): counts only, exit 0.
        config(['notifications.mode' => 'OFF', 'notifications.followups_enabled' => false, 'notifications.followups_cutoff' => '2026-10-01T00:00:00Z']);
        $this->artisan('famboook:notifications-check')
            ->expectsOutputToContain('due_unopened: 0 (expected while opening is not ready: MODE_OFF, FOLLOWUPS_DISABLED)')
            ->doesntExpectOutputToContain('WARN')
            ->assertExitCode(0);
    }
}
