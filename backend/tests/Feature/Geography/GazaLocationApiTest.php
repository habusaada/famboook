<?php

namespace Tests\Feature\Geography;

use App\Actions\ImportGazaGeographyAction;
use App\Enums\GazaLocationLevel;
use App\Enums\GazaLocationReviewIssue;
use App\Http\Middleware\EnsureStaffSideAccount;
use App\Models\GazaLocation;
use App\Models\GazaLocationReviewFlag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * FB-GEO-01 Stage 2B: the read-only Staff Gaza location API (API-D1,
 * API-D4). SYNTHETIC geography imported through the real import action:
 *
 *   GOV 41..45 — codes north→south; names deliberately NOT in code order,
 *                and source rows in neither order
 *   GOV 42 → CITY 213 → AREA 1 → NEI 10 «حي الهدى» → LAN 1 «الفاروق» (مسجد/جامع), LAN 2 «بلال» (مصلى)
 *                              → NEI 11 «حي النور » (trailing space) → LAN 9
 *                     → AREA 2 → NEI 12 «حي الشجاعية» → LAN 3 «الهدى» (مصلى)
 *          → CITY 214 → AREA 3 → NEI 13 «حي الهدى» (same name, other parent; audited flag) → LAN 4
 *   GOV 41/43/44/45 → one city, area, neighborhood and landmark each; LAN 7 has an audited unusual type
 */
class GazaLocationApiTest extends TestCase
{
    use BuildsGazaWorkbooks;
    use RefreshDatabase;

    private const RESOURCE_KEYS = ['has_children', 'id', 'landmark_type', 'level', 'name', 'parent_id'];

    private const GOVERNORATES = [
        41 => 'ياسمين الشمال', 42 => 'غزة الاختبار', 43 => 'وسطى الاختبار', 44 => 'خان الاختبار', 45 => 'باب الجنوب',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $gov = fn (int $code) => [$code, self::GOVERNORATES[$code]];
        $city213 = [213, 'مدينة الاختبار'];
        $rows = [
            $this->geoRow($gov(45), [216, 'مدينة الجنوب'], [13, 'منطقة الجنوب'], [50, 'حي الجنوب'], [8, 78, 'مدرسة', 'مدرسة الجنوب']),
            $this->geoRow($gov(42), $city213, [1, 'غرب'], [10, 'حي الهدى'], [2, 76, 'مصلى', 'بلال']),
            $this->geoRow($gov(43), [218, 'مدينة الوسط'], [8, 'منطقة الوسط'], [30, 'حي الوسط'], [6, 78, 'مدرسة', 'مدرسة الوسط']),
            $this->geoRow($gov(42), $city213, [1, 'غرب'], [10, 'حي الهدى'], [1, 77, 'مسجد/جامع', 'الفاروق']),
            $this->geoRow($gov(41), [256, 'مدينة الشمال'], [16, 'منطقة الشمال'], [20, 'حي الشمال'], [5, 78, 'مدرسة', 'مدرسة الشمال']),
            $this->geoRow($gov(42), $city213, [2, 'شرق'], [12, 'حي الشجاعية'], [3, 76, 'مصلى', 'الهدى']),
            $this->geoRow($gov(44), [217, 'مدينة الساحل'], [14, 'منطقة الساحل'], [40, 'حي المواصي'], [7, 8, 'نوع غير مألوف', 'نقطة']),
            $this->geoRow($gov(42), [214, 'الزهراء التجريبية'], [3, 'الزهراء'], [13, 'حي الهدى'], [4, 78, 'مدرسة', 'مدرسة الزهراء']),
            $this->geoRow($gov(42), $city213, [1, 'غرب'], [11, 'حي النور '], [9, 78, 'مدرسة', 'مدرسة النور']),
        ];
        $path = $this->geoWorkbook($rows);
        app(ImportGazaGeographyAction::class)->handle($this->geoSource($path, [
            ['issue' => GazaLocationReviewIssue::UNUSUAL_LANDMARK_TYPE, 'level' => GazaLocationLevel::LANDMARK, 'source_code' => '7', 'note' => 'Synthetic.'],
            ['issue' => GazaLocationReviewIssue::PARENT_PLACE_MISMATCH, 'level' => GazaLocationLevel::NEIGHBORHOOD, 'source_code' => '13', 'note' => 'Synthetic.'],
        ]));
    }

