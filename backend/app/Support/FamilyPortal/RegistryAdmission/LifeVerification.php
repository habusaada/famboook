<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use DateTimeImmutable;

/**
 * One answer of a RegistryLifeVerifier (RR-02): the status the authoritative source
 * states and when it established it. A NULL time is never fresh, so it never proves a
 * life.
 */
final class LifeVerification
{
    public function __construct(
        public readonly LifeVerificationStatus $status,
        public readonly ?DateTimeImmutable $verifiedAt,
    ) {}
}
