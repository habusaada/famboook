<?php

namespace App\Support\Notifications;

use App\Enums\FamilyStatus;
use App\Enums\NotificationEventType;
use App\Enums\NotificationFollowupOutcome;
use App\Enums\NotificationFollowupReason;
use App\Enums\NotificationFollowupStage;
use App\Enums\NotificationFollowupStatus;
use App\Enums\NotificationLevel;
use App\Enums\NotificationStatus;
use App\Enums\WorkflowActorSide;
use App\Models\Family;
use App\Models\NotificationFollowup;
use App\Models\NotificationMessage;
use App\Models\NotificationRecipient;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The notification follow-up engine (ADR FB-NOTIFY-01 ADR-05/06/07, §2c;
 * FB-NOTIFY-01E.3-F2). Three transitions, nothing else:
 *
 *   OPEN       a due stage of a family-level recipient that did not meet its
 *              level's condition (run(), opening phase — gated);
 *   SATISFIED  the family's own view (NORMAL) or acknowledgement (IMPORTANT,
 *              every OPEN stage) before expires_at — satisfyOn*(), called by
 *              the family Domain Actions inside their transaction;
 *   EXPIRED    every OPEN stage once the message reached expires_at (or is
 *              ARCHIVED) — run(), expiry phase, whatever the gate.
 *
 * Stages (due_at derived from published_at, never from the run time):
 *   NORMAL     FIRST  UNVIEWED        published_at + 72 h, if not viewed
 *   IMPORTANT  FIRST  UNACKNOWLEDGED  published_at + 24 h, if not acknowledged
 *              SECOND UNACKNOWLEDGED  published_at + 72 h, if not acknowledged
 * FIRST and SECOND are independent: a closed FIRST (whatever its outcome)
 * neither reopens nor suppresses SECOND; after an outage both open at once.
 *
 * Opening also requires: the follow-up gate ready (NotificationReadiness::
 * forFollowups() — mode not OFF, gate on, valid cutoff, worker ready); the
 * message PUBLISHED, published at or after the cutoff (no backfill) and not
 * expired; the family ACTIVE at publication AND now. Never a second row per
 * (recipient, stage) — the unique index is the last guard — and never a
 * reopened row. Family inactivity never EXPIRES a follow-up (ADR §2c).
 *
 * LOCK ORDER (every path, PostgreSQL):
 *   1. the notification_recipients row FOR UPDATE (the family actions
 *      already hold it; the scheduler takes it with SKIP LOCKED and skips a
 *      busy recipient until the next run);
 *   2. that recipient's notification_followups rows FOR UPDATE, by id;
 *   3. inserts only: the follow-up row (FK KEY SHARE on the recipient, held)
 *      and its event (FK KEY SHARE on the message, the recipient and the
 *      follow-up). The message row is never locked here: the expiry sweep
 *      and the publication worker lock messages, and neither then locks a
 *      recipient, so no cycle can form; at worst an event insert waits for
 *      a sweep's short message transaction (bounded by lock_timeout).
 * Never a follow-up first and the recipient after. Scheduler transactions set
 * lock_timeout 5 s and statement_timeout 45 s locally; each recipient is its
 * own short transaction, so an interrupted run loses nothing and the next run
 * resumes. Errors are counted and logged (codes only), never swallowed.
 *
 * Fairness: each candidate is tried at most once per run; one that FAILED is
 * deferred for RETRY_BACKOFF_SECONDS (excluded from the candidate queries
 * BEFORE the limit, so a healthy recipient behind it progresses), then
 * retried. The deferral list lives in the shared cache, per phase, bounded to
 * RETRY_BACKOFF_MAX ids; a run merges only ITS new failures into it under an
 * atomic cache lock (concurrent runs never overwrite each other) and writes
 * nothing when nothing failed; losing it only means an earlier retry.
 */
final class NotificationFollowupEngine
{
    public const DEFAULT_LIMIT = 100;

