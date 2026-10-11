<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Enums\LifeStatus;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Support\FamilyActivityLog;
use App\Support\RelationshipTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attaches an EXISTING Person who has no active family membership to a
 * Family as an active, non-head member (docs/05 §73 "Reuse Person", docs/11
 * FP-ADR-076). The counterpart of AddFamilyMemberAction for a Person who is
 * already in the registry.
 *
 * - Never creates, edits, merges or deletes a Person: the Person's recorded
 *   data stays exactly as it is.
 * - Never ends or moves a membership: a Person with an ACTIVE membership
 *   anywhere is refused (that would be a transfer, docs/03 §13) by
 *   CreateFamilyMembershipAction, which locks the Family and the Person and
 *   re-checks; the partial unique index uq_person_active_family_membership
 *   is the final backstop. Ended (historical) memberships are kept.
 * - Never creates a household head and never touches the existing one.
 * - Only a LIVING, ACTIVE Person may be attached (FP-ADR-076): DECEASED,
 *   UNKNOWN life status and inactive Persons are refused — checked with the
 *   Family and then the Person row locked (the canonical Family → Person
 *   order), so a concurrent death or deactivation cannot slip in between.
 * - Records FAMILY_MEMBER_ADDED, as for a new member.
 */
class AttachFamilyMemberAction
{
    public function __construct(private readonly CreateFamilyMembershipAction $memberships = new CreateFamilyMembershipAction) {}

    public function handle(Family $family, Person $person, string $relationshipCode, ?int $actingUserId): FamilyMembership
    {
        if ($relationshipCode === RelationshipTypes::HEAD) {
            throw ValidationException::withMessages(['relationship' => 'لا يمكن إضافة فرد بصفة "رب الأسرة".']);
        }

        return DB::transaction(function () use ($family, $person, $relationshipCode, $actingUserId) {
            Family::withTrashed()->whereKey($family->id)->lockForUpdate()->first();
            // A Person active anywhere is refused BEFORE their row is locked:
            // their own Family's writers (e.g. a death record) lock Person →
            // Family, so locking that Person here could close a cycle. The
            // locked re-check in CreateFamilyMembershipAction stays the authority.
            if (FamilyMembership::query()->where('person_id', $person->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['person' => 'الشخص عضو نشط في أسرة.']);
            }
            $locked = Person::query()->whereKey($person->id)->lockForUpdate()->first();
            if ($locked === null || $locked->life_status !== LifeStatus::ALIVE || ! $locked->is_active) {
                throw ValidationException::withMessages(['person' => 'لا يمكن ربط شخص متوفى أو غير مؤكد الحياة أو غير نشط بالأسرة.']);
            }

            $membership = $this->memberships->handle($family, $person, $relationshipCode, false, now()->toDateString(), $actingUserId);

            FamilyActivityLog::record($family->id, FamilyActivityType::FAMILY_MEMBER_ADDED, $person, $actingUserId);

            return $membership->load(['person', 'relationshipType']);
        });
    }
}
