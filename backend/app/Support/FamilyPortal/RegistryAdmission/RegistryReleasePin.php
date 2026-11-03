<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Exceptions\RegistryAdmissionReferenceException;
use App\Exceptions\RegistryReleasePinUnavailableException;

/**
 * Keeps the published civil registry release an admission read from changing until the
 * admission transaction ends (FB-FAMILY-ADD-01 ADD-WP-07B-2b; ADD-WP-07B-2R D-F1, D-F3,
 * D-F5). INTERNAL: nothing calls it and nothing is bound in the container.
 *
 * pin() is called inside the admission transaction, before its row locks
 * (ADD-WP-07B-2R §4 step 10). It returns only if, from then until that transaction
 * commits or rolls back, publication cannot replace $releaseId.
 */
interface RegistryReleasePin
{
    /**
     * @throws RegistryAdmissionReferenceException RELEASE_CHANGED: $releaseId is no longer the active release
     * @throws RegistryReleasePinUnavailableException the pin could not be taken or proven (fail closed)
     */
    public function pin(int $releaseId): void;
}
