<?php

namespace Tests\Support\FamilyPortal;

use App\Enums\RegistryChildReason as Reason;
use App\Support\CivilLookup\CivilRecord;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\RegistryAdmission\CivilRegistryValues;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildAdmissibility;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildAdmission;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildEligibility;
use LogicException;

/**
 * TESTS ONLY (FB-FAMILY-ADD-01 RR-02A): the operational eligibility with ONLY gate G-04
 * lifted (GENDER_MAPPING_PROVISIONAL, when the gender maps). Life is left exactly to the
 * application's own rules (RegistryLifeStatus, the verifier and the revised policy), so a
 * test can show what the life policy alone decides. Refused outside the test runner.
 */
final class GenderGateLiftedAdmissibility implements RegistryChildAdmissibility
{
    public function __construct()
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('The gender gate is lifted in tests only.');
        }
    }

    public function assess(FamilyAccessResult $context, CivilRecord $record, array $sourceReasons): RegistryChildAdmission
    {
        $assessment = RegistryChildEligibility::evaluate($context, $record);
        $mapped = CivilRegistryValues::gender($record->payload['gender'] ?? null)->mapped();
        $reasons = array_values(array_filter([...$assessment->reasons, ...$sourceReasons],
            fn (Reason $reason) => ! ($reason === Reason::GENDER_MAPPING_PROVISIONAL && $mapped)));

        return RegistryChildAdmission::of($record, $assessment, $reasons,
            livingProven: $assessment->livingProven, livingByDeclaration: $assessment->livingByDeclaration);
    }
}
