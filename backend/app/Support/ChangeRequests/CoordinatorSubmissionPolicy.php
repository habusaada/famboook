<?php

namespace App\Support\ChangeRequests;

use App\Enums\CoordinatorSubmissionMode;
use App\Models\User;

/**
 * The ONE answer to "may this Coordinator submit a NEW Change Request now?"
 * (CO-02.3b; docs/06 AUTH-ADR-093, docs/adr/CO-02-COORDINATOR-REQUESTS.md
 * §11). Enforced by SubmitChangeRequestAction::handleCoordinator
 * (authoritative). The family channel (FamilySubmissionPolicy) is separate:
 * neither switch opens the other.
 *
 * - Mode (`change_requests.coordinator_submission_mode`): exactly OFF, PILOT
 *   or GENERAL after trimming and upper-casing; missing, empty or anything
 *   else is OFF.
 * - Pilot allowlist (`change_requests.coordinator_pilot_user_ids`): a
 *   comma-separated list of canonical user database ids (positive integers,
 *   no sign, no leading zero). Empty → nobody; ANY malformed entry → the
 *   whole list is invalid → nobody. Never logged, returned or shown.
 *
 * It decides the CHANNEL only. Who is a Coordinator, the
 * coordinator-request.submit permission and the scope are checked by their
 * own owners. Only NEW submissions are gated: resubmission, cancellation and
 * the whole Staff workflow continue while it is closed.
 */
final class CoordinatorSubmissionPolicy
{
    /** The effective mode: fail closed to OFF. */
    public static function mode(): CoordinatorSubmissionMode
    {
        $raw = config('change_requests.coordinator_submission_mode');

        return is_string($raw) ? (CoordinatorSubmissionMode::tryFrom(strtoupper(trim($raw))) ?? CoordinatorSubmissionMode::OFF) : CoordinatorSubmissionMode::OFF;
    }

    /**
     * The pilot allowlist as user ids, or NULL when it is malformed (then
     * nobody is allowed).
     *
     * @return list<int>|null
     */
    public static function pilotUserIds(): ?array
    {
        $raw = config('change_requests.coordinator_pilot_user_ids');
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return [];
        }
        if (! is_string($raw)) {
            return null;
        }

        $ids = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if (preg_match('/^[1-9][0-9]{0,17}$/', $entry) !== 1) {
                return null;
            }
            $ids[] = (int) $entry;
        }

        return array_values(array_unique($ids));
    }

    /** May THIS account submit a new Coordinator request now? */
    public static function allows(User $user): bool
    {
        return match (self::mode()) {
            CoordinatorSubmissionMode::OFF => false,
            CoordinatorSubmissionMode::GENERAL => true,
            CoordinatorSubmissionMode::PILOT => in_array((int) $user->getKey(), self::pilotUserIds() ?? [], true),
        };
    }
}
