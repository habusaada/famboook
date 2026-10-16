<?php

namespace Tests\Feature\Geography;

use App\Actions\ImportGazaGeographyAction;
use App\Enums\GazaLocationLevel;
use App\Enums\GazaLocationReviewIssue;
use App\Models\FamilyResidence;
use App\Models\GazaLocation;
use App\Models\GazaLocationReviewFlag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FB-GEO-01 API-D2: the read-only Family PWA mirrors of the Gaza reference
 * (governorates, children, details) for the residence change-request form.
 * Boundaries, hierarchy, inactive handling, exposure and read-only-ness.
 * Synthetic data only.
 */
class FamilyGazaLocationApiTest extends TestCase
{
    use BuildsGazaWorkbooks;
    use FamilyIdentityFixtures;
    use RefreshDatabase;

    private const URI = '/api/v1/family/change-requests/gaza-locations';

    private const KEYS = ['has_children', 'id', 'level', 'name', 'parent_id'];

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);

        $rows = [
            $this->geoRow([42, 'غزة الاختبار'], [213, 'مدينة الاختبار'], [1, 'غرب'], [10, 'حي الهدى'], [2, 76, 'مصلى', 'بلال']),
            $this->geoRow([42, 'غزة الاختبار'], [213, 'مدينة الاختبار'], [1, 'غرب'], [10, 'حي الهدى'], [1, 77, 'مسجد/جامع', 'الفاروق']),
            $this->geoRow([42, 'غزة الاختبار'], [213, 'مدينة الاختبار'], [2, 'شرق'], [12, 'حي الشجاعية'], [3, 76, 'مصلى', 'الهدى']),
            $this->geoRow([41, 'ياسمين الشمال'], [256, 'مدينة الشمال'], [16, 'منطقة الشمال'], [20, 'حي الشمال'], [5, 78, 'مدرسة', 'مدرسة الشمال']),
        ];
        app(ImportGazaGeographyAction::class)->handle($this->geoSource($this->geoWorkbook($rows), [
            ['issue' => GazaLocationReviewIssue::PARENT_PLACE_MISMATCH, 'level' => GazaLocationLevel::NEIGHBORHOOD, 'source_code' => '10', 'note' => 'Synthetic.'],
        ]));

        $this->head = $this->activatedHead()['user'];
    }

    private function id(GazaLocationLevel $level, string $code): string
    {
        return GazaLocation::idFor($level, $code);
    }

    private function family(string $uri, ?User $as = null, string $method = 'GET'): TestResponse
    {
        return $this->actingAs(($as ?? $this->head)->fresh())->json($method, self::URI.$uri);
    }

    private function staffUser(string $role): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName($role, 'web')));
    }

    // ------------------------------------------------------------ boundaries

    public function test_the_routes_sit_in_the_family_context_group(): void
    {
        $found = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/family/change-requests/gaza-locations')) {
                continue;
            }
            $found++;
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $middleware = $route->gatherMiddleware();
            foreach (['auth:sanctum', 'family.side', 'can:family-portal.access', 'family.context'] as $required) {
                $this->assertContains($required, $middleware, $route->uri());
            }
        }
        $this->assertSame(3, $found, 'governorates, children and details only');
    }

    public function test_only_a_household_member_with_a_family_context_can_read(): void
    {
        $gov = $this->id(GazaLocationLevel::GOVERNORATE, '42');

        $this->json('GET', self::URI.'/governorates')->assertUnauthorized();
        foreach (['DATA_ENTRY', 'SOCIAL_WORKER', 'SUPER_ADMIN'] as $role) {
            $this->family('/governorates', $this->staffUser($role))->assertForbidden();
            $this->family("/{$gov}/children", $this->staffUser($role))->assertForbidden();
        }
        // A coordinator-only account has no family-portal.access.
        $this->family('/governorates', $this->familyUser(['COORDINATOR']))->assertForbidden();
        // A family account never reaches the Staff lookups.
        $this->actingAs($this->head->fresh())->getJson('/api/v1/gaza-locations/governorates')->assertForbidden();
        $this->actingAs($this->head->fresh())->getJson("/api/v1/gaza-locations/{$gov}")->assertForbidden();
    }

    public function test_nothing_can_be_written(): void
    {
        $gov = $this->id(GazaLocationLevel::GOVERNORATE, '42');
        $before = [GazaLocation::count(), GazaLocationReviewFlag::count(), FamilyResidence::count()];

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->family('/governorates', null, $method)->assertStatus(405);
            $this->family("/{$gov}", null, $method)->assertStatus(405);
        }
        $this->family('/governorates')->assertOk();

        $this->assertSame($before, [GazaLocation::count(), GazaLocationReviewFlag::count(), FamilyResidence::count()]);
    }

    // ------------------------------------------------------------ hierarchy and exposure

    public function test_governorates_and_children_follow_the_hierarchy_without_staff_only_fields(): void
    {
        $governorates = $this->family('/governorates')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['ياسمين الشمال', 'غزة الاختبار'], array_column($governorates->json('data'), 'name'), 'north → south');

        $parent = $this->id(GazaLocationLevel::GOVERNORATE, '42');
        foreach (['CITY', 'AREA', 'NEIGHBORHOOD', 'LANDMARK'] as $level) {
            $children = $this->family("/{$parent}/children")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $this->assertNotEmpty($children->json('data'));
            foreach ($children->json('data') as $child) {
                $this->assertSame(self::KEYS, collect($child)->keys()->sort()->values()->all(), 'no landmark_type, code, flag or status');
                $this->assertSame($parent, $child['parent_id']);
                $this->assertSame($level, $child['level']);
            }
            $parent = $children->json('data.0.id');
        }
        $this->assertStringNotContainsString('source_code', $governorates->getContent());
        $this->assertStringNotContainsString('PARENT_PLACE_MISMATCH', $this->family('/'.$this->id(GazaLocationLevel::AREA, '1').'/children')->getContent());
    }

    public function test_details_carry_the_path_and_status_and_an_inactive_parent_lists_nothing(): void
    {
        $nei = $this->id(GazaLocationLevel::NEIGHBORHOOD, '10');
        $area = $this->id(GazaLocationLevel::AREA, '1');

        $details = $this->family("/{$nei}")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['GOVERNORATE', 'CITY', 'AREA'], array_column($details->json('data.path'), 'level'));
        $this->assertTrue($details->json('data.is_active'));
        $this->assertArrayNotHasKey('landmark_type', $details->json('data'));

        GazaLocation::whereKey($area)->update(['is_active' => false]);

        // A saved location under an inactive ancestor is still described…
        $details = $this->family("/{$nei}")->assertOk();
        $this->assertFalse(collect($details->json('data.path'))->firstWhere('id', $area)['is_active']);
        $this->assertFalse($this->family("/{$area}")->assertOk()->json('data.is_active'));
        // …but never offered: not listed, and its children are not available.
        $areas = $this->family('/'.$this->id(GazaLocationLevel::CITY, '213').'/children')->assertOk();
        $this->assertNotContains($area, array_column($areas->json('data'), 'id'));
        $this->family("/{$area}/children")->assertNotFound();

        // Malformed and unknown ids.
        $this->family('/not-a-uuid')->assertNotFound();
        $this->family('/00000000-0000-4000-8000-00000000abcd')->assertNotFound();
    }
}
