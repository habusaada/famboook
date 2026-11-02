<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use JsonSerializable;
use LogicException;

/**
 * A validated direct-admission reference (FB-FAMILY-ADD-01 ADD-WP-06B-2). INTERNAL:
 * for the trusted admission code only, which must evaluate the child again under its
 * locks (ADR §10). The child's National ID is reachable only through
 * childNationalId(); it is masked in debug output and cannot be serialized.
 */
final class RegistryAdmissionReference implements JsonSerializable
{
    public function __construct(
        public readonly int $releaseId,
        #[\SensitiveParameter] private readonly string $childNationalId,
        /** 'FATHER' or 'MOTHER' */
        public readonly string $parentLink,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
    ) {}

    public function childNationalId(): string
    {
        return $this->childNationalId;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['releaseId' => $this->releaseId, 'childNationalId' => '[redacted]', 'parentLink' => $this->parentLink,
            'issuedAt' => $this->issuedAt, 'expiresAt' => $this->expiresAt];
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('An admission reference is never serialized.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('An admission reference is never serialized.');
    }
}
