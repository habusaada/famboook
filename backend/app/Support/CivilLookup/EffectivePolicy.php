<?php

namespace App\Support\CivilLookup;

/** The lookup policy that applies to one user today: the role it came from and the daily limit (NULL = unlimited). */
final class EffectivePolicy
{
    public function __construct(
        public readonly string $role,
        public readonly ?int $dailyLimit,
    ) {}

    public function unlimited(): bool
    {
        return $this->dailyLimit === null;
    }
}
