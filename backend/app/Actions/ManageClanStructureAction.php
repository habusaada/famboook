<?php

namespace App\Actions;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use Illuminate\Support\Facades\DB;

/**
 * Administration of the Clan → Branch Group → Branch reference structure
 * (docs/03 §7a, permission clan.manage). Codes are fixed at creation;
 * names, order and active state can change. Nothing is ever deleted:
 * deactivating only prevents NEW selection, existing families keep and
 * display their Clan/Branch. A Group never moves to another Clan and a
 * Branch never moves to another Group in V1.
 */
class ManageClanStructureAction
{
    /** @param array{code: string, name: string} $data */
    public function createClan(array $data): Clan
    {
        return DB::transaction(fn () => Clan::create([...$data, 'is_active' => true]));
    }

    /** @param array<string, mixed> $data name / is_active */
    public function updateClan(Clan $clan, array $data): Clan
    {
        return DB::transaction(function () use ($clan, $data) {
            $clan->fill(array_intersect_key($data, array_flip(['name', 'is_active'])))->save();

            return $clan;
        });
    }

    /** @param array<string, mixed> $data code / name / sort_order */
    public function createGroup(Clan $clan, array $data): BranchGroup
    {
        return DB::transaction(function () use ($clan, $data) {
            return BranchGroup::create([
                'clan_id' => $clan->id,
                'code' => $data['code'],
                'name' => $data['name'] ?? null,
                'sort_order' => $data['sort_order'] ?? ((int) $clan->branchGroups()->max('sort_order') + 1),
                'is_active' => true,
            ]);
        });
    }

    /** @param array<string, mixed> $data name / sort_order / is_active */
    public function updateGroup(BranchGroup $group, array $data): BranchGroup
    {
        return DB::transaction(function () use ($group, $data) {
            $group->fill(array_intersect_key($data, array_flip(['name', 'sort_order', 'is_active'])))->save();

            return $group;
        });
    }

    /** @param array<string, mixed> $data code / name / sort_order */
    public function createBranch(BranchGroup $group, array $data): Branch
    {
        return DB::transaction(function () use ($group, $data) {
            return Branch::create([
                'branch_group_id' => $group->id,
                // Mirrors the group's Clan (composite FK keeps them equal).
                'clan_id' => $group->clan_id,
                'code' => $data['code'],
                'name' => $data['name'],
                'sort_order' => $data['sort_order'] ?? ((int) $group->branches()->max('sort_order') + 1),
                'is_active' => true,
            ]);
        });
    }

    /** @param array<string, mixed> $data name / sort_order / is_active */
    public function updateBranch(Branch $branch, array $data): Branch
    {
        return DB::transaction(function () use ($branch, $data) {
            $branch->fill(array_intersect_key($data, array_flip(['name', 'sort_order', 'is_active'])))->save();

            return $branch;
        });
    }
}
