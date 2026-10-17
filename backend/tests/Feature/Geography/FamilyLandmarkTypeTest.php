<?php

namespace Tests\Feature\Geography;

use App\Actions\ImportGazaGeographyAction;
use App\Enums\GazaLocationLevel;
use App\Enums\GazaLocationReviewIssue;
use App\Enums\GazaLocationReviewStatus;
use App\Models\FamilyResidence;
use App\Models\GazaLocation;
use App\Models\GazaLocationReviewFlag;
use App\Models\User;
use App\Support\Geography\FamilyLandmarkType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FB-GEO-03 (API-D4 as amended): the family-safe landmark type. A family
 * sees a landmark's type NAME unless an UNUSUAL_LANDMARK_TYPE review flag is
 * OPEN or CORRECTION_REQUIRED; never the type code or any review data; never
 * a type below LANDMARK. Applied to the Family location mirrors and to the
 * household profile's structured locations. Synthetic data only.
 */
class FamilyLandmarkTypeTest extends TestCase
{
    use BuildsGazaWorkbooks;
    use FamilyIdentityFixtures;
    use RefreshDatabase;

    private const MIRROR = '/api/v1/family/change-requests/gaza-locations';

    private const PROFILE = '/api/v1/family/household/profile';

    private array $head;

    private string $ordinary;

