<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

/**
 * What an admission answers (ADR §7): only the opaque member reference of the new
 * membership (NULL on a replay once it is no longer an active member of this Family)
 * and whether this was a replay. No National ID, Person, membership id or name.
 */
final class RegistryAdmissionOutcome
{
    public function __construct(
        public readonly ?string $memberRef,
        public readonly bool $replayed,
    ) {}

    /** @return array{member_ref: ?string, replayed: bool} */
    public function toArray(): array
    {
        return ['member_ref' => $this->memberRef, 'replayed' => $this->replayed];
    }
}
