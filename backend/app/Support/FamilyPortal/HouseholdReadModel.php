<?php

namespace App\Support\FamilyPortal;

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only queries behind the Family Portal household views (PWA-3A). Every
 * query starts from the Family the resolver put in the context — there is no
 * other way in. Eligibility is not re-checked here: FamilyAccessResolver
 * decided it (family.context).
 */
final class HouseholdReadModel
{
    /**
     * The household summary: the Family with exactly what the summary needs.
     *
     * registered_member_count is the number of ACTIVE memberships, whatever
     * the members' life status (the Staff member_count and the Coordinator
     * active_member_count count the same way). The declared household size
     * is a separate source fact from the current declaration and is never
     * derived from — or reconciled with — the members.
     */
    public function summary(FamilyAccessResult $context): Family
    {
        return $this->family($context)->firstOrFail();
    }

    /**
     * The «أسرتي» profile (PWA-3A Step 4): the summary's Family plus its
     * CURRENT residence only (is_current; at most one per Family). There is
     * no residence history to read: residence corrections are made in place.
     * Only the family-facing residence columns are selected (PWA-3B.3 adds
     * address_text, residence_type and started_at — never coordinates,
     * source, notes or lifecycle flags).
     */
    public function profile(FamilyAccessResult $context): Family
    {
        return $this->family($context)
            ->with(['currentResidence' => fn ($r) => $r->select([
                'id', 'family_id', 'original_residence_text', 'displacement_status', 'displacement_location_text',
                'governorate', 'city', 'area', 'neighborhood', 'address_text', 'residence_type', 'started_at',
            ])])
            ->firstOrFail();
    }

    /**
     * The household members: exactly one row per ACTIVE membership — the
     * same population registered_member_count counts, so the list and the
     * dashboard figure can never disagree. No Person lifecycle filter: a
     * DECEASED, UNKNOWN, inactive or soft-deleted Person keeps its row (the
     * resource turns a soft-deleted one into a placeholder). One query.
     *
     * Order: the household head; spouses; sons and daughters together;
     * fathers and mothers together; OTHER and any future code; no recorded
     * relationship. Within a group: birth date (unknown last; a soft-deleted
     * Person's is not used), then paper_sequence_no (missing last), then the
     * membership id. None of the tie-breakers is exposed.
     *
     * @return Collection<int, FamilyMembership>
     */
    public function members(FamilyAccessResult $context): Collection
    {
        $birthDate = 'CASE WHEN persons.deleted_at IS NULL THEN persons.birth_date END';

        return $this->activeMemberships($context)
            // Plain joins: soft-deleted Persons are included on purpose.
            ->leftJoin('persons', 'persons.id', '=', 'family_memberships.person_id')
            ->leftJoin('relationship_types', 'relationship_types.id', '=', 'family_memberships.relationship_type_id')
            ->select([
                'family_memberships.id',
                'family_memberships.is_household_head',
                'family_memberships.started_at as membership_started_at',
                'persons.full_name as person_full_name',
                'persons.gender as person_gender',
                'persons.birth_date as person_birth_date',
                'persons.marital_status as person_marital_status',
                'persons.life_status as person_life_status',
                'persons.death_date as person_death_date',
                // Masked by FamilyHouseholdMemberResource; never returned in full.
                'persons.national_id as person_national_id',
                'persons.mobile as person_mobile',
                'persons.alternate_mobile as person_alternate_mobile',
                'persons.alternate_mobile_owner_relation as person_alternate_mobile_owner_relation',
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
            ->orderByRaw('family_memberships.paper_sequence_no IS NULL')
            ->orderBy('family_memberships.paper_sequence_no')
            ->orderBy('family_memberships.id')
            ->get();
    }

    /**
     * «بياناتي الشخصية» (PWA-3B.1): the signed-in household head's OWN Person
     * and membership — the membership the resolver put in the context, never
     * one chosen by the client. Only the columns the self view needs are
     * read; the sensitive ones leave this class only through
     * FamilySelfResource, masked.
     */
    public function self(FamilyAccessResult $context): FamilyMembership
    {
        return FamilyMembership::query()
            ->whereKey($context->membership->getKey())
            ->where('family_memberships.person_id', $context->person->getKey())
            ->join('persons', 'persons.id', '=', 'family_memberships.person_id')
            ->leftJoin('relationship_types', 'relationship_types.id', '=', 'family_memberships.relationship_type_id')
            ->select([
                'family_memberships.is_household_head',
                'family_memberships.started_at as membership_started_at',
                'persons.full_name as person_full_name',
                'persons.national_id as person_national_id',
                'persons.gender as person_gender',
                'persons.birth_date as person_birth_date',
                'persons.marital_status as person_marital_status',
                'persons.mobile as person_mobile',
                'persons.alternate_mobile as person_alternate_mobile',
                'persons.alternate_mobile_owner_relation as person_alternate_mobile_owner_relation',
                'relationship_types.code as relationship_code',
                'relationship_types.name as relationship_name',
            ])
            ->firstOrFail();
    }

    /**
     * The resolved Family with its lineage names (branch group included),
     * registration date and paper form number, current declaration and
     * registered count. Never notes, status, registration source or ids out.
     */
    private function family(FamilyAccessResult $context): Builder
    {
        return Family::query()
            ->whereKey($context->family->getKey())
            ->select([
                'families.id', 'families.family_code', 'families.clan_id', 'families.branch_id',
                'families.registration_date', 'families.paper_form_no',
            ])
            ->addSelect(['registered_member_count' => $this->activeMemberships($context)->selectRaw('count(*)')])
            ->with([
                'clan:id,name',
                'branch:id,name,branch_group_id',
                'branch.group:id,name',
                'currentHouseholdDeclaration:id,family_id,declared_household_size,declared_living_sons,declared_living_daughters,declared_at,source',
            ]);
    }

    /** The authoritative population: the resolved Family's ACTIVE memberships. */
    private function activeMemberships(FamilyAccessResult $context): Builder
    {
        return FamilyMembership::query()
            ->where('family_memberships.family_id', $context->family->getKey())
            ->where('family_memberships.is_active', true);
    }
}