    /**
     * level => [stage, trigger reason, hours after published_at].
     *
     * @var array<string, list<array{0: NotificationFollowupStage, 1: NotificationFollowupReason, 2: int}>>
     */
    private const STAGES = [
        'NORMAL' => [[NotificationFollowupStage::FIRST, NotificationFollowupReason::UNVIEWED, 72]],
        'IMPORTANT' => [
            [NotificationFollowupStage::FIRST, NotificationFollowupReason::UNACKNOWLEDGED, 24],
            [NotificationFollowupStage::SECOND, NotificationFollowupReason::UNACKNOWLEDGED, 72],
        ],
    ];

    /**
     * Fairness (FB-NOTIFY-01E.3-F2 hardening): a recipient whose step FAILED
     * is deferred for this long, so it cannot hold the head of the
     * oldest-first batch run after run; then it is retried. Never deleted,
     * never excluded for good.
     */
    public const RETRY_BACKOFF_SECONDS = 600;

    /** At most this many deferred recipients are remembered per phase (bounded state; an overflow is only retried earlier). */
    public const RETRY_BACKOFF_MAX = 1000;

    private const BACKOFF_KEY = 'notifications:followups:retry-backoff:';

    public function __construct(private readonly NotificationEventRecorder $events) {}

    /**
     * One bounded run: expiry first (always), then opening (only when the
     * gate is ready). At most $limit recipients per phase, each tried at most
     * ONCE per run; a recipient that failed recently is deferred (counted in
     * *_deferred) and retried after RETRY_BACKOFF_SECONDS.
     *
     * @return array{opening_ready: bool, expiry_scanned: int, expired: int, expiry_skipped: int, expiry_deferred: int,
     *     opening_scanned: int, opened: int, opening_skipped: int, opening_deferred: int, errors: int}
     */
    public function run(int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(0, $limit);
        $counts = ['opening_ready' => false, 'expiry_scanned' => 0, 'expired' => 0, 'expiry_skipped' => 0, 'expiry_deferred' => 0,
            'opening_scanned' => 0, 'opened' => 0, 'opening_skipped' => 0, 'opening_deferred' => 0, 'errors' => 0];

        $backoff = $this->loadBackoff('expiry');
        $counts['expiry_deferred'] = count($backoff);
        $failures = [];
        foreach ($this->expiryCandidates(CarbonImmutable::now(), $limit, array_keys($backoff)) as $recipientId) {
            $counts['expiry_scanned']++;
            $this->step($counts, $failures, 'expiry', (int) $recipientId, function (int $id) use (&$counts) {
                $closed = $this->expire($id);
                if ($closed === null) {
                    $counts['expiry_skipped']++;
                } else {
                    $counts['expired'] += $closed;
                }
            });
        }
        $this->recordFailures('expiry', $failures);

        $cutoff = NotificationConfig::followupsCutoff();
        $counts['opening_ready'] = NotificationReadiness::forFollowups() === [] && $cutoff !== null;
        if ($counts['opening_ready']) {
            $backoff = $this->loadBackoff('opening');
            $counts['opening_deferred'] = count($backoff);
            $failures = [];
            foreach ($this->openingCandidates(CarbonImmutable::now(), $cutoff, $limit, array_keys($backoff)) as $recipientId) {
                $counts['opening_scanned']++;
                $this->step($counts, $failures, 'opening', (int) $recipientId, function (int $id) use (&$counts, $cutoff) {
                    $opened = $this->open($id, $cutoff);
                    if ($opened === null) {
                        $counts['opening_skipped']++;
                    } else {
                        $counts['opened'] += $opened;
                    }
                });
            }
            $this->recordFailures('opening', $failures);
        }

        $work = $counts['expiry_scanned'] + $counts['opening_scanned'];
        if ($work > 0) {
            Log::info('Notification follow-ups.', $counts);
        }

        return $counts;
    }

