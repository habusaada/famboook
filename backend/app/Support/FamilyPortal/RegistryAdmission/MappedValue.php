<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Enums\CivilValueStatus;

/** One raw civil registry value as CivilRegistryValues read it: $value only when MAPPED. */
final class MappedValue
{
    public function __construct(
        public readonly CivilValueStatus $status,
        public readonly ?\BackedEnum $value = null,
    ) {}

    public function mapped(): bool
    {
        return $this->status === CivilValueStatus::MAPPED;
    }
}
