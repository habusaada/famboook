<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use Illuminate\Database\Seeder;

/**
 * Clan structure baseline (docs/02 §7a–§7c): the Clan عائلة البريم and
 * its approved Branch taxonomy (approved 2026-09-25). This is seed data,
 * not a rule — administrators may add, rename, reorder or deactivate
 * groups and branches afterwards.
 *
 * Branch Groups are organizational containers without a name of their own
 * (name NULL; displayed by their Branches' names). Codes are stable and
 * never derived from database ids.
 *
 * Idempotent and non-destructive: rows are matched by code; existing rows
 * are never overwritten, reordered or reactivated. No Family is assigned
 * to a Branch here.
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
            'groups' => [
                ['code' => 'BG01', 'name' => null, 'sort_order' => 1, 'branches' => [
                    ['code' => 'BREEM_ABU_HANNUN', 'name' => 'البريم - أبو حنون', 'sort_order' => 1],
                ]],
                ['code' => 'BG02', 'name' => null, 'sort_order' => 2, 'branches' => [
                    ['code' => 'AL_JAHSH', 'name' => 'الجحش', 'sort_order' => 1],
                ]],
                ['code' => 'BG03', 'name' => null, 'sort_order' => 3, 'branches' => [
                    ['code' => 'AL_DARDEESI', 'name' => 'الدرديسي', 'sort_order' => 1],
                ]],
                ['code' => 'BG04', 'name' => null, 'sort_order' => 4, 'branches' => [
                    ['code' => 'AL_FAJM', 'name' => 'الفجم', 'sort_order' => 1],
                ]],
                ['code' => 'BG05', 'name' => null, 'sort_order' => 5, 'branches' => [
                    ['code' => 'AL_MADANI', 'name' => 'المدني', 'sort_order' => 1],
                ]],
                ['code' => 'BG06', 'name' => null, 'sort_order' => 6, 'branches' => [
                    ['code' => 'ABU_TEIM', 'name' => 'أبو تيم', 'sort_order' => 1],
                ]],
                ['code' => 'BG07', 'name' => null, 'sort_order' => 7, 'branches' => [
                    ['code' => 'ABU_TEIMA', 'name' => 'أبو تيمة', 'sort_order' => 1],
                    ['code' => 'ABU_HALAS', 'name' => 'أبو حلس', 'sort_order' => 2],
                    ['code' => 'AL_TARSHA', 'name' => 'الطرشة', 'sort_order' => 3],
                    ['code' => 'ABU_SALEM', 'name' => 'أبو سالم', 'sort_order' => 4],
                    ['code' => 'AL_SHEIBI', 'name' => 'الشيبي', 'sort_order' => 5],
                ]],
                ['code' => 'BG08', 'name' => null, 'sort_order' => 8, 'branches' => [
                    ['code' => 'ABU_DAWOUD', 'name' => 'أبو داوود', 'sort_order' => 1],
                    ['code' => 'ABU_HUSSEIN', 'name' => 'أبو حسين', 'sort_order' => 2],
                    ['code' => 'ABU_ALTHANIN', 'name' => 'أبو الثنين', 'sort_order' => 3],
                    ['code' => 'ABU_AQAB', 'name' => 'أبو عقب', 'sort_order' => 4],
                    ['code' => 'ABU_SHABAB', 'name' => 'أبو شباب', 'sort_order' => 5],
                ]],
                ['code' => 'BG09', 'name' => null, 'sort_order' => 9, 'branches' => [
                    ['code' => 'ABU_ADRAJ', 'name' => 'أبو أدرج', 'sort_order' => 1],
                ]],
                ['code' => 'BG10', 'name' => null, 'sort_order' => 10, 'branches' => [
                    ['code' => 'ABU_DEEB', 'name' => 'أبو ديب', 'sort_order' => 1],
                ]],
                ['code' => 'BG11', 'name' => null, 'sort_order' => 11, 'branches' => [
                    ['code' => 'ABU_SAADA', 'name' => 'أبو سعادة', 'sort_order' => 1],
                ]],
                ['code' => 'BG12', 'name' => null, 'sort_order' => 12, 'branches' => [
                    ['code' => 'ABU_SHEHADA', 'name' => 'أبو شحادة', 'sort_order' => 1],
                ]],
                ['code' => 'BG13', 'name' => null, 'sort_order' => 13, 'branches' => [
                    ['code' => 'ABU_ALI', 'name' => 'أبو علي', 'sort_order' => 1],
                ]],
                ['code' => 'BG14', 'name' => null, 'sort_order' => 14, 'branches' => [
                    ['code' => 'ABU_AWWAD', 'name' => 'أبو عواد', 'sort_order' => 1],
                ]],
                ['code' => 'BG15', 'name' => null, 'sort_order' => 15, 'branches' => [
                    ['code' => 'ABU_NUSEIRA', 'name' => 'أبو نصيرة', 'sort_order' => 1],
                ]],
                ['code' => 'BG16', 'name' => null, 'sort_order' => 16, 'branches' => [
                    ['code' => 'BARHAM', 'name' => 'برهم', 'sort_order' => 1],
                ]],
                ['code' => 'BG17', 'name' => null, 'sort_order' => 17, 'branches' => [
                    ['code' => 'QABLAN', 'name' => 'قبلان', 'sort_order' => 1],
                ]],
            ],
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