    /** @param list<string> $roles */
    private function staff(array $roles = ['DATA_ENTRY']): User
    {
        $user = User::factory()->create();
        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function id(GazaLocationLevel $level, string $code): string
    {
        return GazaLocation::idFor($level, $code);
    }

    private function api(string $uri, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->staff())->getJson('/api/v1/gaza-locations'.$uri);
    }

    /** @return list<string> */
    private function names(TestResponse $response): array
    {
        return array_column($response->json('data'), 'name');
    }

    /** @return list<string> every staff endpoint, with real ids */
    private function endpoints(): array
    {
        $nei = $this->id(GazaLocationLevel::NEIGHBORHOOD, '10');

        return ['/governorates', '/search?q=الهدى', "/{$nei}/children", "/{$nei}"];
    }

    // ------------------------------------------------------------ Governorates

    public function test_governorates_are_listed_north_to_south_by_official_code(): void
    {
        $response = $this->api('/governorates')->assertOk();

        $this->assertSame(array_values(self::GOVERNORATES), $this->names($response), 'code order 41 → 45, not name or source order');
        foreach ($response->json('data') as $governorate) {
            $this->assertSame(self::RESOURCE_KEYS, collect($governorate)->keys()->sort()->values()->all());
            $this->assertSame('GOVERNORATE', $governorate['level']);
            $this->assertNull($governorate['parent_id']);
            $this->assertTrue($governorate['has_children']);
            $this->assertNull($governorate['landmark_type']);
        }
    }

    // ------------------------------------------------------------ Children

    public function test_children_walk_every_level_sorted_by_name(): void
    {
        $cities = $this->api('/'.$this->id(GazaLocationLevel::GOVERNORATE, '42').'/children')->assertOk();
        $this->assertSame(['الزهراء التجريبية', 'مدينة الاختبار'], $this->names($cities));
        $this->assertSame(['CITY', 'CITY'], array_column($cities->json('data'), 'level'));

        $areas = $this->api('/'.$this->id(GazaLocationLevel::CITY, '213').'/children')->assertOk();
        $this->assertSame(['شرق', 'غرب'], $this->names($areas));

        $neighborhoods = $this->api('/'.$this->id(GazaLocationLevel::AREA, '1').'/children')->assertOk();
        // «حي النور » is stored with its trailing space and shown trimmed.
        $this->assertSame(['حي النور', 'حي الهدى'], $this->names($neighborhoods));
        $this->assertSame('حي النور ', GazaLocation::find($this->id(GazaLocationLevel::NEIGHBORHOOD, '11'))->name);

        $landmarks = $this->api('/'.$this->id(GazaLocationLevel::NEIGHBORHOOD, '10').'/children')->assertOk();
        $this->assertSame(['الفاروق', 'بلال'], $this->names($landmarks));
        $this->assertSame(['مسجد/جامع', 'مصلى'], array_column($landmarks->json('data'), 'landmark_type'));
        $this->assertSame([false, false], array_column($landmarks->json('data'), 'has_children'));
        $this->assertSame($this->id(GazaLocationLevel::NEIGHBORHOOD, '10'), $landmarks->json('data.0.parent_id'));
    }