    /**
     * NORMAL: the family's FIRST view satisfies an OPEN FIRST (UNVIEWED)
     * stage. IMPORTANT: viewing satisfies nothing. Called by
     * RecordNotificationViewAction (and by the acknowledgement's implied
     * view) with the recipient row ALREADY locked in its transaction.
     *
     * @return int follow-ups closed as SATISFIED
     */
    public function satisfyOnView(NotificationRecipient $locked, NotificationMessage $message, DateTimeInterface $at): int
    {
        if ($message->level !== NotificationLevel::NORMAL) {
            return 0;
        }

        return $this->satisfy($locked, $message, $at, NotificationFollowupReason::UNVIEWED, 'VIEWED');
    }

    /**
     * IMPORTANT: the family's acknowledgement satisfies EVERY OPEN stage —
     * before expires_at only (a late acknowledgement changes no follow-up;
     * expiry wins). Called by AcknowledgeNotificationAction with the
     * recipient row ALREADY locked in its transaction.
     *
     * @return int follow-ups closed as SATISFIED
     */
    public function satisfyOnAcknowledgement(NotificationRecipient $locked, NotificationMessage $message, DateTimeInterface $at): int
    {
        if ($message->level !== NotificationLevel::IMPORTANT) {
            return 0;
        }

        return $this->satisfy($locked, $message, $at, NotificationFollowupReason::UNACKNOWLEDGED, 'ACKNOWLEDGED');
    }

    /**
     * Recipients with a stage due now and not opened — the opening phase's
     * candidates (and the health check's "due but not opened" count). The
     * same rules open() re-checks under the lock.
     *
     * @return list<QueryBuilder> one query per (level, stage), selecting r.id
     */
    public function dueQueries(CarbonImmutable $now, CarbonImmutable $cutoff): array
    {
        $queries = [];
        foreach (self::STAGES as $level => $stages) {
            foreach ($stages as [$stage, $reason, $hours]) {
                $queries[] = DB::table('notification_recipients as r')
                    ->join('notification_messages as m', 'm.id', '=', 'r.message_id')
                    ->join('families as f', 'f.id', '=', 'r.family_id')
                    ->where('m.status', NotificationStatus::PUBLISHED->value)
                    ->where('m.level', $level)
                    ->where('m.published_at', '>=', $cutoff)
                    ->where('m.published_at', '<=', $now->subHours($hours))
                    ->where(fn (QueryBuilder $q) => $q->whereNull('m.expires_at')->orWhere('m.expires_at', '>', $now))
                    ->where('r.family_status_at_publication', FamilyStatus::ACTIVE->value)
                    ->where('f.status', FamilyStatus::ACTIVE->value)
                    ->whereNull('f.deleted_at')
                    ->whereNull($reason === NotificationFollowupReason::UNVIEWED ? 'r.viewed_at' : 'r.acknowledged_at')
                    ->whereNotExists(fn (QueryBuilder $x) => $x->selectRaw('1')
                        ->from('notification_followups as nf')
                        ->whereColumn('nf.recipient_id', 'r.id')
                        ->where('nf.stage', $stage->value))
                    ->select('r.id', 'm.published_at');
            }
        }

        return $queries;
    }

    /** OPEN follow-ups whose message reached expires_at (or is ARCHIVED): awaiting EXPIRED closure. */
    public function expiredOpenQuery(CarbonImmutable $now): QueryBuilder
    {
        return DB::table('notification_followups as nf')
            ->join('notification_recipients as r', 'r.id', '=', 'nf.recipient_id')
            ->join('notification_messages as m', 'm.id', '=', 'r.message_id')
            ->where('nf.status', NotificationFollowupStatus::OPEN->value)
            ->where(fn (QueryBuilder $q) => $q
                ->where(fn (QueryBuilder $e) => $e->whereNotNull('m.expires_at')->where('m.expires_at', '<=', $now))
                ->orWhere('m.status', NotificationStatus::ARCHIVED->value));
    }

    // ------------------------------------------------------------------ phases