    private string $unusual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);

        app(ImportGazaGeographyAction::class)->handle($this->geoSource($this->geoWorkbook([
            $this->geoRow([42, 'غزة الاختبار'], [213, 'مدينة الاختبار'], [1, 'غرب'], [10, 'حي الهدى'], [2, 76, 'مصلى', 'بلال']),
            // A type from a non-landmark category (as Stage 1A W2), flagged by the audited findings.
            $this->geoRow([42, 'غزة الاختبار'], [213, 'مدينة الاختبار'], [1, 'غرب'], [10, 'حي الهدى'], [9, 8, 'قتل', 'مستشفى الاختبار']),
        ]), [
            ['issue' => GazaLocationReviewIssue::UNUSUAL_LANDMARK_TYPE, 'level' => GazaLocationLevel::LANDMARK, 'source_code' => '9', 'note' => 'Synthetic unusual type.'],
        ]));
        $this->ordinary = GazaLocation::idFor(GazaLocationLevel::LANDMARK, '2');
        $this->unusual = GazaLocation::idFor(GazaLocationLevel::LANDMARK, '9');
        $this->head = $this->activatedHead();
    }

    private function family(string $uri): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->head['user']->fresh())->getJson($uri)->assertOk();
    }

    private function setFlag(GazaLocationReviewStatus $status): void
    {
        GazaLocationReviewFlag::where('gaza_location_id', $this->unusual)->update(['review_status' => $status->value]);
    }

    /** landmark_type of each landmark in the neighborhood's children list, keyed by id. */
    private function listed(): array
    {
        $children = $this->family(self::MIRROR.'/'.GazaLocation::idFor(GazaLocationLevel::NEIGHBORHOOD, '10').'/children')->json('data');

        return array_column($children, 'landmark_type', 'id');
    }

    /** @return array<string, array{0: GazaLocationReviewStatus, 1: ?string}> */
    public static function statuses(): array
    {
        return [
            'OPEN hides it' => [GazaLocationReviewStatus::OPEN, null],
            'CORRECTION_REQUIRED hides it' => [GazaLocationReviewStatus::CORRECTION_REQUIRED, null],
            'CONFIRMED no longer blocks it' => [GazaLocationReviewStatus::CONFIRMED, 'قتل'],
            'DISMISSED no longer blocks it' => [GazaLocationReviewStatus::DISMISSED, 'قتل'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_the_flag_status_decides_on_lists_details_and_both_profile_locations(GazaLocationReviewStatus $status, ?string $expected): void
    {
        $this->setFlag($status);

        // Mirrors: children list and details.
        $this->assertSame([$this->ordinary => 'مصلى', $this->unusual => $expected], $this->listed());
        $this->assertSame($expected, $this->family(self::MIRROR."/{$this->unusual}")->json('data.landmark_type'));
        $this->assertSame('مصلى', $this->family(self::MIRROR."/{$this->ordinary}")->json('data.landmark_type'));

        // Household profile: original and current structured locations.
        foreach ([[$this->unusual, $this->ordinary], [$this->ordinary, $this->unusual]] as [$original, $current]) {
            FamilyResidence::where('family_id', $this->head['family']->id)->delete();
            FamilyResidence::factory()->create(['family_id' => $this->head['family']->id, 'original_location_id' => $original, 'current_location_id' => $current]);
            $residence = $this->family(self::PROFILE)->json('data.residence');

            $types = [$this->unusual => $expected, $this->ordinary => 'مصلى'];
            $this->assertSame($types[$original], $residence['original_location']['landmark_type']);
            $this->assertSame($types[$current], $residence['current_location']['landmark_type']);
            // The name is shown exactly as before, whatever the type.
            $this->assertSame('مستشفى الاختبار', $residence[$original === $this->unusual ? 'original_location' : 'current_location']['name']);
        }
    }

    public function test_no_type_below_landmark_and_never_a_type_code_or_review_data(): void
    {
        $nei = GazaLocation::idFor(GazaLocationLevel::NEIGHBORHOOD, '10');
        $this->assertNull($this->family(self::MIRROR."/{$nei}")->json('data.landmark_type'));
        foreach ($this->family(self::MIRROR.'/governorates')->json('data') as $governorate) {
            $this->assertNull($governorate['landmark_type']);
        }
        FamilyResidence::factory()->create(['family_id' => $this->head['family']->id, 'original_location_id' => $nei, 'current_location_id' => $this->unusual]);
        $profile = $this->family(self::PROFILE);
        $this->assertNull($profile->json('data.residence.original_location.landmark_type'));

        foreach ([$profile, $this->family(self::MIRROR."/{$this->unusual}"), $this->family(self::MIRROR."/{$nei}/children")] as $response) {
            $json = $response->getContent();
            foreach (['landmark_type_code', 'source_code', 'review', 'flag', 'UNUSUAL', 'OPEN', 'Synthetic', 'blocked', '"قتل"'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $json, $forbidden);
            }
        }
    }

    public function test_the_staff_lookups_keep_the_full_type_and_the_reference_data_is_never_changed(): void
    {
        $before = GazaLocation::orderBy('id')->get(['id', 'name', 'parent_id', 'landmark_type_code', 'landmark_type_name', 'is_active'])->toArray();

        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole('DATA_ENTRY'));
        $this->actingAs($staff)->getJson("/api/v1/gaza-locations/{$this->unusual}")->assertOk()->assertJsonPath('data.landmark_type', 'قتل');
        $this->listed();
        $this->family(self::MIRROR."/{$this->unusual}");

        $this->assertSame($before, GazaLocation::orderBy('id')->get(['id', 'name', 'parent_id', 'landmark_type_code', 'landmark_type_name', 'is_active'])->toArray());
        $this->assertSame(GazaLocationReviewStatus::OPEN, GazaLocationReviewFlag::where('gaza_location_id', $this->unusual)->sole()->review_status);
    }

    public function test_a_list_resolves_every_type_in_one_flag_query(): void
    {
        DB::enableQueryLog();
        $this->listed();
        $flagQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'gaza_location_review_flags'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $flagQueries, 'no N+1 over the landmarks');
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function sameLandmark(): array
    {
        return ['a permitted type' => ['ordinary', 'مصلى'], 'a blocked type (OPEN)' => ['unusual', null]];
    }

    #[DataProvider('sameLandmark')]
    public function test_the_same_landmark_as_original_and_current_location_gets_one_decision_from_one_query(string $which, ?string $expected): void
    {
        $id = $this->{$which};
        FamilyResidence::factory()->create(['family_id' => $this->head['family']->id, 'original_location_id' => $id, 'current_location_id' => $id]);

        DB::enableQueryLog();
        $residence = $this->family(self::PROFILE)->json('data.residence');
        $flagQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'gaza_location_review_flags'))->count();
        DB::disableQueryLog();

        $this->assertSame($id, $residence['original_location']['id']);
        $this->assertSame($id, $residence['current_location']['id']);
        $this->assertSame($expected, $residence['original_location']['landmark_type']);
        $this->assertSame($expected, $residence['current_location']['landmark_type']);
        $this->assertSame(1, $flagQueries, 'both summaries resolved by one batched query');
    }

    public function test_the_internal_flag_is_never_serialized(): void
    {
        $locations = GazaLocation::whereIn('id', [$this->ordinary, $this->unusual])->get();
        FamilyLandmarkType::load($locations);

        foreach ($locations as $location) {
            // Loaded (so no second query) …
            $this->assertArrayHasKey(FamilyLandmarkType::BLOCKED_ATTRIBUTE, $location->getAttributes());
            // … but never in any serialization of the model.
            $this->assertArrayNotHasKey(FamilyLandmarkType::BLOCKED_ATTRIBUTE, $location->toArray());
            $this->assertStringNotContainsString(FamilyLandmarkType::BLOCKED_ATTRIBUTE, $location->toJson());
        }
        $this->assertStringNotContainsString(FamilyLandmarkType::BLOCKED_ATTRIBUTE, $locations->toJson());
        $this->assertNull(FamilyLandmarkType::of($locations->firstWhere('id', $this->unusual)));
    }

    public function test_an_unloaded_location_is_still_resolved_safely(): void
    {
        // Fail-safe: never a blocked type, even without a prior load().
        $this->assertNull(FamilyLandmarkType::of(GazaLocation::findOrFail($this->unusual)));
        $this->assertSame('مصلى', FamilyLandmarkType::of(GazaLocation::findOrFail($this->ordinary)));
        $this->assertNull(FamilyLandmarkType::of(GazaLocation::findOrFail(GazaLocation::idFor(GazaLocationLevel::AREA, '1'))));
    }
}
