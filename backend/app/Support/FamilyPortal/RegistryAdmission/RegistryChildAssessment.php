<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Enums\Gender;
use App\Enums\RegistryChildReason;
use App\Enums\RegistryChildStatus;

/**
 * The evaluation of one suggested child (FB-FAMILY-ADD-01 ADD-WP-05). The reasons are
 * INTERNAL diagnostics. Only publicStatus() may ever leave the server: it never names
 * another household, a death, a deleted record or any registry status.
 */
final class RegistryChildAssessment
{
    /** @param  list<RegistryChildReason>  $reasons */
    public function __construct(
        public readonly array $reasons,
        public readonly ?string $parentLink = null,
        public readonly ?Gender $gender = null,
    ) {}

    public function publicStatus(): RegistryChildStatus
    {
        if ($this->reasons === []) {
            return RegistryChildStatus::ELIGIBLE;
        }
        // The head's own household: nothing about another household is disclosed.
        if (in_array(RegistryChildReason::ALREADY_MEMBER, $this->reasons, true)) {
            return RegistryChildStatus::ALREADY_MEMBER;
        }
        if (in_array(RegistryChildReason::REQUEST_OPEN, $this->reasons, true) && self::onlyRequestOrPending($this->reasons)) {
            return RegistryChildStatus::REQUEST_OPEN;
        }

        return RegistryChildStatus::NOT_ELIGIBLE;
    }

    public function admissible(): bool
    {
        return $this->publicStatus() === RegistryChildStatus::ELIGIBLE;
    }

    public function has(RegistryChildReason $reason): bool
    {
        return in_array($reason, $this->reasons, true);
    }

    /**
     * An open request is shown as such only when nothing else refuses the child for a
     * reason the head may not learn (another household, a death, a deleted record …).
     *
     * @param  list<RegistryChildReason>  $reasons
     */
    private static function onlyRequestOrPending(array $reasons): bool
    {
        $pending = [RegistryChildReason::REQUEST_OPEN, RegistryChildReason::GENDER_MAPPING_PROVISIONAL, RegistryChildReason::LIFE_UNVERIFIED];

        return array_diff(array_map(fn ($r) => $r->value, $reasons), array_map(fn ($r) => $r->value, $pending)) === [];
    }
}
