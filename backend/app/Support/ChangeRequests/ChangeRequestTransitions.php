<?php

namespace App\Support\ChangeRequests;

use App\Enums\ChangeRequestRejectionReason;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType as E;
use LogicException;

/**
 * The ONE authoritative Change Request state machine (docs/05 §54 amended by
 * WF-ADR-049). Nothing writes a status that this table does not allow: the
 * PWA-5b Domain Actions check it under the request's row lock, and
 * WorkflowEventRecorder refuses an event that does not match it.
 *
 * - No V1 flow creates a DRAFT (AE-2): a request is created directly as
 *   SUBMITTED (from NULL). DRAFT → SUBMITTED is kept for compatibility.
 * - The family may cancel until approval, never from APPROVED (AE-3).
 * - APPROVED → REJECTED only as NO_LONGER_APPLICABLE (AE-4) — the "after a
 *   refused apply" condition is the action's check and a database CHECK.
 * - Terminal states (APPLIED, REJECTED, CANCELLED) have no outgoing
 *   transition. A refused / failed apply is not a transition: the request
 *   stays APPROVED and only an APPLY_FAILED event is recorded.
 *
 * The permission is the one the acting side must hold (docs/06 §52–§53);
 * family-side transitions additionally require the family.context Family.
 */
final class ChangeRequestTransitions
{
    /** @var list<ChangeRequestTransition>|null */
    private static ?array $all = null;

    /** @return list<ChangeRequestTransition> */
    public static function all(): array
    {
        $family = WorkflowActorSide::FAMILY;
        $staff = WorkflowActorSide::STAFF;
        $anyReason = ChangeRequestRejectionReason::cases();

        return self::$all ??= [
            new ChangeRequestTransition(null, S::SUBMITTED, E::SUBMITTED, $family, 'change-request.submit'),
            new ChangeRequestTransition(S::DRAFT, S::SUBMITTED, E::SUBMITTED, $family, 'change-request.submit'),

            new ChangeRequestTransition(S::SUBMITTED, S::UNDER_REVIEW, E::REVIEW_STARTED, $staff, 'change-request.review'),
            new ChangeRequestTransition(S::SUBMITTED, S::CANCELLED, E::CANCELLED, $family, 'change-request.cancel'),

            new ChangeRequestTransition(S::UNDER_REVIEW, S::RETURNED_FOR_CLARIFICATION, E::RETURNED, $staff, 'change-request.return'),
            new ChangeRequestTransition(S::UNDER_REVIEW, S::APPROVED, E::APPROVED, $staff, 'change-request.approve'),
            new ChangeRequestTransition(S::UNDER_REVIEW, S::REJECTED, E::REJECTED, $staff, 'change-request.reject', $anyReason),
            new ChangeRequestTransition(S::UNDER_REVIEW, S::CANCELLED, E::CANCELLED, $family, 'change-request.cancel'),

            new ChangeRequestTransition(S::RETURNED_FOR_CLARIFICATION, S::RESUBMITTED, E::RESUBMITTED, $family, 'change-request.resubmit'),
            new ChangeRequestTransition(S::RETURNED_FOR_CLARIFICATION, S::CANCELLED, E::CANCELLED, $family, 'change-request.cancel'),

            new ChangeRequestTransition(S::RESUBMITTED, S::UNDER_REVIEW, E::REVIEW_STARTED, $staff, 'change-request.review'),
            new ChangeRequestTransition(S::RESUBMITTED, S::CANCELLED, E::CANCELLED, $family, 'change-request.cancel'),

            new ChangeRequestTransition(S::APPROVED, S::APPLIED, E::APPLIED, $staff, 'change-request.apply'),
            new ChangeRequestTransition(S::APPROVED, S::REJECTED, E::REJECTED, $staff, 'change-request.reject', [ChangeRequestRejectionReason::NO_LONGER_APPLICABLE]),
        ];
    }

    public static function find(?S $from, S $to): ?ChangeRequestTransition
    {
        foreach (self::all() as $transition) {
            if ($transition->from === $from && $transition->to === $to) {
                return $transition;
            }
        }

        return null;
    }

    public static function allows(?S $from, S $to): bool
    {
        return self::find($from, $to) !== null;
    }

    /** @return list<ChangeRequestTransition> the transitions out of a status */
    public static function from(?S $from): array
    {
        return array_values(array_filter(self::all(), fn (ChangeRequestTransition $t) => $t->from === $from));
    }

    /**
     * The transition, or a LogicException when the table does not allow it or
     * the rejection reason does not fit it. The message holds codes only.
     */
    public static function assertAllowed(?S $from, S $to, ?ChangeRequestRejectionReason $reason = null): ChangeRequestTransition
    {
        $transition = self::find($from, $to);
        if ($transition === null) {
            throw new LogicException('Change request transition not allowed: '.($from->value ?? 'NEW').' → '.$to->value);
        }
        if (! $transition->accepts($reason)) {
            throw new LogicException('Rejection reason not allowed for '.($from->value ?? 'NEW').' → '.$to->value.': '.($reason->value ?? 'none'));
        }

        return $transition;
    }
}
