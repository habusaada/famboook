<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;

/**
 * The input of one direct admission (FB-FAMILY-ADD-01 ADD-WP-07B-2c; ADR §7 Admission).
 * The client sends no Family, Person, membership or National ID: the household comes
 * from family.context, the child from the opaque reference. Shapes are checked here;
 * the reference itself is checked by RegistryAdmissionRefs. Never serialized or logged.
 */
final class RegistryAdmissionRequest implements JsonSerializable
{
    /** A lowercase canonical UUID (RFC 4122 variant). */
    public const CLIENT_REFERENCE_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /** family_registry_admissions.declaration_version is varchar(40). */
    public const DECLARATION_VERSION_MAX = 40;

    public function __construct(
        #[\SensitiveParameter] public readonly string $ref,
        public readonly string $clientReference,
        public readonly string $declarationVersion,
        public readonly bool $declarationAccepted,
        public readonly ?string $ip = null,
        // RR-02A (D-06R): the head's separate declaration that the child is alive now.
        public readonly bool $lifeDeclared = false,
    ) {
        if ($ref === '' || strlen($ref) > RegistryAdmissionRefs::MAX_LENGTH) {
            throw new InvalidArgumentException('An admission names its child by a reference.');
        }
        if (preg_match(self::CLIENT_REFERENCE_PATTERN, $clientReference) !== 1) {
            throw new InvalidArgumentException('An admission carries a lowercase UUID as its client reference.');
        }
        if (trim($declarationVersion) === '' || strlen($declarationVersion) > self::DECLARATION_VERSION_MAX) {
            throw new InvalidArgumentException('An admission carries the declaration version it accepted.');
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['ref' => '[redacted]', 'clientReference' => $this->clientReference, 'declarationVersion' => $this->declarationVersion,
            'declarationAccepted' => $this->declarationAccepted, 'lifeDeclared' => $this->lifeDeclared];
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('An admission request is never serialized.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('An admission request is never serialized.');
    }
}
