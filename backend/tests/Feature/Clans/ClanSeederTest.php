<?php

namespace Tests\Feature\Clans;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\Family;
use Database\Seeders\ClanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The approved Al-Breem taxonomy seeded by ClanSeeder (seed baseline only;
 * not a business rule on the number of groups).
 */
class ClanSeederTest extends TestCase
{
    use RefreshDatabase;

    /** Approved 2026-09-25: group code => [branch code => Arabic name]. */
    private const EXPECTED = [
        'BG01' => ['BREEM_ABU_HANNUN' => 'البريم - أبو حنون'],
        'BG02' => ['AL_JAHSH' => 'الجحش'],
        'BG03' => ['AL_DARDEESI' => 'الدرديسي'],
        'BG04' => ['AL_FAJM' => 'الفجم'],
        'BG05' => ['AL_MADANI' => 'المدني'],
        'BG06' => ['ABU_TEIM' => 'أبو تيم'],
        'BG07' => [
            'ABU_TEIMA' => 'أبو تيمة',
            'ABU_HALAS' => 'أبو حلس',
            'AL_TARSHA' => 'الطرشة',
            'ABU_SALEM' => 'أبو سالم',
            'AL_SHEIBI' => 'الشيبي',
        ],
        'BG08' => [
            'ABU_DAWOUD' => 'أبو داوود',
            'ABU_HUSSEIN' => 'أبو حسين',
            'ABU_ALTHANIN' => 'أبو الثنين',
            'ABU_AQAB' => 'أبو عقب',
            'ABU_SHABAB' => 'أبو شباب',
        ],
        'BG09' => ['ABU_ADRAJ' => 'أبو أدرج'],
        'BG10' => ['ABU_DEEB' => 'أبو ديب'],
        'BG11' => ['ABU_SAADA' => 'أبو سعادة'],
        'BG12' => ['ABU_SHEHADA' => 'أبو شحادة'],
        'BG13' => ['ABU_ALI' => 'أبو علي'],
        'BG14' => ['ABU_AWWAD' => 'أبو عواد'],
        'BG15' => ['ABU_NUSEIRA' => 'أبو نصيرة'],
        'BG16' => ['BARHAM' => 'برهم'],
        'BG17' => ['QABLAN' => 'قبلان'],
    ];

    private function alBreem(): Clan
    {
        return Clan::where('code', Clan::AL_BREEM)->firstOrFail();
    }

    /** @return array<string, array<string, string>> */
    private function tree(): array
    {
        $tree = [];
        foreach ($this->alBreem()->branchGroups()->with('branches')->get() as $group) {
            $tree[$group->code] = $group->branches->pluck('name', 'code')->all();
        }

        return $tree;
    }

    public function test_seeds_the_approved_taxonomy_in_order(): void
    {
        $this->seed(ClanSeeder::class);

        $clan = $this->alBreem();
        $this->assertSame('عائلة البريم', $clan->name);

        $groups = $clan->branchGroups()->get();
        $this->assertCount(17, $groups);
        $this->assertSame(array_keys(self::EXPECTED), $groups->pluck('code')->all());
        $this->assertSame(range(1, 17), $groups->pluck('sort_order')->all());
        // No invented group names; all active.
        $this->assertSame([], $groups->whereNotNull('name')->pluck('code')->all());
        $this->assertTrue($groups->every->is_active);

        // Exact Arabic names, exact membership, exact order.
        $this->assertSame(self::EXPECTED, $this->tree());
        $this->assertSame(25, Branch::where('clan_id', $clan->id)->where('is_active', true)->count());
    }

    public function test_multi_branch_and_single_branch_groups(): void
    {
        $this->seed(ClanSeeder::class);
        $tree = $this->tree();

        $this->assertCount(5, $tree['BG07']);
        $this->assertCount(5, $tree['BG08']);
        foreach ($tree as $code => $branches) {
            if (! in_array($code, ['BG07', 'BG08'], true)) {
                $this->assertCount(1, $branches, $code);
            }
        }
        $bg08 = BranchGroup::where('code', 'BG08')->with('branches')->firstOrFail();
        $this->assertSame(range(1, 5), $bg08->branches->pluck('sort_order')->all());
        // Unnamed groups display by their branches' names.
        $this->assertSame('أبو ديب', BranchGroup::where('code', 'BG10')->with('branches')->firstOrFail()->displayName());
        $this->assertSame(
            'أبو تيمة / أبو حلس / الطرشة / أبو سالم / الشيبي',
            BranchGroup::where('code', 'BG07')->with('branches')->firstOrFail()->displayName()
        );
    }

    public function test_seeder_is_idempotent_and_preserves_admin_changes(): void
    {
        $this->seed(ClanSeeder::class);

        // Administrator edits after the first seed.
        $group = BranchGroup::where('code', 'BG03')->firstOrFail();
        $group->update(['is_active' => false, 'sort_order' => 40, 'name' => 'اسم إداري تجريبي']);
        $branch = Branch::where('code', 'ABU_SALEM')->firstOrFail();
        $branch->update(['is_active' => false, 'sort_order' => 9, 'name' => 'اسم معدل تجريبي']);

        $this->seed(ClanSeeder::class);
        $this->seed(ClanSeeder::class);

        $this->assertSame(1, Clan::where('code', Clan::AL_BREEM)->count());
        $this->assertSame(17, BranchGroup::where('clan_id', $this->alBreem()->id)->count());
        $this->assertSame(25, Branch::where('clan_id', $this->alBreem()->id)->count());

        $group->refresh();
        $this->assertFalse($group->is_active);
        $this->assertSame(40, $group->sort_order);
        $this->assertSame('اسم إداري تجريبي', $group->name);
        $branch->refresh();
        $this->assertFalse($branch->is_active);
        $this->assertSame(9, $branch->sort_order);
        $this->assertSame('اسم معدل تجريبي', $branch->name);
    }

    public function test_no_family_is_assigned_or_reassigned(): void
    {
        $families = Family::factory()->count(3)->create();
        $before = DB::table('families')->orderBy('id')->get(['id', 'family_code', 'clan_id', 'branch_id', 'updated_at']);

        $this->seed(ClanSeeder::class);

        $after = DB::table('families')->orderBy('id')->get(['id', 'family_code', 'clan_id', 'branch_id', 'updated_at']);
        $this->assertEquals($before, $after);
        foreach ($after as $row) {
            $this->assertSame($this->alBreem()->id, (int) $row->clan_id);
            $this->assertNull($row->branch_id);
        }
        $this->assertSame(0, Family::whereNotNull('branch_id')->count());
        $this->assertCount(3, $families);
    }
}
