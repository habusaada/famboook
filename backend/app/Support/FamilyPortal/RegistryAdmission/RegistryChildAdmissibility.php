<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Enums\RegistryChildReason;
use App\Support\CivilLookup\CivilRecord;
use App\Support\FamilyAuth\FamilyAccessResult;

/**
 * Whether one child may be admitted now (FB-FAMILY-ADD-01 ADD-WP-07B-2c; ADR §5, E-3 …
 * E-9). Called by AdmitRegistryChildAction under its locks, with the National ID lock
 * held, so the application registry it reads cannot change before the commit.
 *
 * In operation the ONLY implementation is GatedRegistryChildAdmissibility: the action
 * refuses any other outside the test runner. A test-only implementation that lifts the
 * pending gates lives under tests/.
 */
interface RegistryChildAdmissibility
{
    /** @param  list<RegistryChildReason>  $sourceReasons  the cross-record reasons only discovery can see */
    public function assess(FamilyAccessResult $context, CivilRecord $record, array $sourceReasons): RegistryChildAdmission;
}