    /**
     * @param  list<int>  $deferred  recipients in retry backoff (at most RETRY_BACKOFF_MAX)
     * @return list<int> at most $limit recipient ids, oldest publication first
     */
    private function openingCandidates(CarbonImmutable $now, CarbonImmutable $cutoff, int $limit, array $deferred): array
    {
        if ($limit === 0) {
            return [];
        }
        $ids = [];
        foreach ($this->dueQueries($now, $cutoff) as $query) {
            $query->when($deferred !== [], fn (QueryBuilder $q) => $q->whereNotIn('r.id', $deferred));
            foreach ($query->orderBy('m.published_at')->orderBy('r.id')->limit($limit)->get() as $row) {
                $ids[(int) $row->id] ??= (string) $row->published_at;
            }
        }
        // Several stages of one recipient are opened in ONE step; oldest first, bounded.
        uksort($ids, fn (int $a, int $b) => [$ids[$a], $a] <=> [$ids[$b], $b]);

        return array_slice(array_keys($ids), 0, $limit);
    }

    /**
     * @param  list<int>  $deferred  recipients in retry backoff (at most RETRY_BACKOFF_MAX)
     * @return Collection<int, int> at most $limit recipient ids
     */
    private function expiryCandidates(CarbonImmutable $now, int $limit, array $deferred): Collection
    {
        return $this->expiredOpenQuery($now)
            ->when($deferred !== [], fn (QueryBuilder $q) => $q->whereNotIn('nf.recipient_id', $deferred))
            ->select('nf.recipient_id')->distinct()
            ->orderBy('nf.recipient_id')
            ->limit($limit)
            ->pluck('nf.recipient_id');
    }

    /** @return int|null follow-ups opened, or NULL when the recipient was busy or no longer eligible */
    private function open(int $recipientId, CarbonImmutable $cutoff): ?int
    {
        return DB::transaction(function () use ($recipientId, $cutoff) {
            NotificationLifecycle::limitTransaction();
            $recipient = $this->lockOrSkip($recipientId);
            if ($recipient === null) {
                return null;
            }
            $now = CarbonImmutable::now();
            $message = NotificationMessage::query()->find($recipient->message_id);
            if ($message === null || ! $this->openable($recipient, $message, $cutoff, $now)) {
                return null;
            }
            $existing = $this->lockFollowups($recipient)->keyBy(fn (NotificationFollowup $f) => $f->stage->value);

            $opened = 0;
            $hasAccount = null;
            foreach (self::STAGES[$message->level->value] as [$stage, $reason, $hours]) {
                $dueAt = CarbonImmutable::instance($message->published_at)->addHours($hours);
                $unmet = $reason === NotificationFollowupReason::UNVIEWED ? $recipient->viewed_at === null : $recipient->acknowledged_at === null;
                if ($existing->has($stage->value) || ! $unmet || $now->lt($dueAt)) {
                    continue;
                }
                $followup = (new NotificationFollowup)->forceFill([
                    'recipient_id' => $recipient->getKey(),
                    'stage' => $stage,
                    'trigger_reason' => $reason,
                    'due_at' => $dueAt,
                    'status' => NotificationFollowupStatus::OPEN,
                ]);
                $followup->save();
                $this->events->followup($followup, NotificationEventType::FOLLOWUP_OPENED, WorkflowActorSide::SYSTEM, metadata: [
                    'stage' => $stage->value,
                    'trigger_reason' => $reason->value,
                    'level' => $message->level->value,
                    // Diagnostic only: the account state NOW, at opening (not the
                    // publication snapshot). Eligibility never depends on it.
                    'had_family_account' => $hasAccount ??= $this->hasActivatedAccount((int) $recipient->family_id),
                ]);
                $opened++;
            }

            return $opened;
        });
    }

