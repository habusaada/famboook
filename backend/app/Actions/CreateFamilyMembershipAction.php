<?php

namespace App\Actions;

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Support\RelationshipTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical attachment of an EXISTING Person to an EXISTING Family as one
 * active membership (docs/03 §11-§14, §96b) — an internal building block,
 * never an endpoint, and never a Person creator.
 *
 * - The relationship type is required by code (RelationshipTypes::required),
 *   resolved before any write: a missing or inactive seed fails loudly.
 * - The Family and the Person are locked and re-checked: the Family exists
 *   (not soft-deleted) and, when given, belongs to the expected Clan; the
 *   Person exists (not soft-deleted) and has NO active membership (one active
 *   membership per Person, docs/03 §13).
 * - A household-head membership requires a Family without an active head
 *   (one active head per Family, docs/03 §14).
 * - The partial unique indexes stay the final backstop.
 * - No activity entry: the calling Family action records its own event.
 */
class CreateFamilyMembershipAction
{
    public function handle(
        Family $family,
        Person $person,
        string $relationshipCode,
        bool $isHouseholdHead,
        string $startedAt,
        ?int $actingUserId,
        ?int $expectedClanId = null,
    ): FamilyMembership {
        $typeId = RelationshipTypes::required($relationshipCode);

        return DB::transaction(function () use ($family, $person, $typeId, $isHouseholdHead, $startedAt, $actingUserId, $expectedClanId) {
            /** @var Family|null $family */
            $family = Family::query()->whereKey($family->id)->lockForUpdate()->first();
            if ($family === null) {
                throw ValidationException::withMessages(['family' => 'الأسرة غير موجودة.']);
            }
            if ($expectedClanId !== null && (int) $family->clan_id !== $expectedClanId) {
                throw ValidationException::withMessages(['family' => 'الأسرة لا تتبع العشيرة المتوقعة.']);
            }
            /** @var Person|null $person */
            $person = Person::query()->whereKey($person->id)->lockForUpdate()->first();
            if ($person === null) {
                throw ValidationException::withMessages(['person' => 'الشخص غير موجود.']);
            }
            if (FamilyMembership::query()->where('person_id', $person->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['person' => 'الشخص عضو نشط في أسرة أخرى.']);
            }
            if ($isHouseholdHead && FamilyMembership::query()->where('family_id', $family->id)->where('is_active', true)->where('is_household_head', true)->exists()) {
                throw ValidationException::withMessages(['family' => 'للأسرة رب أسرة نشط.']);
            }

            return FamilyMembership::create([
                'family_id' => $family->id,
                'person_id' => $person->id,
                'relationship_type_id' => $typeId,
                'is_household_head' => $isHouseholdHead,
                'started_at' => $startedAt,
                'is_active' => true,
                'created_by' => $actingUserId,
                'updated_by' => $actingUserId,
            ]);
        });
    }
}
