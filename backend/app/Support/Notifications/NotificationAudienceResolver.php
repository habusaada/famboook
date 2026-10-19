<?php

namespace App\Support\Notifications;

use App\Enums\CoordinatorScopeType;
use App\Enums\FamilyStatus;
use App\Enums\NotificationAudienceType;
use App\Enums\NotificationKind;
use App\Enums\NotificationSenderSide;
use App\Enums\WorkflowEventType;
use App\Exceptions\NotificationAudienceException;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\ChangeRequest;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Family;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\AccountSide;
use App\Support\FamilyAuth\CoordinatorAccessResult;
use App\Support\FamilyAuth\CoordinatorScopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The ONE server-side resolution of a notification audience into families
 * (FB-NOTIFY-01B.2b.3; ADR FB-NOTIFY-01 ADR-03, ADR-04, ADR-09).
 *
 * Every resolution is a QUERY over `families` — never a list of ids loaded
 * into PHP — one row per family (overlapping criteria cannot duplicate), soft-
 * deleted families excluded, ready to feed an INSERT … SELECT through
 * NotificationRecipientSnapshot.
 *
 *   STAFF_FAMILIES         the selected families that are ACTIVE now
 *   STAFF_ALL_ACTIVE       every ACTIVE family; announcement.send-all
 *   COORDINATOR_SCOPE      CoordinatorScopes::families() of the CURRENT scope
 *   COORDINATOR_SELECTION  the selection (families ∪ branches ∪ groups ∪
 *                          clans) INTERSECTED with that same query — never
 *                          widened by the client's criteria; every selected
 *                          structure must itself be inside the scope
 *   SYSTEM_ORIGIN          a RETURNED / REJECTED / APPLIED change-request
 *                          event: the request's own family_id, whatever its
 *                          status (R8); none when it has none
 *
 * Two moments:
 * - validateRequest(): when publication is requested. Explicitly selected
 *   families must ALL be eligible now (ACTIVE, not deleted, in scope), and
 *   the audience must not be empty.
 * - families() / forMessage(): at publication, inside its transaction. The
 *   sender's side, scope and send-all are RE-CHECKED and eligibility is
 *   re-resolved; a family that stopped being eligible is simply not
 *   selected. An empty result is the caller's PUBLISH_FAILED.
 *
 * This is scope authorization only. It never replaces the sending
 * permission (announcement.send / coordinator-announcement.send), which the
 * publication action's policy checks at request time and the publication
 * worker RE-CHECKS — with the coordinator's assignments — immediately before
 * writing the snapshot (ADR-04, ADR-08 rule 2). Infrastructure readiness
 * (NotificationReadiness) is separate too and grants nothing here. Nothing
 * here writes: recipients are inserted only by the future publication
 * transaction.
 */
final class NotificationAudienceResolver
{
    public const SEND_ALL_PERMISSION = 'announcement.send-all';

    /** The workflow events notified automatically (FB-NOTIFY-01B.1 D7). */
    public const SYSTEM_EVENT_TYPES = [WorkflowEventType::RETURNED, WorkflowEventType::REJECTED, WorkflowEventType::APPLIED];

    public function __construct(private readonly CoordinatorScopes $scopes) {}

    /**
     * Request-time validation: the audience fits the sender, every explicitly
     * selected family is eligible now, and at least one family results.
     */
    public function validateRequest(User $sender, NotificationAudience $audience): void
    {
        $families = $this->families($sender, $audience);

        $selected = $audience->ids('family_ids');
        if ($selected !== []) {
            $eligible = match ($audience->type) {
                NotificationAudienceType::STAFF_FAMILIES => $families->clone(),
                default => $this->scopes->families($this->coordinatorContext($sender)),
            };
            if ($eligible->whereIn('families.id', $selected)->count() !== count($selected)) {
                throw new NotificationAudienceException(NotificationAudienceException::FAMILY_NOT_ELIGIBLE);
            }
        }

        if (! $families->exists()) {
            throw new NotificationAudienceException(NotificationAudienceException::EMPTY);
        }
    }

