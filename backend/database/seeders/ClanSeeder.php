<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use Illuminate\Database\Seeder;

/**
 * Clan structure baseline (docs/02 §7a–§7c). Only data that is explicitly
 * confirmed is seeded: the Clan عائلة البريم. Al-Breem's 16 Branch Groups
 * and their Branches are NOT seeded until the approved taxonomy is
 * supplied — add them to TAXONOMY below (never guessed).
 *
 * Idempotent and non-destructive: existing rows are never overwritten or
 * reactivated.
 */
class ClanSeeder extends Seeder
{
    /**
     * [clan code => [name, groups]]; each group:
     * ['code' => 'G01', 'name' => null|'…', 'sort_order' => 1,
     *  'branches' => [['code' => '…', 'name' => '…', 'sort_order' => 1], …]]
     */
    private const TAXONOMY = [
        Clan::AL_BREEM => [
            'name' => 'عائلة البريم',
            // Pending the approved list of 16 groups and their branches.
            'groups' => [],
        ],
    ];

    public function run(): void
    {
        foreach (self::TAXONOMY as $clanCode => $clanData) {
            $clan = Clan::firstOrCreate(['code' => $clanCode], ['name' => $clanData['name'], 'is_active' => true]);

            foreach ($clanData['groups'] as $groupData) {
                $group = BranchGroup::firstOrCreate(
                    ['clan_id' => $clan->id, 'code' => $groupData['code']],
                    ['name' => $groupData['name'] ?? null, 'sort_order' => $groupData['sort_order'] ?? 0, 'is_active' => true]
                );

                foreach ($groupData['branches'] ?? [] as $branchData) {
                    Branch::firstOrCreate(
                        ['clan_id' => $clan->id, 'code' => $branchData['code']],
                        [
                            'branch_group_id' => $group->id,
                            'name' => $branchData['name'],
                            'sort_order' => $branchData['sort_order'] ?? 0,
                            'is_active' => true,
                        ]
                    );
                }
            }
        }
    }
}
