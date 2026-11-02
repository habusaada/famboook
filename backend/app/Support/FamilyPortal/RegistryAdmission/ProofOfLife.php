<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Enums\LifeEvidence;
use App\Support\CivilLookup\CivilRecord;

/**
 * Proof of life from the civil registry (FB-FAMILY-ADD-01 ADD-WP-05; D-06, gate G-05).
 * Fails closed: it can only report DECEASED, INDETERMINATE or UNVERIFIED, so it never
 * authorizes an admission.
 *
 * - Death is known only from a valid `deathdate` (02A §L-4).
 * - An empty `deathdate` is UNVERIFIED, never alive.
 * - `presonstate` is never interpreted: it cannot prove life or death.
 * - Age, birth date and marital status are not read.
 * - There is no override.
 */
final class ProofOfLife
{
    public static function evaluate(CivilRecord $record): LifeEvidence
    {
        $death = $record->payload['deathdate'] ?? null;
        if (! is_string($death) || $death === '') {
            return LifeEvidence::UNVERIFIED;
        }

        return CivilRegistryValues::date($death) !== null ? LifeEvidence::DECEASED : LifeEvidence::INDETERMINATE;
    }
}