    /**
     * The families $sender may address with $audience RIGHT NOW. Re-checks
     * the sender's side, scope and send-all on every call.
     *
     * @return Builder<Family>
     */
    public function families(User $sender, NotificationAudience $audience): Builder
    {
        return match ($audience->type) {
            NotificationAudienceType::STAFF_FAMILIES => $this->staffFamilies($sender)
                ->whereIn('families.id', $audience->ids('family_ids')),
            NotificationAudienceType::STAFF_ALL_ACTIVE => $this->staffFamilies($sender, sendAll: true),
            NotificationAudienceType::COORDINATOR_SCOPE => $this->scopes->families($this->coordinatorContext($sender)),
            NotificationAudienceType::COORDINATOR_SELECTION => $this->coordinatorSelection($sender, $audience),
            NotificationAudienceType::SYSTEM_ORIGIN => throw new NotificationAudienceException(NotificationAudienceException::INVALID),
        };
    }

    /**
     * SYSTEM (ADR-09, E1, D7): the family of the change request behind this
     * workflow event — any family status (R8), never a deleted family.
     *
     * The event must exist, belong to a change request and be RETURNED,
     * REJECTED or APPLIED; anything else is INVALID (never silently empty). A
     * request without a family (REGISTER_FAMILY) is the approved skip: it
     * resolves to NO family, and nothing is inferred from created_family_id
     * or from the submitter.
     *
     * @return Builder<Family>
     */
    public function systemFamilies(int $originWorkflowEventId): Builder
    {
        $morph = (new ChangeRequest)->getMorphClass();
        $types = array_map(fn (WorkflowEventType $type) => $type->value, self::SYSTEM_EVENT_TYPES);
        $origin = WorkflowEvent::query()->whereKey($originWorkflowEventId)->first(['id', 'workflowable_type', 'event_type']);
        if ($origin === null || $origin->workflowable_type !== $morph || ! in_array($origin->event_type, self::SYSTEM_EVENT_TYPES, true)) {
            throw new NotificationAudienceException(NotificationAudienceException::INVALID);
        }

        // The same conditions again in SQL: the query stands on its own.
        return Family::query()->whereIn('families.id', fn (QueryBuilder $q) => $q
            ->select('cr.family_id')
            ->from('workflow_events as we')
            ->join('change_requests as cr', 'cr.id', '=', 'we.workflowable_id')
            ->where('we.id', $originWorkflowEventId)
            ->where('we.workflowable_type', $morph)
            ->whereIn('we.event_type', $types)
            ->whereNotNull('cr.family_id'));
    }

    /**
     * Publication-time resolution of a stored message: its kind, side and
     * audience must agree; the sender is re-read and re-authorized.
     *
     * @return Builder<Family>
     */
    public function forMessage(NotificationMessage $message): Builder
    {
        if ($message->kind === NotificationKind::SYSTEM) {
            if ($message->sender_side !== NotificationSenderSide::SYSTEM || $message->origin_workflow_event_id === null) {
                throw new NotificationAudienceException(NotificationAudienceException::INVALID);
            }

            return $this->systemFamilies((int) $message->origin_workflow_event_id);
        }

        $audience = NotificationAudience::fromArray($message->audience);
        if ($audience->type->senderSide() !== $message->sender_side) {
            throw new NotificationAudienceException(NotificationAudienceException::INVALID);
        }
        $sender = User::query()->find($message->sender_user_id)
            ?? throw new NotificationAudienceException(NotificationAudienceException::NOT_AUTHORIZED);

        return $this->families($sender, $audience);
    }

    /**
     * ACTIVE, not deleted families, for an active Staff-side sender — who,
     * for every ACTIVE family, also holds send-all.
     *
     * @return Builder<Family>
     */
    private function staffFamilies(User $sender, bool $sendAll = false): Builder
    {
        if (! $sender->is_active || ! AccountSide::isStaff($sender) || ($sendAll && ! $sender->can(self::SEND_ALL_PERMISSION))) {
            throw new NotificationAudienceException(NotificationAudienceException::NOT_AUTHORIZED);
        }

        return Family::query()->where('families.status', FamilyStatus::ACTIVE->value);
    }

    /** The sender's CURRENT Coordinator context — the one authority on scope. */
    private function coordinatorContext(User $sender): CoordinatorAccessResult
    {
        $context = $this->scopes->context($sender);
        if (! $context->allowed()) {
            throw new NotificationAudienceException(NotificationAudienceException::NOT_AUTHORIZED);
        }

        return $context;
    }

