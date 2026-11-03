<?php

namespace Tests\Support\FamilyPortal;

use App\Enums\LifeEvidence;
use App\Enums\RegistryChildReason as Reason;
use App\Support\CivilLookup\CivilRecord;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\RegistryAdmission\CivilRegistryValues;
use App\Support\FamilyPortal\RegistryAdmission\ProofOfLife;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildAdmissibility;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildAdmission;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildEligibility;
use LogicException;

/**
 * TESTS ONLY (FB-FAMILY-ADD-01 ADD-WP-07B-2c, plan U-1): the operational eligibility with
 * the two PENDING gates lifted, so the admission's success path can be tested at all.
 *
 * - GENDER_MAPPING_PROVISIONAL is lifted only when the gender maps (G-04 pending);
 * - LIFE_UNVERIFIED is lifted, and life counted as proven, only when ProofOfLife is
 *   UNVERIFIED, i.e. an empty death date (G-05 pending).
 * - NOTHING else: DECEASED and INDETERMINATE stay refusals (D-06), as does every other
 *   reason. Under tests/ (autoload-dev), bound nowhere, refused outside the test runner;
 *   AdmitRegistryChildAction refuses any admissibility but the gated one outside tests too.
 */
final class PendingGatesLiftedAdmissibility implements RegistryChildAdmissibility
{
    public function __construct()
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('Pending gates are lifted in tests only.');
        }
    }

    public function assess(FamilyAccessResult $context, CivilRecord $record, array $sourceReasons): RegistryChildAdmission
    {
        $assessment = RegistryChildEligibility::evaluate($context, $record);
        $unverified = ProofOfLife::evaluate($record) === LifeEvidence::UNVERIFIED;
        $mapped = CivilRegistryValues::gender($record->payload['gender'] ?? null)->mapped();

        $reasons = array_values(array_filter([...$assessment->reasons, ...$sourceReasons], fn (Reason $reason) => ! (
            ($reason === Reason::GENDER_MAPPING_PROVISIONAL && $mapped)
            || ($reason === Reason::LIFE_UNVERIFIED && $unverified)
        )));

        return RegistryChildAdmission::of($record, $assessment, $reasons, livingProven: $unverified);
    }
}
