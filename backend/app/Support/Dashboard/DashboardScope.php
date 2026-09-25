<?php

namespace App\Support\Dashboard;

use App\Enums\FamilyStatus;
use App\Enums\LifeStatus;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single population foundation of the Operational Dashboard
 * (docs/03 §55a). Every section counts from these two queries, so no two
 * cards can silently count different populations.
 *
 * Families: ACTIVE, not soft-deleted, in the Clan; with a Branch Group,
 * only families whose Branch is in that group; with a Branch, only that
 * Branch. Families without a Branch count at Clan scope only.
 *
 * Current people: persons (not soft-deleted, not DECEASED) with an ACTIVE
 * membership in one of those families — the same population as family
 * targeting and the family health indicators.
 */
final class DashboardScope
{
    public function __construct(
        public readonly Clan $clan,
        public readonly ?BranchGroup $group = null,
        public readonly ?Branch $branch = null,
    ) {}

    /** Subquery selecting families.id of the scoped current families. */
    public function familyIds(): Builder
    {
        return DB::table('families')
            ->select('families.id')
            ->where('families.status', FamilyStatus::ACTIVE->value)
            ->whereNull('families.deleted_at')
            ->where('families.clan_id', $this->clan->id)
            ->when($this->branch, fn (Builder $q) => $q->where('families.branch_id', $this->branch->id))
            ->when(! $this->branch && $this->group, fn (Builder $q) => $q->whereIn(
                'families.branch_id',
                DB::table('branches')->select('id')->where('branch_group_id', $this->group->id),
            ));
    }

    /**
     * Current members of the scoped families. Aliases: m = family_memberships,
     * p = persons. A person has at most one active membership, so rows are
     * distinct people.
     */
    public function currentMembers(): Builder
    {
        return DB::table('family_memberships as m')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('m.is_active', true)
            ->whereNull('p.deleted_at')
            ->where('p.life_status', '!=', LifeStatus::DECEASED->value)
            ->whereIn('m.family_id', $this->familyIds());
    }

    /** Subquery selecting the person ids of the current members. */
    public function currentPersonIds(): Builder
    {
        return $this->currentMembers()->select('m.person_id');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'clan' => ['code' => $this->clan->code, 'name' => $this->clan->name],
            'branch_group' => $this->group ? [
                'code' => $this->group->code,
                'name' => $this->group->name,
                'display_name' => $this->group->loadMissing('branches')->displayName(),
            ] : null,
            'branch' => $this->branch ? ['code' => $this->branch->code, 'name' => $this->branch->name] : null,
        ];
    }
}