    public function test_a_landmark_has_no_children(): void
    {
        $this->api('/'.$this->id(GazaLocationLevel::LANDMARK, '1').'/children')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    // ------------------------------------------------------------ Search

    public function test_arabic_search_returns_every_level_with_its_path_and_no_normalization(): void
    {
        $response = $this->api('/search?q=الهدى')->assertOk();

        // Two neighborhoods first (broader level), then the landmark.
        $this->assertSame(['NEIGHBORHOOD', 'NEIGHBORHOOD', 'LANDMARK'], array_column($response->json('data'), 'level'));
        $this->assertSame(['حي الهدى', 'حي الهدى', 'الهدى'], $this->names($response));

        // Identical names under different parents are told apart by their paths.
        $paths = collect($response->json('data'))->take(2)
            ->map(fn ($item) => implode(' / ', array_column($item['path'], 'name')))->sort()->values()->all();
        $this->assertSame(['غزة الاختبار / الزهراء التجريبية / الزهراء', 'غزة الاختبار / مدينة الاختبار / غرب'], $paths);

        $landmark = $response->json('data.2');
        $this->assertSame(['GOVERNORATE', 'CITY', 'AREA', 'NEIGHBORHOOD'], array_column($landmark['path'], 'level'));
        $this->assertSame([...self::RESOURCE_KEYS, 'path'], collect($landmark)->keys()->sort()->values()->all());

        // PDD-003: no Arabic normalization (ي does not match ى).
        $this->api('/search?q=الهدي')->assertOk()->assertJsonCount(0, 'data');
        // A trailing space in the stored name never hides a match.
        $this->assertSame(['حي النور'], $this->names($this->api('/search?q=النور&level=NEIGHBORHOOD')));
    }

    public function test_search_is_paginated_with_a_maximum_page_size(): void
    {
        $page = $this->api('/search?q=حي&per_page=3')->assertOk();
        $page->assertJsonCount(3, 'data');
        $page->assertJsonPath('meta.total', 8);
        $page->assertJsonPath('meta.per_page', 3);
        $page->assertJsonPath('meta.last_page', 3);
        $this->assertArrayHasKey('links', $page->json());

        $all = collect([1, 2, 3])->flatMap(fn ($n) => array_column($this->api("/search?q=حي&per_page=3&page={$n}")->json('data'), 'id'));
        $this->assertCount(8, $all->unique(), 'deterministic order: no repeats or gaps across pages');

        $this->api('/search?q=حي')->assertJsonPath('meta.per_page', 20);
        $this->api('/search?q=حي&per_page=50')->assertOk();
        $this->api('/search?q=حي&per_page=51')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->api('/search?q=حي&per_page=0')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_search_validates_its_parameters(): void
    {
        $this->api('/search')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->api('/search?q=ا')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->api('/search?q='.str_repeat('ا', 101))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->api('/search?q=حي&level=REGION')->assertUnprocessable()->assertJsonValidationErrors('level');
        $this->api('/search?q=حي&parent_id=not-a-uuid')->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->api('/search?q=حي&parent_id='.Str::uuid())->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->api('/search?q=حي&page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
        // Wildcards are literal.
        $this->api('/search?q=%25%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_search_by_level(): void
    {
        $response = $this->api('/search?q=الهدى&level=LANDMARK')->assertOk();

        $this->assertSame(['الهدى'], $this->names($response));
        $this->assertSame('مصلى', $response->json('data.0.landmark_type'));
    }

    public function test_parent_id_scopes_the_search_to_all_descendants(): void
    {
        // CITY 213: its neighborhood «حي الهدى» (grandchild) AND the landmark
        // «الهدى» four levels down — but not CITY 214's «حي الهدى».
        $city = $this->id(GazaLocationLevel::CITY, '213');
        $response = $this->api("/search?q=الهدى&parent_id={$city}")->assertOk();
        $this->assertSame([$this->id(GazaLocationLevel::NEIGHBORHOOD, '10'), $this->id(GazaLocationLevel::LANDMARK, '3')], array_column($response->json('data'), 'id'));

        // The scope itself is not a result.
        $nei = $this->id(GazaLocationLevel::NEIGHBORHOOD, '10');
        $this->api("/search?q=الهدى&parent_id={$nei}")->assertOk()->assertJsonCount(0, 'data');

        // An inactive scope is refused.
        GazaLocation::whereKey($city)->update(['is_active' => false]);
        $this->api("/search?q=الهدى&parent_id={$city}")->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    // ------------------------------------------------------------ Details

    public function test_details_return_the_location_with_its_full_path(): void
    {
        $response = $this->api('/'.$this->id(GazaLocationLevel::LANDMARK, '3'))->assertOk();

        $response->assertJsonPath('data.name', 'الهدى');
        $response->assertJsonPath('data.level', 'LANDMARK');
        $response->assertJsonPath('data.landmark_type', 'مصلى');
        $response->assertJsonPath('data.has_children', false);
        // Stage 3.2 G1 (API-D4 amended): details carry is_active for the
        // node and each path item, so a saved inactive selection is visible.
        $response->assertJsonPath('data.is_active', true);
        $this->assertSame([
            ['id' => $this->id(GazaLocationLevel::GOVERNORATE, '42'), 'level' => 'GOVERNORATE', 'name' => 'غزة الاختبار', 'is_active' => true],
            ['id' => $this->id(GazaLocationLevel::CITY, '213'), 'level' => 'CITY', 'name' => 'مدينة الاختبار', 'is_active' => true],
            ['id' => $this->id(GazaLocationLevel::AREA, '2'), 'level' => 'AREA', 'name' => 'شرق', 'is_active' => true],
            ['id' => $this->id(GazaLocationLevel::NEIGHBORHOOD, '12'), 'level' => 'NEIGHBORHOOD', 'name' => 'حي الشجاعية', 'is_active' => true],
        ], $response->json('data.path'));

        $this->api('/'.$this->id(GazaLocationLevel::GOVERNORATE, '42'))->assertOk()->assertJsonPath('data.path', []);
    }

    public function test_inactive_locations_are_hidden_from_lists_but_retrievable_by_id(): void
    {
        $inactive = $this->id(GazaLocationLevel::NEIGHBORHOOD, '11');
        GazaLocation::whereKey($inactive)->update(['is_active' => false]);

        $this->assertSame(['حي الهدى'], $this->names($this->api('/'.$this->id(GazaLocationLevel::AREA, '1').'/children')));
        $this->api('/search?q=النور&level=NEIGHBORHOOD')->assertOk()->assertJsonCount(0, 'data');
        $this->api("/{$inactive}")->assertOk()->assertJsonPath('data.name', 'حي النور')->assertJsonPath('data.is_active', false);
        $this->api("/{$inactive}/children")->assertNotFound();

        // has_children counts active children only.
        GazaLocation::whereKey($this->id(GazaLocationLevel::LANDMARK, '8'))->update(['is_active' => false]);
        $this->api('/'.$this->id(GazaLocationLevel::NEIGHBORHOOD, '50'))->assertJsonPath('data.has_children', false);

        $this->assertFalse(GazaLocation::find($inactive)->is_active, 'reads never reactivate');
    }

    public function test_malformed_and_unknown_ids_are_404(): void
    {
        $unknown = (string) Str::uuid();
        foreach (['/not-a-uuid', '/not-a-uuid/children', '/123', "/{$unknown}", "/{$unknown}/children"] as $uri) {
            $this->api($uri)->assertNotFound();
        }
    }

    // ------------------------------------------------------------ Authorization

    public function test_unauthenticated_requests_are_401(): void
    {
        foreach ($this->endpoints() as $uri) {
            $this->getJson('/api/v1/gaza-locations'.$uri)->assertUnauthorized();
        }
    }

    public function test_staff_holding_reference_data_view_or_residence_update_may_read(): void
    {
        // SOCIAL_WORKER holds residence.update but not reference-data.view.
        $socialWorker = $this->staff(['SOCIAL_WORKER']);
        $this->assertFalse($socialWorker->can('reference-data.view'));
        $this->assertTrue($socialWorker->can('residence.update'));

        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'SOCIAL_WORKER'] as $role) {
            $user = $role === 'SOCIAL_WORKER' ? $socialWorker : $this->staff([$role]);
            foreach ($this->endpoints() as $uri) {
                $this->api($uri, $user)->assertOk();
            }
        }
    }

    public function test_staff_without_either_permission_are_403(): void
    {
        foreach (['REVIEWER', 'REPORTS_VIEWER'] as $role) {
            $user = $this->staff([$role]);
            foreach ($this->endpoints() as $uri) {
                $this->api($uri, $user)->assertForbidden();
            }
        }
    }

    public function test_family_side_accounts_never_reach_the_staff_routes(): void
    {
        foreach ([['FAMILY_USER'], ['COORDINATOR'], ['FAMILY_USER', 'COORDINATOR'], ['DATA_ENTRY', 'FAMILY_USER']] as $roles) {
            $user = $this->staff($roles);
            foreach ($this->endpoints() as $uri) {
                $this->api($uri, $user)->assertForbidden()->assertJsonPath('message', EnsureStaffSideAccount::MESSAGE);
            }
        }
    }

    // ------------------------------------------------------------ Exposure and integrity

    public function test_no_internal_field_is_exposed_and_flagged_records_stay_selectable(): void
    {
        $internalFields = ['source_code', 'landmark_type_code', 'created_at', 'updated_at', 'sort_order', 'parent_level',
            'review', 'flag', 'issue_code', 'severity', 'notes', 'import', 'sha256', 'checksum', 'family', 'person', 'residence'];
        // Lists, children and search never carry is_active (they return
        // active locations only). Stage 3.2 G1 deliberately added it to the
        // DETAILS response only (API-D4 amended), checked separately below.
        $lists = [
            $this->api('/governorates'),
            $this->api('/search?q=حي'),
            $this->api('/search?q=نقطة'),
            $this->api('/'.$this->id(GazaLocationLevel::GOVERNORATE, '42').'/children'),
        ];
        foreach ($lists as $response) {
            $json = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
            foreach ([...$internalFields, 'is_active'] as $internal) {
                $this->assertStringNotContainsString($internal, $json);
            }
        }
        $details = json_encode($this->api('/'.$this->id(GazaLocationLevel::LANDMARK, '7'))->json(), JSON_UNESCAPED_UNICODE);
        foreach ($internalFields as $internal) {
            $this->assertStringNotContainsString($internal, $details);
        }

        // Flagged records (audited findings) are listed like any other.
        $this->assertSame(2, GazaLocationReviewFlag::whereIn('issue_code', ['UNUSUAL_LANDMARK_TYPE', 'PARENT_PLACE_MISMATCH'])->count());
        $this->api('/search?q=نقطة')->assertJsonPath('data.0.landmark_type', 'نوع غير مألوف');
        $this->api('/search?q=الهدى&level=NEIGHBORHOOD')->assertJsonCount(2, 'data');
    }

    public function test_the_api_never_writes(): void
    {
        $user = $this->staff();
        $locations = GazaLocation::orderBy('id')->get()->toArray();
        $flags = GazaLocationReviewFlag::orderBy('id')->get()->toArray();

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        foreach ([...$this->endpoints(), '/search?q=حي&per_page=2&page=2', '/'.$this->id(GazaLocationLevel::LANDMARK, '7')] as $uri) {
            $this->api($uri, $user)->assertOk();
        }

        $this->assertSame([], $writes);
        $this->assertSame($locations, GazaLocation::orderBy('id')->get()->toArray());
        $this->assertSame($flags, GazaLocationReviewFlag::orderBy('id')->get()->toArray());
    }

    public function test_search_and_paths_use_a_constant_number_of_queries(): void
    {
        $user = $this->staff();
        $count = function (string $uri) use ($user): int {
            $this->actingAs($user);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/gaza-locations'.$uri)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        // Warm the permission cache (Spatie loads roles/permissions once).
        $count('/governorates');

        // count + page (with has_children as a subquery) + one query per ancestor level.
        $this->assertSame(5, $count('/search?q=حي&per_page=1'));
        $this->assertSame(5, $count('/search?q=حي&per_page=8'), 'no query per result');
        // Landmarks are four levels deep: still one query per level.
        $this->assertSame(6, $count('/search?q=مدرسة&per_page=50'));
        $this->assertSame(2, $count('/'.$this->id(GazaLocationLevel::NEIGHBORHOOD, '10').'/children'));
    }
}
