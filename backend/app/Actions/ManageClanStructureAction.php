<?php

namespace App\Actions;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Administration of the Clan → (optional Branch Group) → Branch reference
 * structure (docs/03 §7a, permission clan.manage). Codes are fixed at
 * creation; names, order and active state can change. Nothing is ever
 * deleted: deactivating only prevents NEW selection, existing families keep
 * and display their Clan/Branch. A Group never moves to another Clan. A
 * Branch never changes Clan, but may be created ungrouped and later be
 * assigned to, moved between or removed from Groups of its own Clan.
 */
class ManageClanStructureAction
{
    /**
     * Only the Clan: never Branch Groups or Branches (also used by the Import
     * Wizard's "create Clan" step). Active unless explicitly created inactive.
     *
     * @param  array{code: string, name: string, is_active?: bool}  $data
     */
    public function createClan(array $data): Clan
    {
        return DB::transaction(fn () => Clan::create([...$data, 'is_active' => $data['is_active'] ?? true]));
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

    /**
     * A Branch of the Clan, optionally classified under one of the Clan's
     * Branch Groups (NULL = ungrouped).
     *
     * @param  array<string, mixed>  $data  code / name / sort_order
     */
    public function createBranch(Clan $clan, ?BranchGroup $group, array $data): Branch
    {
        $this->assertGroupOfClan($group, $clan->id);

        return DB::transaction(function () use ($clan, $group, $data) {
            return Branch::create([
                'branch_group_id' => $group?->id,
                'clan_id' => $clan->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'sort_order' => $data['sort_order'] ?? ((int) $this->siblings($clan->id, $group)->max('sort_order') + 1),
                'is_active' => true,
            ]);
        });
    }

    /**
     * name / sort_order / is_active, and — when the key is present —
     * `branch_group`: a Group of the SAME Clan to assign or move to, or null
     * to ungroup. The Branch's Clan and code never change.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateBranch(Branch $branch, array $data): Branch
    {
        if (array_key_exists('branch_group', $data)) {
            $this->assertGroupOfClan($data['branch_group'], $branch->clan_id);
        }

        return DB::transaction(function () use ($branch, $data) {
            $branch->fill(array_intersect_key($data, array_flip(['name', 'sort_order', 'is_active'])));
            if (array_key_exists('branch_group', $data)) {
                $branch->branch_group_id = $data['branch_group']?->id;
            }
            $branch->save();

            return $branch;
        });
    }

    /** Cross-Clan grouping is refused (also enforced by the composite FK). */
    private function assertGroupOfClan(?BranchGroup $group, int $clanId): void
    {
        if ($group !== null && $group->clan_id !== $clanId) {
            throw ValidationException::withMessages([
                'branch_group_id' => 'مجموعة الفروع لا تتبع العشيرة / العائلة نفسها.',
            ]);
        }
    }

    /** Branches sharing the group (or, when ungrouped, the Clan's ungrouped ones). */
    private function siblings(int $clanId, ?BranchGroup $group): Builder
    {
        return Branch::query()->where('clan_id', $clanId)
            ->when($group, fn (Builder $q) => $q->where('branch_group_id', $group->id),
                fn (Builder $q) => $q->whereNull('branch_group_id'));
    }
}
