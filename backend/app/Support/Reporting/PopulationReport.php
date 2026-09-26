<?php

namespace App\Support\Reporting;

use App\Models\BranchGroup;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Report 1 — Population & Families (docs/03 §55b). Aggregates over the
 * shared scope; the organizational breakdown lists Branch Groups (with
 * their Branches) at Clan scope, plus a separate "unassigned" row for
 * families without a Branch, and the group's Branches at group scope.
 */
final class PopulationReport
{
    private readonly PopulationAggregates $aggregates;

    public function __construct(private readonly OrganizationalScope $scope, CarbonInterface $today)
    {
        $this->aggregates = new PopulationAggregates($scope, $today);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $demographics = $this->aggregates->demographics();
        $displacement = $this->aggregates->displacement(topLocations: 10);

        return [
            'summary' => [
                'active_families' => $displacement['total_families'],
                'current_people' => $demographics['total'],
                'male' => $demographics['gender']['male'],
                'female' => $demographics['gender']['female'],
                'unknown_gender' => $demographics['gender']['unknown'],
                'displaced' => $displacement['displaced'],
                'not_displaced' => $displacement['not_displaced'],
                'unknown_displacement' => $displacement['unknown'],
            ],
            'age_bands' => $demographics['age_bands'],
            'organization' => $this->organization(),
            'top_locations' => $displacement['top_locations'],
        ];
    }

    /**
     * Families and current people per Branch, assembled into the Clan's
     * group → branch structure. Two grouped queries, whatever the number
     * of groups and branches.
     *
     * @return array<string, mixed>|null
     */
    private function organization(): ?array
    {
        if ($this->scope->level() === 'BRANCH') {
            return null;
        }

        $families = DB::table('families as f')
            ->whereIn('f.id', $this->scope->familyIds())
            ->selectRaw('f.branch_id as branch_id, count(*) as total')
            ->groupBy('f.branch_id')
            ->pluck('total', 'branch_id');
        $people = $this->scope->currentMembers()
            ->join('families as f', 'f.id', '=', 'm.family_id')
            ->selectRaw('f.branch_id as branch_id, count(*) as total')
            ->groupBy('f.branch_id')
            ->pluck('total', 'branch_id');
        // pluck() keys a NULL branch_id as "".
        $count = fn ($counts, ?int $branchId) => (int) ($counts[$branchId ?? ''] ?? 0);

        $groups = $this->scope->group
            ? collect([$this->scope->group->loadMissing('branches')])
            : $this->scope->clan->branchGroups()->with('branches')->get();

        $rows = $groups->map(function (BranchGroup $group) use ($families, $people, $count) {
            $branches = $group->branches->map(fn ($b) => [
                'code' => $b->code,
                'name' => $b->name,
                'is_active' => $b->is_active,
                'families' => $count($families, $b->id),
                'people' => $count($people, $b->id),
            ])->values();

            return [
                'code' => $group->code,
                'name' => $group->name,
                'display_name' => $group->displayName(),
                'is_active' => $group->is_active,
                'families' => $branches->sum('families'),
                'people' => $branches->sum('people'),
                'branches' => $branches->all(),
            ];
        })->values()->all();

        return [
            'level' => $this->scope->level(),
            'groups' => $rows,
            // Families without a Branch: a separate row at Clan scope only,
            // never attributed to a group.
            'unassigned' => $this->scope->level() === 'CLAN' ? [
                'families' => $count($families, null),
                'people' => $count($people, null),
            ] : null,
        ];
    }
}
