<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Support\CivilLookup\CivilRecord;
use App\Support\FamilyAuth\FamilyAccessResult;

/**
 * The operational eligibility of an admission (FB-FAMILY-ADD-01 ADD-WP-07B-2c):
 * RegistryChildEligibility::evaluate(), unchanged, read per record (no batch facts, so
 * under the caller's locks), plus discovery's cross-record reasons.
 *
 * A life is proven only by an authoritative RegistryLifeVerifier through
 * RegistryLifeStatus (RR-02; G-05, D-06), and none is bound, so no life is proven. With
 * the gender mapping still provisional (G-04), no child is admissible either way: every
 * admission is refused NOT_ELIGIBLE until those gates are closed by their own decision.
 */
final class GatedRegistryChildAdmissibility implements RegistryChildAdmissibility
{
    public function assess(FamilyAccessResult $context, CivilRecord $record, array $sourceReasons): RegistryChildAdmission
    {
        $assessment = RegistryChildEligibility::evaluate($context, $record);

        return RegistryChildAdmission::of($record, $assessment, [...$assessment->reasons, ...$sourceReasons], livingProven: $assessment->livingProven,
            livingByDeclaration: $assessment->livingByDeclaration);
    }
}