    /**
     * A coordinator selection: every selected structure must be covered by an
     * effective assignment (or the whole audience is refused), and the family
     * query stays intersected with CoordinatorScopes::families().
     *
     * @return Builder<Family>
     */
    private function coordinatorSelection(User $sender, NotificationAudience $audience): Builder
    {
        $context = $this->coordinatorContext($sender);
        $this->assertStructuresCovered($context, $audience);

        return $this->scopes->families($context)->where(fn (Builder $q) => $this->selection($q, $audience));
    }

    /**
     * Every selected Clan, Branch Group and Branch lies WHOLLY inside the
     * CURRENT effective scope — the coversPlacement() rules:
     *
     *   Clan          a CLAN assignment of that Clan
     *   Branch Group  active; a CLAN assignment of its Clan or a BRANCH_GROUP
     *                 assignment of it
     *   Branch        active (and, when grouped, its Group active); a CLAN
     *                 assignment of its Clan, a BRANCH_GROUP assignment of its
     *                 Group or a BRANCH assignment of it
     *
     * One unauthorized, inactive or unknown structure refuses the WHOLE
     * request with the generic NOT_AUTHORIZED: nothing is silently dropped,
     * and "unknown" and "outside your scope" are indistinguishable. At most
     * MAX_SELECTED structure rows are read (never families); the effective
     * assignments are already in the context.
     */
    private function assertStructuresCovered(CoordinatorAccessResult $context, NotificationAudience $audience): void
    {
        [$clans, $groups, $branches] = [$audience->ids('clan_ids'), $audience->ids('branch_group_ids'), $audience->ids('branch_ids')];
        if ($clans === [] && $groups === [] && $branches === []) {
            return;
        }
        $assigned = fn (CoordinatorScopeType $type, string $column) => $context->assignments
            ->filter(fn (CoordinatorScopeAssignment $a) => $a->scope_type === $type)
            ->map(fn (CoordinatorScopeAssignment $a) => (int) $a->{$column})
            ->all();
        $clanScoped = $assigned(CoordinatorScopeType::CLAN, 'clan_id');
        $groupScoped = $assigned(CoordinatorScopeType::BRANCH_GROUP, 'branch_group_id');
        $branchScoped = $assigned(CoordinatorScopeType::BRANCH, 'branch_id');

        $covered = array_diff($clans, $clanScoped) === [];

        if ($covered && $groups !== []) {
            $found = BranchGroup::query()->whereKey($groups)->where('is_active', true)->get(['id', 'clan_id']);
            $covered = $found->count() === count($groups) && $found->every(fn (BranchGroup $group) => in_array((int) $group->id, $groupScoped, true)
                || in_array((int) $group->clan_id, $clanScoped, true));
        }

        if ($covered && $branches !== []) {
            $found = Branch::query()->with('group:id,is_active')->whereKey($branches)->where('is_active', true)
                ->get(['id', 'clan_id', 'branch_group_id']);
            $covered = $found->count() === count($branches) && $found->every(fn (Branch $branch) => ($branch->branch_group_id === null || $branch->group?->is_active === true)
                && (in_array((int) $branch->id, $branchScoped, true)
                    || ($branch->branch_group_id !== null && in_array((int) $branch->branch_group_id, $groupScoped, true))
                    || in_array((int) $branch->clan_id, $clanScoped, true)));
        }

        if (! $covered) {
            throw new NotificationAudienceException(NotificationAudienceException::NOT_AUTHORIZED);
        }
    }

    /** The union of the selected criteria (the caller intersects it with the scope). */
    private function selection(Builder $query, NotificationAudience $audience): void
    {
        if ($ids = $audience->ids('family_ids')) {
            $query->orWhereIn('families.id', $ids);
        }
        if ($ids = $audience->ids('branch_ids')) {
            $query->orWhereIn('families.branch_id', $ids);
        }
        if ($ids = $audience->ids('branch_group_ids')) {
            $query->orWhereIn('families.branch_id', fn (QueryBuilder $b) => $b
                ->select('sb.id')->from('branches as sb')->whereIn('sb.branch_group_id', $ids));
        }
        if ($ids = $audience->ids('clan_ids')) {
            $query->orWhereIn('families.clan_id', $ids);
        }
    }
}
