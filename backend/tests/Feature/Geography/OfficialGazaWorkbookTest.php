<?php

namespace Tests\Feature\Geography;

use App\Actions\ImportGazaGeographyAction;
use App\Enums\GazaLocationLevel;
use App\Models\GazaLocation;
use App\Models\GazaLocationReviewFlag;
use App\Models\User;
use App\Support\Geography\GazaGeographySource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceptance of the audited MoSD workbook (FB-GEO-01 Stage 1A counts). The
 * workbook is kept outside Git (Stage 1B D-10), so this test is SKIPPED when
 * it is not present at data/references/ beside backend/. The counts are
 * acceptance criteria for this source version only.
 */
class OfficialGazaWorkbookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $source = GazaGeographySource::official();
        if (! is_file($source->path)) {
            $this->markTestSkipped('Official Gaza workbook not present (kept outside Git): '.$source->path);
        }
    }

    private function location(GazaLocationLevel $level, string $code): GazaLocation
    {
        return GazaLocation::query()->where('level', $level->value)->where('source_code', $code)->firstOrFail();
    }

    public function test_the_audited_workbook_imports_with_the_stage_1a_counts_and_findings(): void
    {
        $report = app(ImportGazaGeographyAction::class)->handle(GazaGeographySource::official());

        $expected = ['GOVERNORATE' => 5, 'CITY' => 26, 'AREA' => 28, 'NEIGHBORHOOD' => 234, 'LANDMARK' => 1846];
        $this->assertSame($expected, $report['source']);
        $this->assertSame($expected, $report['created']);
        $this->assertSame(2139, GazaLocation::count());
        foreach ($expected as $level => $count) {
            $this->assertSame($count, GazaLocation::where('level', $level)->count());
        }
        $this->assertSame(0, GazaLocation::where('is_active', false)->count());

        $this->assertSame(
            ['41' => 'شمال غزة', '42' => 'غزة', '43' => 'الوسطى', '44' => 'خان يونس', '45' => 'رفح'],
            GazaLocation::where('level', 'GOVERNORATE')->orderBy('source_code')->pluck('name', 'source_code')->all(),
        );

        // Every non-governorate has a parent of the level directly above.
        foreach (GazaLocation::with('parent')->where('level', '!=', 'GOVERNORATE')->get() as $node) {
            $this->assertSame($node->level->parent(), $node->parent->level, "{$node->level->value}:{$node->source_code}");
        }

        // Source text as supplied (Stage 1A §7.2, §6.2).
        $this->assertSame('منطقة رقم 7 ', $this->location(GazaLocationLevel::NEIGHBORHOOD, '348')->name);
        $this->assertSame('منطقة رقم 7', $this->location(GazaLocationLevel::NEIGHBORHOOD, '352')->name);
        $unusual = $this->location(GazaLocationLevel::LANDMARK, '1218');
        $this->assertSame(['8', 'قتل'], [$unusual->landmark_type_code, $unusual->landmark_type_name]);
        // Two mosques of one neighborhood with one name, distinct codes (Stage 1A W9).
        $this->assertSame($this->location(GazaLocationLevel::LANDMARK, '1189')->name, $this->location(GazaLocationLevel::LANDMARK, '397')->name);

        $this->assertSame([
            'PARENT_PLACE_MISMATCH' => 10,
            'UNUSUAL_LANDMARK_TYPE' => 5,
            'SAME_PARENT_SIMILAR_NAME' => 8,
            'POSSIBLE_DUPLICATE_LANDMARK' => 14,
            'NAME_WHITESPACE' => 191,
        ], $report['flags_created']);
        $this->assertSame(228, GazaLocationReviewFlag::count());

        $again = app(ImportGazaGeographyAction::class)->handle(GazaGeographySource::official());
        $this->assertSame(0, array_sum($again['created']));
        $this->assertSame(0, array_sum($again['flags_created']));
        $this->assertSame(2139, GazaLocation::count());
    }

    public function test_the_api_lists_the_official_governorates_north_to_south(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app(ImportGazaGeographyAction::class)->handle(GazaGeographySource::official());
        $user = User::factory()->create();
        $user->assignRole('SOCIAL_WORKER');

        $response = $this->actingAs($user)->getJson('/api/v1/gaza-locations/governorates')->assertOk();

        // Official codes 41 → 45 (Stage 1A §3.2).
        $this->assertSame(['شمال غزة', 'غزة', 'الوسطى', 'خان يونس', 'رفح'], array_column($response->json('data'), 'name'));

        // A neighborhood whose source name has a trailing space is shown trimmed.
        $nei = GazaLocation::idFor(GazaLocationLevel::NEIGHBORHOOD, '348');
        $this->actingAs($user)->getJson("/api/v1/gaza-locations/{$nei}")
            ->assertOk()
            ->assertJsonPath('data.name', 'منطقة رقم 7')
            ->assertJsonPath('data.path.0.name', 'الوسطى');
    }
}
