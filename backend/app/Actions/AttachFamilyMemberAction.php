<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
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
            $membership = $this->memberships->handle($family, $person, $relationshipCode, false, now()->toDateString(), $actingUserId);

            FamilyActivityLog::record($family->id, FamilyActivityType::FAMILY_MEMBER_ADDED, $person, $actingUserId);

            return $membership->load(['person', 'relationshipType']);
        });
    }
}
