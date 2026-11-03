<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use JsonSerializable;
use LogicException;
use WeakMap;

/**
 * A validated direct-admission reference (FB-FAMILY-ADD-01 ADD-WP-06B-2). INTERNAL: for
 * the trusted admission code only, which must evaluate the child again under its locks
 * (ADR §10). The child's National ID is reachable only through childNationalId().
 *
 * ADD-WP-07B-2c-0: the National ID is NOT a property of the object. It is held in a
 * private static WeakMap keyed by the object, so ordinary inspection of the object —
 * var_export(), (array) casts, get_object_vars(), print_r(), var_dump(),
 * debug_zval_dump(), json_encode() of a cast — finds no National ID, and the entry
 * disappears with the object. It is also never JSON-encoded, serialized, unserialized or
 * cloned. This is not secrecy against Reflection, memory inspection or a debugger with
 * access to the process (ADR §7a).
 */
final class RegistryAdmissionReference implements JsonSerializable
{
    /** @var WeakMap<self, string>|null */
    private static ?WeakMap $nationalIds = null;

    public function __construct(
        public readonly int $releaseId,
        #[\SensitiveParameter] string $childNationalId,
        /** 'FATHER' or 'MOTHER' */
        public readonly string $parentLink,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
    ) {
        self::$nationalIds ??= new WeakMap;
        self::$nationalIds[$this] = $childNationalId;
    }

    public function childNationalId(): string
    {
        return self::$nationalIds[$this] ?? throw new LogicException('An admission reference without its child.');
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

    /** @param  array<mixed>  $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('An admission reference is never unserialized.');
    }

    /** A copy would carry no child (the WeakMap is keyed by the original): refused instead. */
    public function __clone(): void
    {
        throw new LogicException('An admission reference is never cloned.');
    }
}
