<?php

namespace App\Enums;

use App\Models\Family;
use App\Models\FamilyHouseholdDeclaration;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;

// docs/03 §96b "Apply provenance" — the stable, generic key of ONE intended
// effect of one import row. (import_row_id, effect_key) is unique, so the same
// effect can never be recorded twice — the idempotency anchor of a future,
// resumable Apply. The key never contains source values.
enum ImportApplyEffect: string
{
    case HEAD_PERSON = 'HEAD_PERSON';
    case FAMILY = 'FAMILY';
    case HEAD_MEMBERSHIP = 'HEAD_MEMBERSHIP';
    case HOUSEHOLD_DECLARATION = 'HOUSEHOLD_DECLARATION';
    case RESIDENCE = 'RESIDENCE';
    case SPOUSE_1_PERSON = 'SPOUSE_1_PERSON';
    case SPOUSE_2_PERSON = 'SPOUSE_2_PERSON';
    case SPOUSE_3_PERSON = 'SPOUSE_3_PERSON';
    case SPOUSE_4_PERSON = 'SPOUSE_4_PERSON';
    // One per source spouse slot: CREATED (active SPOUSE membership) or
    // OMITTED (the relationship is kept as evidence only).
    case SPOUSE_1_MEMBERSHIP = 'SPOUSE_1_MEMBERSHIP';
    case SPOUSE_2_MEMBERSHIP = 'SPOUSE_2_MEMBERSHIP';
    case SPOUSE_3_MEMBERSHIP = 'SPOUSE_3_MEMBERSHIP';
    case SPOUSE_4_MEMBERSHIP = 'SPOUSE_4_MEMBERSHIP';

    public static function spousePerson(int $slot): self
    {
        return self::from("SPOUSE_{$slot}_PERSON");
    }

    public static function spouseMembership(int $slot): self
    {
        return self::from("SPOUSE_{$slot}_MEMBERSHIP");
    }

    /** Stable entity code stored in import_apply_records.entity_type. */
    public function entityType(): string
    {
        return match (true) {
            $this === self::FAMILY => 'FAMILY',
            $this === self::HOUSEHOLD_DECLARATION => 'HOUSEHOLD_DECLARATION',
            $this === self::RESIDENCE => 'RESIDENCE',
            str_ends_with($this->value, '_MEMBERSHIP') => 'MEMBERSHIP',
            default => 'PERSON',
        };
    }

    /** HEAD / SPOUSE for person and membership effects; null for household-level ones. */
    public function role(): ?string
    {
        return match (true) {
            str_starts_with($this->value, 'HEAD_') => 'HEAD',
            str_starts_with($this->value, 'SPOUSE_') => 'SPOUSE',
            default => null,
        };
    }

    public function spouseSlot(): ?int
    {
        return str_starts_with($this->value, 'SPOUSE_') ? (int) $this->value[7] : null;
    }

    /** Registry model an entity_type code points to (read-side only). */
    public static function modelFor(string $entityType): string
    {
        return [
            'PERSON' => Person::class,
            'FAMILY' => Family::class,
            'MEMBERSHIP' => FamilyMembership::class,
            'HOUSEHOLD_DECLARATION' => FamilyHouseholdDeclaration::class,
            'RESIDENCE' => FamilyResidence::class,
        ][$entityType];
    }
}
