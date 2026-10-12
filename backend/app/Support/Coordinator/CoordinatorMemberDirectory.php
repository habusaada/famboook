<?php

namespace App\Support\Coordinator;

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Support\FamilyAuth\CoordinatorAccessResult;
use App\Support\FamilyAuth\CoordinatorScopes;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only queries behind the Coordinator members directory (CO-01C, docs/06
 * AUTH-ADR-092). Every Family comes from CoordinatorScopes::families() — the
 * context the `coordinator.space` boundary resolved on THIS request — and
 * every member from that Family's ACTIVE memberships. There is no other way
 * in: no route-model binding, no lookup by id, nothing authorized afterwards.
 */
final class CoordinatorMemberDirectory
{
    public function __construct(private readonly CoordinatorScopes $scopes) {}

    /**
     * The Family with that code inside the Coordinator's scope, with exactly
     * what the details need, or NULL (out of scope and nonexistent alike).
     *
     * registered_member_count counts ACTIVE memberships (as the summary's
     * active_member_count); the declared household size is the current
     * declaration's separate source fact, never derived from the members.
     */
    public function family(CoordinatorAccessResult $context, string $familyCode): ?Family
    {
        return $this->scopes->families($context)
            ->where('families.family_code', $familyCode)
            ->select(['families.id', 'families.family_code', 'families.clan_id', 'families.branch_id'])
            ->addSelect(['registered_member_count' => FamilyMembership::query()
                ->whereColumn('family_memberships.family_id', 'families.id')
                ->where('family_memberships.is_active', true)
                ->selectRaw('count(*)')])
            ->with([
                'clan:id,name',
                'branch:id,name,branch_group_id',
                'branch.group:id,name',
                'householdHeadMembership:id,family_id,person_id,is_household_head,is_active',
                'householdHeadMembership.person:id,full_name',
                'currentHouseholdDeclaration:id,family_id,declared_household_size,declared_at',
            ])
            ->first();
    }

    /**
     * The identity of the Family with that code inside the Coordinator's
     * scope, or NULL — for the reveal, which needs nothing else.
     */
    public function familyIdentity(CoordinatorAccessResult $context, string $familyCode): ?Family
    {
        return $this->scopes->families($context)
            ->where('families.family_code', $familyCode)
            ->first(['families.id']);
    }

    /**
     * One row per ACTIVE membership of the (already scoped) Family, in the
     * household order. A soft-deleted Person keeps the row as a placeholder.
     * The National ID and mobile are selected for MASKING only
     * (CoordinatorMemberResource); the full values leave the server only
     * through CoordinatorMemberSensitiveReveal. Nothing else of the Person —
     * no health, notes, documents, account or auth data — is read.
     *
     * @return Collection<int, FamilyMembership>
     */
    public function members(Family $family): Collection
    {
        $birthDate = 'CASE WHEN persons.deleted_at IS NULL THEN persons.birth_date END';

        return FamilyMembership::query()
            ->where('family_memberships.family_id', $family->getKey())
            ->where('family_memberships.is_active', true)
            // Plain join: soft-deleted Persons stay as placeholders.
            ->leftJoin('persons', 'persons.id', '=', 'family_memberships.person_id')
            ->leftJoin('relationship_types', 'relationship_types.id', '=', 'family_memberships.relationship_type_id')
            ->select([
                // For ordering and the opaque reference; never returned.
                'family_memberships.id',
                'family_memberships.family_id',
                'family_memberships.is_household_head',
                'persons.full_name as person_full_name',
                'persons.gender as person_gender',
                'persons.birth_date as person_birth_date',
                'persons.marital_status as person_marital_status',
                'persons.life_status as person_life_status',
                'persons.national_id as person_national_id',
                'persons.mobile as person_mobile',
                'persons.deleted_at as person_deleted_at',
                'relationship_types.code as relationship_code',
                'relationship_types.name as relationship_name',
            ])
            ->orderByDesc('family_memberships.is_household_head')
            ->orderByRaw("CASE
                WHEN relationship_types.code IS NULL THEN 5
                WHEN relationship_types.code = 'SPOUSE' THEN 1
                WHEN relationship_types.code IN ('SON', 'DAUGHTER') THEN 2
                WHEN relationship_types.code IN ('FATHER', 'MOTHER') THEN 3
                ELSE 4 END")
            ->orderByRaw("({$birthDate}) IS NULL")
            ->orderByRaw($birthDate)
            ->orderBy('family_memberships.id')
            ->get();
    }
}
