<?php

namespace App\Support\ChangeRequests;

use App\Enums\FamilySubmissionMode;
use App\Support\FamilyAuth\FamilyAccessResult;

/**
 * The ONE answer to "may this Family submit a NEW Change Request now?"
 * (PWA-6.1b, docs/11 FP-ADR-075). Enforced by SubmitChangeRequestAction
 * (authoritative), asked early by SubmitFamilyChangeRequestRequest (same
 * 503 for a closed channel) and by type discovery.
 *
 * Inputs are trusted server values only: the family.context result and the
 * server configuration — never a Family id from the client.
 *
 * - Mode (`change_requests.family_submission_mode`): exactly OFF, PILOT or
 *   GENERAL after trimming and upper-casing; missing, empty or anything else
 *   is OFF. The legacy boolean CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED is
 *   NOT read: it can never open the channel.
 * - Pilot allowlist (`change_requests.pilot_family_ids`): a comma-separated
 *   list of canonical Family database ids (positive integers, no sign, no
 *   leading zero). Empty → no Family; ANY malformed entry → the whole list
 *   is invalid → no Family. Never logged, returned or shown.
 *
 * It decides the CHANNEL only. Eligibility (family.context), the
 * change-request.submit permission, the request type and its validation are
 * checked by their own owners, unchanged.
 */
final class FamilySubmissionPolicy
{
    /** The effective mode: fail closed to OFF. */
    public static function mode(): FamilySubmissionMode
    {
        $raw = config('change_requests.family_submission_mode');

        return is_string($raw) ? (FamilySubmissionMode::tryFrom(strtoupper(trim($raw))) ?? FamilySubmissionMode::OFF) : FamilySubmissionMode::OFF;
    }

    /**
     * The pilot allowlist as Family ids, or NULL when it is malformed (then
     * no Family is allowed).
     *
     * @return list<int>|null
     */
    public static function pilotFamilyIds(): ?array
    {
        $raw = config('change_requests.pilot_family_ids');
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

    /** May THIS context's Family submit a new request now? */
    public static function allows(FamilyAccessResult $context): bool
    {
        $family = $context->family;
        if ($family === null || ! $context->hasFamilyContext()) {
            return false;
        }

        return match (self::mode()) {
            FamilySubmissionMode::OFF => false,
            FamilySubmissionMode::GENERAL => true,
            FamilySubmissionMode::PILOT => in_array((int) $family->getKey(), self::pilotFamilyIds() ?? [], true),
        };
    }
}
