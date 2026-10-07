<?php

namespace App\Support\FamilyPortal;

use App\Models\AssistanceDelivery;
use App\Models\AssistanceItem;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyNeed;
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

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
                // For ordering and the opaque member_ref (FU-13); never returned.
                'family_memberships.id',
                'family_memberships.family_id',
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

    /**
     * Household health (PWA-3B.6, docs/11 §23a): every person_health_records
     * row of the Persons behind the resolved Family's ACTIVE memberships —
     * the household head included, a DECEASED member included (history), a
     * soft-deleted Person excluded (unavailable, as in the member list).
     * Health is Person-based: a member's earlier records stay theirs. Active
     * and closed (ended_at set) records alike. One query; one row per record.
     *
     * Order: membership id (grouping only); then active first; DISABILITY,
     * CHRONIC_DISEASE, PREGNANCY, BREASTFEEDING; started_at newest first,
     * unknown last; the record id. None of the ids is exposed.
     *
     * @return Collection<int, FamilyMembership>
     */
    public function health(FamilyAccessResult $context): Collection
    {
        return $this->activeMemberships($context)
            ->join('persons', 'persons.id', '=', 'family_memberships.person_id')
            ->whereNull('persons.deleted_at')
            ->join('person_health_records', 'person_health_records.person_id', '=', 'persons.id')
            ->leftJoin('disability_types', 'disability_types.id', '=', 'person_health_records.disability_type_id')
            ->select([
                // For grouping and the opaque member_ref (FU-13); never returned.
                'family_memberships.id',
                'family_memberships.family_id',
                'person_health_records.type as health_type',
                'disability_types.code as disability_type_code',
                'disability_types.name as disability_type_name',
                'person_health_records.condition_name as health_condition_name',
                'person_health_records.started_at as health_started_at',
                'person_health_records.ended_at as health_ended_at',
            ])
            ->orderBy('family_memberships.id')
            ->orderByRaw('person_health_records.ended_at IS NOT NULL')
            ->orderByRaw("CASE person_health_records.type
                WHEN 'DISABILITY' THEN 1
                WHEN 'CHRONIC_DISEASE' THEN 2
                WHEN 'PREGNANCY' THEN 3
                WHEN 'BREASTFEEDING' THEN 4
                ELSE 5 END")
            ->orderByRaw('person_health_records.started_at IS NULL')
            ->orderByDesc('person_health_records.started_at')
            ->orderBy('person_health_records.id')
            ->get();
    }

    /**
     * Household needs (PWA-3B.7, docs/11 §23a): every family_needs row of the
     * resolved Family — OPEN, FULFILLED and CLOSED alike (resolved needs are
     * history). A Need belongs to its Family for good; its Person, if any,
     * may since have left or died (the name stays) or been soft-deleted
     * (unavailable). member_ref only while that Person still has an ACTIVE
     * membership in this Family. One query.
     *
     * Order: OPEN first; created_at newest first; the need id. No id is
     * exposed.
     *
     * @return Collection<int, FamilyNeed>
     */
    public function needs(FamilyAccessResult $context): Collection
    {
        $familyId = $context->family->getKey();

        return FamilyNeed::query()
            ->where('family_needs.family_id', $familyId)
            ->join('need_categories', 'need_categories.id', '=', 'family_needs.need_category_id')
            // Plain joins: a soft-deleted Person is kept, as unavailable.
            ->leftJoin('persons', 'persons.id', '=', 'family_needs.person_id')
            ->leftJoin('family_memberships as current_membership', fn ($join) => $join
                ->on('current_membership.person_id', '=', 'family_needs.person_id')
                ->where('current_membership.family_id', $familyId)
                ->where('current_membership.is_active', true))
            ->select([
                'family_needs.status',
                'family_needs.title',
                'family_needs.quantity',
                'family_needs.unit',
                'family_needs.created_at',
                'family_needs.resolved_at',
                'family_needs.person_id',
                'need_categories.code as category_code',
                'need_categories.name as category_name',
                'persons.full_name as person_full_name',
                'persons.deleted_at as person_deleted_at',
                // For the opaque member_ref (FU-13) only; never returned.
                'current_membership.id as current_membership_id',
            ])
            ->orderByRaw("CASE WHEN family_needs.status = 'OPEN' THEN 0 ELSE 1 END")
            ->orderByDesc('family_needs.created_at')
            ->orderByDesc('family_needs.id')
            ->get();
    }

    /**
     * Received assistance (PWA-3B.7, docs/03 §47e): the NON-REVERSED
     * deliveries of INTERNAL Assistances to beneficiaries of the resolved
     * Family. Nothing else is a receipt — not a nomination, an approval, a
     * NOT_DELIVERED decision, an issued EXTERNAL list or a program's status.
     * The delivery belongs to the beneficiary's Family for good: a head
     * change, a member leaving or dying moves nothing. One query.
     *
     * Order: delivered_at newest first; the delivery id. No id is exposed.
     *
     * @return Collection<int, AssistanceDelivery>
     */
    public function assistance(FamilyAccessResult $context): Collection
    {
        $familyId = $context->family->getKey();

        return AssistanceDelivery::query()
            ->join('assistance_beneficiaries', 'assistance_beneficiaries.id', '=', 'assistance_deliveries.assistance_beneficiary_id')
            ->join('assistances', 'assistances.id', '=', 'assistance_beneficiaries.assistance_id')
            ->join('assistance_categories', 'assistance_categories.id', '=', 'assistances.assistance_category_id')
            // Plain joins: soft-deleted Persons are kept, as unavailable.
            ->leftJoin('persons as beneficiary_person', 'beneficiary_person.id', '=', 'assistance_beneficiaries.person_id')
            ->leftJoin('family_memberships as current_membership', fn ($join) => $join
                ->on('current_membership.person_id', '=', 'assistance_beneficiaries.person_id')
                ->where('current_membership.family_id', $familyId)
                ->where('current_membership.is_active', true))
            ->join('persons as recipient', 'recipient.id', '=', 'assistance_deliveries.recipient_person_id')
            ->where('assistance_beneficiaries.family_id', $familyId)
            ->whereNull('assistance_deliveries.reversed_at')
            ->where('assistances.execution_mode', 'INTERNAL')
            ->select([
                'assistance_deliveries.delivered_at',
                'assistance_deliveries.receipt_mode',
                // For the package and the opaque member_ref only; never returned.
                'assistances.id as assistance_id',
                'assistances.title as assistance_title',
                'assistances.assistance_type',
                'assistances.provider_name',
                'assistance_categories.code as category_code',
                'assistance_categories.name as category_name',
                'assistance_beneficiaries.person_id as beneficiary_person_id',
                'beneficiary_person.full_name as beneficiary_full_name',
                'beneficiary_person.deleted_at as beneficiary_deleted_at',
                'current_membership.id as current_membership_id',
                'recipient.full_name as recipient_full_name',
                'recipient.deleted_at as recipient_deleted_at',
            ])
            ->orderByDesc('assistance_deliveries.delivered_at')
            ->orderByDesc('assistance_deliveries.id')
            ->get();
    }

    /**
     * The package of each Assistance (docs/03 §47e: one delivery = every
     * item in its planned quantity; items are locked once the Assistance
     * leaves DRAFT, before any delivery can exist).
     *
     * @param  Collection<int, AssistanceDelivery>  $deliveries
     * @return BaseCollection<int, Collection<int, AssistanceItem>> keyed by assistance id
     */
    public function packages(Collection $deliveries): BaseCollection
    {
        return AssistanceItem::query()
            ->whereIn('assistance_id', $deliveries->pluck('assistance_id')->unique()->values())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['assistance_id', 'item_name', 'quantity_per_beneficiary', 'unit', 'unit_value', 'currency'])
            ->groupBy('assistance_id');
    }

    /** The authoritative population: the resolved Family's ACTIVE memberships. */
    private function activeMemberships(FamilyAccessResult $context): Builder
    {
        return FamilyMembership::query()
            ->where('family_memberships.family_id', $context->family->getKey())
            ->where('family_memberships.is_active', true);
    }
}
