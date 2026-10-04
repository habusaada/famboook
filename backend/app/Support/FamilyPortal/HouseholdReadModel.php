<?php

namespace App\Support\FamilyPortal;

use App\Models\Family;
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Database\Eloquent\Builder;

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
        return Family::query()
            ->whereKey($context->family->getKey())
            ->select(['families.id', 'families.family_code', 'families.clan_id', 'families.branch_id'])
            ->with([
                'clan:id,name',
                'branch:id,name',
                'currentHouseholdDeclaration:id,family_id,declared_household_size,declared_at',
            ])
            ->withCount(['memberships as registered_member_count' => fn (Builder $m) => $m->where('is_active', true)])
            ->firstOrFail();
    }
}
