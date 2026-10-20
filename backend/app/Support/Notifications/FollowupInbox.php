<?php

namespace App\Support\Notifications;

use App\Enums\CoordinatorScopeType;
use App\Enums\NotificationEventType;
use App\Enums\NotificationFollowupStatus;
use App\Enums\NotificationStatus;
use App\Models\CoordinatorScopeAssignment;
use App\Models\NotificationFollowup;
use App\Models\NotificationRecipientEvent;
use App\Models\User;
use App\Support\FamilyAuth\CoordinatorAccessResult;
use App\Support\FamilyAuth\CoordinatorScopes;
use App\Support\FamilyAuth\FamilyAccessResolver;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The follow-up read model of the F3.2 API (ADR FB-NOTIFY-01 §2f). READ-ONLY.
 * Every filter, ordering and count is SQL; nothing is loaded and filtered in
 * PHP, and no row is resolved one by one.
 *
 * Staff see every follow-up. A coordinator sees the follow-ups of the
 * families in their CURRENT effective scope (CoordinatorScopes::families —
 * ACTIVE families with active structure); anything else is not found.
 *
 * "Mine" (the coordinator is the family's PRIMARY coordinator) is set-based
 * and mirrors FollowupOwnership exactly: the coordinator C is primary for a
 * family F iff C is an eligible owner (household-head context, COORDINATOR,
 * coordinator-space.access, coordinator-notification-followup.manage, F not
 * C's own household) and SOME non-revoked assignment of C covering F is
 * outranked (BRANCH > BRANCH_GROUP > CLAN, then earliest assigned_at, then
 * lowest id) by NO non-revoked covering assignment of another ELIGIBLE
 * coordinator whose own household F is not. (A covering assignment of C that
 * nothing outranks exists iff C's best one is outranked by nothing.) The
 * eligible other coordinators are evaluated in PHP once per request — the
 * users with an assignment in C's clans, bounded by the coordinator count,
 * never by the number of follow-ups — and passed into the query.
 */
final class FollowupInbox
{
    public const STAFF_MAX_PER_PAGE = 50;

    public const COORDINATOR_MAX_PER_PAGE = 25;

    public function __construct(
        private readonly CoordinatorScopes $scopes,
        private readonly FamilyAccessResolver $resolver,
    ) {}

    /** @param  array<string, mixed>  $filters  validated */
    public function staff(array $filters): Builder
    {
        return $this->filtered($this->base(), $filters);
    }

    /** @param  array<string, mixed>  $filters  validated */
    public function coordinator(CoordinatorAccessResult $context, array $filters): Builder
    {
        $primary = $this->primaryCondition($context);
        $query = $this->filtered($this->inScope($this->base(), $context), $filters);
        $query->addSelect(['is_primary' => $primary === null ? DB::query()->selectRaw('NULL') : tap(DB::query(), $primary)->limit(1)]);
        if (($filters['ownership'] ?? 'all') === 'mine') {
            $primary === null ? $query->whereRaw('1 = 0') : $query->whereExists($primary);
        }

        return $query;
    }

    /** NULL when it does not exist — or is not even a uuid (never a database cast error). */
    public function staffFollowup(string $uuid): ?NotificationFollowup
    {
        return Str::isUuid($uuid) ? $this->base()->where('notification_followups.uuid', strtolower($uuid))->first() : null;
    }

    /** NULL when it does not exist, is not a uuid OR is outside the CURRENT scope — the same answer. */
    public function coordinatorFollowup(CoordinatorAccessResult $context, string $uuid): ?NotificationFollowup
    {
        return Str::isUuid($uuid)
            ? $this->inScope($this->base(), $context)->where('notification_followups.uuid', strtolower($uuid))->first()
            : null;
    }

    /** @return array<string, mixed> counts only */
    public function staffSummary(): array
    {
        return $this->summary(fn () => $this->base(), null);
    }

    /** @return array<string, mixed> counts only, over the CURRENT scope */
    public function coordinatorSummary(CoordinatorAccessResult $context): array
    {
        $primary = $this->primaryCondition($context);

        return $this->summary(fn () => $this->inScope($this->base(), $context), $primary ?? fn (QueryBuilder $q) => $q->selectRaw('1')->whereRaw('1 = 0'));
    }

    /**
     * The follow-up's history, oldest first. Notes are returned only when
     * $withNotes (the caller decides who may read them); a note corrected by a
     * NOTE_CORRECTION is never returned.
     *
     * @return Collection<int, NotificationRecipientEvent>
     */
    public function history(NotificationFollowup $followup): Collection
    {
        return NotificationRecipientEvent::query()
            ->leftJoin('users as actor', 'actor.id', '=', 'notification_recipient_events.actor_user_id')
            ->where('notification_recipient_events.followup_id', $followup->getKey())
            ->select('notification_recipient_events.*', 'actor.name as actor_name')
            ->selectRaw('EXISTS (SELECT 1 FROM notification_recipient_events c WHERE c.corrects_event_id = notification_recipient_events.id) AS note_corrected')
            ->orderBy('notification_recipient_events.id')
            ->get();
    }

    // ---------------------------------------------------------------- queries

    private function base(): Builder
    {
        $head = DB::query()->select('hp.full_name')
            ->from('family_memberships as hm')
            ->join('persons as hp', 'hp.id', '=', 'hm.person_id')
            ->whereColumn('hm.family_id', 'fam.id')
            ->where('hm.is_active', true)
            ->where('hm.is_household_head', true)
            ->limit(1);

        return NotificationFollowup::query()
            ->join('notification_recipients as r', 'r.id', '=', 'notification_followups.recipient_id')
            ->join('notification_messages as m', 'm.id', '=', 'r.message_id')
            ->join('families as fam', 'fam.id', '=', 'r.family_id')
            ->select('notification_followups.*')
            ->addSelect([
                'm.notice_code as message_notice_code', 'm.level as message_level', 'm.title as message_title',
                'm.status as message_status', 'm.expires_at as message_expires_at',
                'fam.family_code', 'r.family_id as family_id',
                'r.viewed_at as recipient_viewed_at', 'r.acknowledged_at as recipient_acknowledged_at',
                'r.acknowledged_late as recipient_acknowledged_late', 'r.had_family_account as recipient_had_family_account',
            ])
            ->selectSub($head, 'head_name');
    }

    private function inScope(Builder $query, CoordinatorAccessResult $context): Builder
    {
        return $query->whereIn('r.family_id', $this->scopes->families($context)->select('families.id')->toBase());
    }

    /** @param  array<string, mixed>  $filters */
    private function filtered(Builder $query, array $filters): Builder
    {
        $status = $filters['status'] ?? NotificationFollowupStatus::OPEN->value;
        $query->where('notification_followups.status', $status)
            ->when($filters['stage'] ?? null, fn ($q, $v) => $q->where('notification_followups.stage', $v))
            ->when($filters['level'] ?? null, fn ($q, $v) => $q->where('m.level', $v))
            ->when($filters['outcome'] ?? null, fn ($q, $v) => $q->where('notification_followups.outcome', $v))
            ->when($filters['family_code'] ?? null, fn ($q, $v) => $q->where('fam.family_code', $v))
            ->when($filters['due_before'] ?? null, fn ($q, $v) => $q->where('notification_followups.due_at', '<=', CarbonImmutable::parse($v)->utc()));

        return $status === NotificationFollowupStatus::OPEN->value
            ? $query->orderBy('notification_followups.due_at')->orderBy('notification_followups.id')
            : $query->orderByDesc('notification_followups.closed_at')->orderByDesc('notification_followups.id');
    }

    /**
     * @param  Closure(): Builder  $base
     * @param  (Closure(QueryBuilder): mixed)|null  $primary
     * @return array<string, mixed>
     */
    private function summary(Closure $base, ?Closure $primary): array
    {
        $open = NotificationFollowupStatus::OPEN->value;
        $byStage = $base()->toBase()->where('notification_followups.status', $open)->reorder()
            ->select(DB::raw('notification_followups.stage AS k'), DB::raw('count(*) AS n'))->groupBy('notification_followups.stage')->pluck('n', 'k');
        $byLevel = $base()->toBase()->where('notification_followups.status', $open)->reorder()
            ->select(DB::raw('m.level AS k'), DB::raw('count(*) AS n'))->groupBy('m.level')->pluck('n', 'k');
        $now = CarbonImmutable::now();
        $expiredOpen = $base()->where('notification_followups.status', $open)
            ->where(fn ($q) => $q->where('m.status', NotificationStatus::ARCHIVED->value)
                ->orWhere(fn ($e) => $e->whereNotNull('m.expires_at')->where('m.expires_at', '<=', $now)))
            ->count();
        $closed = $base()->toBase()->where('notification_followups.status', NotificationFollowupStatus::CLOSED->value)
            ->where('notification_followups.closed_at', '>=', $now->subDays(30))->reorder()
            ->select(DB::raw('notification_followups.outcome AS k'), DB::raw('count(*) AS n'))->groupBy('notification_followups.outcome')->pluck('n', 'k');

        $counts = [
            'open' => (int) $byStage->sum(),
            'open_by_stage' => ['FIRST' => (int) ($byStage['FIRST'] ?? 0), 'SECOND' => (int) ($byStage['SECOND'] ?? 0)],
            'open_by_level' => ['NORMAL' => (int) ($byLevel['NORMAL'] ?? 0), 'IMPORTANT' => (int) ($byLevel['IMPORTANT'] ?? 0)],
            'expired_open' => $expiredOpen,
            'closed_last_30_days' => [
                'SATISFIED' => (int) ($closed['SATISFIED'] ?? 0),
                'EXPIRED' => (int) ($closed['EXPIRED'] ?? 0),
                'CLOSED_MANUALLY' => (int) ($closed['CLOSED_MANUALLY'] ?? 0),
            ],
        ];
        if ($primary !== null) {
            $counts['mine_open'] = $base()->where('notification_followups.status', $open)->whereExists($primary)->count();
        }

        return $counts;
    }

    // ------------------------------------------------------- set-based "mine"

    /**
     * The EXISTS body "the context's coordinator is the primary coordinator of
     * the outer row's family (alias fam)", or NULL when they can own nothing
     * (no management permission).
     *
     * @return (Closure(QueryBuilder): mixed)|null
     */
    private function primaryCondition(CoordinatorAccessResult $context): ?Closure
    {
        $user = $context->user;
        if (! $context->allowed() || $user === null || ! $user->can('coordinator-notification-followup.manage')) {
            return null;
        }
        $userId = (int) $user->getKey();
        $ownFamilyId = $this->ownFamilyId($user);

        // The other ELIGIBLE coordinators with an assignment in this coordinator's clans.
        $clans = CoordinatorScopeAssignment::query()->where('user_id', $userId)->whereNull('revoked_at')->distinct()->pluck('clan_id');
        $others = [];
        $otherIds = CoordinatorScopeAssignment::query()->whereIn('clan_id', $clans)->whereNull('revoked_at')
            ->where('user_id', '<>', $userId)->distinct()->orderBy('user_id')->pluck('user_id');
        foreach (User::query()->whereKey($otherIds)->get() as $other) {
            if ($this->scopes->context($other)->allowed() && $other->can('coordinator-notification-followup.manage')) {
                $others[(int) $other->getKey()] = $this->ownFamilyId($other);
            }
        }

        return function (QueryBuilder $q) use ($userId, $ownFamilyId, $others) {
            $q->selectRaw('1')->from('coordinator_scope_assignments as ac')
                ->where('ac.user_id', $userId)
                ->whereNull('ac.revoked_at')
                ->whereColumn('ac.clan_id', 'fam.clan_id')
                ->where(fn (QueryBuilder $c) => $this->covers($c, 'ac'))
                ->when($ownFamilyId !== null, fn (QueryBuilder $c) => $c->where('fam.id', '<>', $ownFamilyId));
            if ($others === []) {
                return;
            }
            $q->whereNotExists(function (QueryBuilder $o) use ($others) {
                $o->selectRaw('1')->from('coordinator_scope_assignments as ao')
                    ->whereIn('ao.user_id', array_keys($others))
                    ->whereNull('ao.revoked_at')
                    ->whereColumn('ao.clan_id', 'fam.clan_id')
                    ->where(fn (QueryBuilder $c) => $this->covers($c, 'ao'));
                // Never a coordinator's own household.
                foreach ($others as $otherId => $otherOwnFamily) {
                    if ($otherOwnFamily !== null) {
                        $o->whereRaw('NOT (ao.user_id = ? AND fam.id = ?)', [$otherId, $otherOwnFamily]);
                    }
                }
                $spec = fn (string $a) => sprintf("(CASE %s.scope_type WHEN '%s' THEN 3 WHEN '%s' THEN 2 ELSE 1 END)",
                    $a, CoordinatorScopeType::BRANCH->value, CoordinatorScopeType::BRANCH_GROUP->value);
                $o->whereRaw("({$spec('ao')} > {$spec('ac')} OR ({$spec('ao')} = {$spec('ac')} AND (ao.assigned_at < ac.assigned_at OR (ao.assigned_at = ac.assigned_at AND ao.id < ac.id))))");
            });
        };
    }

    /** CLAN covers its Clan; BRANCH_GROUP the Branches of its Group; BRANCH its Branch (alias fam). */
    private function covers(QueryBuilder $c, string $a): void
    {
        $c->where("{$a}.scope_type", CoordinatorScopeType::CLAN->value)
            ->orWhere(fn (QueryBuilder $x) => $x->where("{$a}.scope_type", CoordinatorScopeType::BRANCH->value)->whereColumn("{$a}.branch_id", 'fam.branch_id'))
            ->orWhere(fn (QueryBuilder $x) => $x->where("{$a}.scope_type", CoordinatorScopeType::BRANCH_GROUP->value)
                ->whereExists(fn (QueryBuilder $b) => $b->selectRaw('1')->from("branches as gb_{$a}")
                    ->whereColumn("gb_{$a}.id", 'fam.branch_id')
                    ->whereColumn("gb_{$a}.branch_group_id", "{$a}.branch_group_id")));
    }

    private function ownFamilyId(User $user): ?int
    {
        $context = $this->resolver->familyContext($user);

        return $context->hasFamilyContext() ? (int) $context->family->getKey() : null;
    }

    /** Event types whose notes the API may show (Staff / coordinator notes only). */
    public static function noteTypes(): array
    {
        return [NotificationEventType::CONTACT_RECORDED, NotificationEventType::FOLLOWUP_CLOSED];
    }
}
