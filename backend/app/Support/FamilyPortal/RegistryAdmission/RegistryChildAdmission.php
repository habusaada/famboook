<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\RegistryChildReason;
use App\Support\CivilLookup\CivilRecord;

/**
 * The admission's own view of one child (FB-FAMILY-ADD-01 ADD-WP-07B-2c): the reasons
 * that refuse it (INTERNAL) and the values a new Person would be created with. Built
 * only by a RegistryChildAdmissibility, under the admission's locks.
 *
 * admissible() needs no reason at all AND a proven living child (D-06: an unproven life
 * is a refusal, never a default), a FATHER / MOTHER link, a mapped gender and a name.
 * RR-02A (D-06R, proposed): or a child in a living-scope release (`livingByDeclaration`),
 * which the admission accepts ONLY with the head's declaration of current life and records
 * with life status UNKNOWN, never ALIVE.
 */
final class RegistryChildAdmission
{
    /** @param  list<RegistryChildReason>  $reasons */
    public function __construct(
        public readonly array $reasons,
        public readonly bool $livingProven,
        public readonly ?string $parentLink,
        public readonly ?Gender $gender,
        public readonly MaritalStatus $maritalStatus,
        public readonly ?string $fullName,
        public readonly ?string $birthDate,
        public readonly bool $livingByDeclaration = false,
    ) {}

    /**
     * The values of $record, refused by $reasons. The marital status is the mapped one or
     * UNKNOWN (never guessed); the birth date the observed one or NULL (never invented).
     *
     * @param  list<RegistryChildReason>  $reasons
     */
    public static function of(CivilRecord $record, RegistryChildAssessment $assessment, array $reasons, bool $livingProven, bool $livingByDeclaration = false): self
    {
        $marital = CivilRegistryValues::maritalStatus($record->payload['maritalst'] ?? null);

        return new self(
            array_values(array_unique($reasons, SORT_REGULAR)),
            $livingProven,
            $assessment->parentLink,
            $assessment->gender,
            $marital->value instanceof MaritalStatus ? $marital->value : MaritalStatus::UNKNOWN,
            CivilRegistryValues::fullName($record),
            CivilRegistryValues::date($record->payload['datebirth'] ?? null),
            ! $livingProven && $livingByDeclaration,
        );
    }

    public function admissible(): bool
    {
        return $this->reasons === [] && ($this->livingProven || $this->livingByDeclaration) && in_array($this->parentLink, ['FATHER', 'MOTHER'], true)
            && in_array($this->gender, [Gender::MALE, Gender::FEMALE], true) && $this->fullName !== null;
    }

    /** The reason recorded for a refusal: the first one, or the unproven life. */
    public function refusalReason(): RegistryChildReason
    {
        return $this->reasons[0] ?? RegistryChildReason::LIFE_UNVERIFIED;
    }

    /** SON or DAUGHTER, from the mapped gender (G-04). */
    public function relationship(): ?string
    {
        return match ($this->gender) {
            Gender::MALE => 'SON',
            Gender::FEMALE => 'DAUGHTER',
            default => null,
        };
    }
}