    /** @return int|null follow-ups closed as EXPIRED, or NULL when the recipient was busy or the message is not expired */
    private function expire(int $recipientId): ?int
    {
        return DB::transaction(function () use ($recipientId) {
            NotificationLifecycle::limitTransaction();
            $recipient = $this->lockOrSkip($recipientId);
            if ($recipient === null) {
                return null;
            }
            $now = CarbonImmutable::now();
            $message = NotificationMessage::query()->find($recipient->message_id);
            if ($message === null || ! $this->expired($message, $now)) {
                return null;
            }

            $closed = 0;
            foreach ($this->lockFollowups($recipient, open: true) as $followup) {
                $this->close($followup, NotificationFollowupOutcome::EXPIRED, $now, ['closed_by' => 'EXPIRES_AT']);
                $closed++;
            }

            return $closed;
        });
    }

    private function satisfy(NotificationRecipient $locked, NotificationMessage $message, DateTimeInterface $at, NotificationFollowupReason $reason, string $by): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Follow-ups are satisfied only inside the family action transaction.');
        }
        // Expiry wins at or after expires_at: the scheduler closes them EXPIRED.
        if ($this->expired($message, CarbonImmutable::instance($at))) {
            return 0;
        }

        $closed = 0;
        foreach ($this->lockFollowups($locked, open: true) as $followup) {
            if ($followup->trigger_reason === $reason) {
                $this->close($followup, NotificationFollowupOutcome::SATISFIED, CarbonImmutable::instance($at), ['closed_by' => $by]);
                $closed++;
            }
        }

        return $closed;
    }

    // ----------------------------------------------------------------- helpers

    /** A system closure: no user, no reason code (chk_nf_manual); one FOLLOWUP_CLOSED event. */
    private function close(NotificationFollowup $followup, NotificationFollowupOutcome $outcome, CarbonImmutable $at, array $metadata): void
    {
        $followup->forceFill(['status' => NotificationFollowupStatus::CLOSED, 'outcome' => $outcome, 'closed_at' => $at])->save();
        $this->events->followup($followup, NotificationEventType::FOLLOWUP_CLOSED, WorkflowActorSide::SYSTEM, metadata: [
            'stage' => $followup->stage->value,
            'outcome' => $outcome->value,
            ...$metadata,
        ]);
    }

    private function openable(NotificationRecipient $recipient, NotificationMessage $message, CarbonImmutable $cutoff, CarbonImmutable $now): bool
    {
        return $message->status === NotificationStatus::PUBLISHED
            && $message->published_at !== null
            && $message->published_at->getTimestamp() >= $cutoff->getTimestamp()
            && ! $this->expired($message, $now)
            && $recipient->family_status_at_publication === FamilyStatus::ACTIVE
            && Family::query()->whereKey($recipient->family_id)->where('status', FamilyStatus::ACTIVE->value)->exists();
    }

    /** The publication snapshot's definition (NotificationRecipientSnapshot::activatedAccount), evaluated now. */
    private function hasActivatedAccount(int $familyId): bool
    {
        return DB::table('families')->where('families.id', $familyId)
            ->whereExists(NotificationRecipientSnapshot::activatedAccount())
            ->exists();
    }

    private function expired(NotificationMessage $message, CarbonImmutable $now): bool
    {
        return $message->status === NotificationStatus::ARCHIVED
            || ($message->expires_at !== null && $now->getTimestamp() >= $message->expires_at->getTimestamp());
    }

    /** The recipient row FOR UPDATE SKIP LOCKED (PostgreSQL), or NULL when another session holds it. */
    private function lockOrSkip(int $recipientId): ?NotificationRecipient
    {
        return NotificationRecipient::query()->whereKey($recipientId)
            ->lock(DB::getDriverName() === 'pgsql' ? 'for update skip locked' : true)
            ->first();
    }

    /**
     * Lock-order step 2: the recipient's follow-ups FOR UPDATE, by id — only
     * ever after the recipient row.
     *
     * @return Collection<int, NotificationFollowup>
     */
    private function lockFollowups(NotificationRecipient $recipient, bool $open = false): Collection
    {
        return NotificationFollowup::query()
            ->where('recipient_id', $recipient->getKey())
            ->when($open, fn ($q) => $q->where('status', NotificationFollowupStatus::OPEN->value))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * One recipient, tried once: a failure is counted, logged (codes only)
     * and collected for deferral. A success needs no write: an old deferral
     * has already expired (deferred recipients are not candidates).
     *
     * @param  array<string, int|bool>  $counts
     * @param  array<int, int>  $failures  this run's failures: recipient id => retry-not-before (unix time)
     */
    private function step(array &$counts, array &$failures, string $phase, int $recipientId, callable $work): void
    {
        try {
            $work($recipientId);
        } catch (Throwable $e) {
            $counts['errors']++;
            $failures[$recipientId] = CarbonImmutable::now()->getTimestamp() + self::RETRY_BACKOFF_SECONDS;
            Log::warning('Notification follow-up step failed.', [
                'phase' => $phase, 'recipient_id' => $recipientId, 'code' => 'FOLLOWUP_STEP_ERROR', 'exception' => $e::class,
                'retry_after_seconds' => self::RETRY_BACKOFF_SECONDS,
            ]);
        }
    }

    /**
     * The phase's deferred recipients still in backoff. Expired entries are
     * dropped, and so is any entry further ahead than one backoff period (a
     * corrupt or foreign value can never defer a recipient longer). An
     * unreadable cache means no deferral — the pre-backoff behaviour — never
     * a suppressed recipient.
     *
     * @return array<int, int> recipient id => retry-not-before (unix time)
     */
    private function loadBackoff(string $phase): array
    {
        try {
            $stored = Cache::get(self::BACKOFF_KEY.$phase, []);
        } catch (Throwable $e) {
            Log::warning('Notification follow-up backoff unavailable.', ['code' => 'FOLLOWUP_BACKOFF_UNAVAILABLE', 'exception' => $e::class]);

            return [];
        }

        return $this->valid($stored);
    }

    /**
     * Merges THIS run's failures into the CURRENT stored deferrals, under an
     * atomic cache lock (the shared database store's cache_locks in
     * production): a concurrent run's deferrals are re-read, never
     * overwritten. Nothing is written when nothing failed. Bounded to the
     * RETRY_BACKOFF_MAX latest deferrals — a dropped one is only retried
     * earlier. If the lock or the cache fails, this run's deferrals are not
     * recorded: those recipients are retried earlier, never suppressed.
     *
     * @param  array<int, int>  $failures
     */
    private function recordFailures(string $phase, array $failures): void
    {
        if ($failures === []) {
            return;
        }
        $key = self::BACKOFF_KEY.$phase;
        try {
            // Bounded by ATTEMPTS, not by the clock (Lock::block() measures with
            // now(), which a frozen test clock never advances): ~5 s at most.
            $lock = Cache::lock($key.':lock', 10);
            for ($attempt = 0; ! $lock->get(); $attempt++) {
                if ($attempt >= 50) {
                    Log::warning('Notification follow-up backoff busy.', ['code' => 'FOLLOWUP_BACKOFF_BUSY', 'deferrals' => count($failures)]);

                    return;
                }
                usleep(100_000);
            }
            try {
                $merged = array_replace($this->valid(Cache::get($key, [])), $failures);
                arsort($merged);
                Cache::put($key, array_slice($merged, 0, self::RETRY_BACKOFF_MAX, preserve_keys: true), self::RETRY_BACKOFF_SECONDS + 60);
            } finally {
                $lock->release();
            }
        } catch (Throwable $e) {
            Log::warning('Notification follow-up backoff unavailable.', ['code' => 'FOLLOWUP_BACKOFF_UNAVAILABLE', 'exception' => $e::class]);
        }
    }

    /** @return array<int, int> the entries still in backoff, none further ahead than one backoff period */
    private function valid(mixed $stored): array
    {
        $now = CarbonImmutable::now()->getTimestamp();

        return is_array($stored)
            ? array_filter($stored, fn ($until, $id) => is_int($id) && is_int($until)
                && $until > $now && $until <= $now + self::RETRY_BACKOFF_SECONDS, ARRAY_FILTER_USE_BOTH)
            : [];
    }
}
