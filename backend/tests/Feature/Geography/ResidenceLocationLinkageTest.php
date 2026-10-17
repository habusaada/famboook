<?php

namespace Tests\Feature\Geography;

use App\Actions\CreateFamilyResidenceAction;
use App\Actions\ImportGazaGeographyAction;
use App\Actions\UpdateFamilyResidenceAction;
use App\Enums\FamilyActivityType;
use App\Enums\GazaLocationLevel;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\GazaLocation;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * FB-GEO-01 Stage 3.2: residence references to the Gaza geographic
 * reference (Stage 3.1 audit, test matrix T1–T20). SYNTHETIC geography
 * (BuildsGazaWorkbooks default dataset) and synthetic families.
 */
class ResidenceLocationLinkageTest extends TestCase
{
    use BuildsGazaWorkbooks;
    use RefreshDatabase;

    private Family $family;

    private FamilyResidence $residence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        app(ImportGazaGeographyAction::class)->handle($this->geoSource($this->geoWorkbook()));

        $this->family = Family::factory()->create();
        FamilyMembership::factory()->householdHead()->create([
            'family_id' => $this->family->id,
            'person_id' => Person::factory()->create()->id,
        ]);
        $this->residence = FamilyResidence::factory()->create([
            'family_id' => $this->family->id,
            'governorate' => 'نص حر',
            'city' => 'مدينة نصية',
            'displacement_status' => null,
        ]);
    }

    private function loc(GazaLocationLevel $level, string $code): string
    {
        return GazaLocation::idFor($level, $code);
    }

    private function user(string $role = 'DATA_ENTRY'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function patchResidence(array $payload, string $role = 'DATA_ENTRY'): TestResponse
    {
        return $this->actingAs($this->user($role))
            ->patchJson("/api/v1/families/{$this->family->family_code}/residence", $payload);
    }

    private function deactivate(string $id): void
    {
        GazaLocation::whereKey($id)->update(['is_active' => false]);
    }

    /** A valid staff registration payload (synthetic) with optional residence additions. */
    private function registration(array $residence = []): array
    {
        return [
            'registration_date' => '2026-09-01',
            'registration_source' => 'MANUAL_ENTRY',
            'household_head' => [
                'full_name' => 'اسم تجريبي للاختبار',
                'national_id' => '912340777',
                'gender' => 'MALE',
            ],
            'clan_code' => 'AL_BREEM',
            'residence' => ['displacement_status' => 'DISPLACED', ...$residence],
        ];
    }

    // ------------------------------------------------------------ T1 schema / null compatibility

    public function test_t1_existing_residences_have_null_references_and_unchanged_text(): void
    {
        $this->assertTrue(Schema::hasColumns('family_residences', ['original_location_id', 'current_location_id']));
        $fresh = $this->residence->fresh();
        $this->assertNull($fresh->original_location_id);
        $this->assertNull($fresh->current_location_id);
        $this->assertSame(['نص حر', 'مدينة نصية'], [$fresh->governorate, $fresh->city]);

        // The partial unique index (one CURRENT residence per Family) survives
        // the migration on every driver: a historical row is still allowed.
        FamilyResidence::factory()->create(['family_id' => $this->family->id, 'is_current' => false, 'ended_at' => '2026-01-01']);
        $this->assertSame(2, FamilyResidence::where('family_id', $this->family->id)->count());

        $this->actingAs($this->user())->getJson("/api/v1/families/{$this->family->family_code}")
            ->assertOk()
            ->assertJsonPath('data.residence.original_location_id', null)
            ->assertJsonPath('data.residence.current_location_id', null)
            ->assertJsonPath('data.residence.original_location', null)
            ->assertJsonPath('data.residence.current_location', null)
            ->assertJsonPath('data.residence.governorate', 'نص حر');
    }

    // ------------------------------------------------------------ T2 registration at any level

    public function test_t2_registration_accepts_an_active_location_at_every_level(): void
    {
        $levels = [
            [GazaLocationLevel::GOVERNORATE, '1'], [GazaLocationLevel::CITY, '10'], [GazaLocationLevel::AREA, '10'],
            [GazaLocationLevel::NEIGHBORHOOD, '100'], [GazaLocationLevel::LANDMARK, '1'],
        ];
        foreach ($levels as $i => [$level, $code]) {
            $payload = $this->registration(['current_location_id' => $this->loc($level, $code), 'original_location_id' => $this->loc(GazaLocationLevel::GOVERNORATE, '2')]);
            $payload['household_head']['national_id'] = '91234080'.$i;
            $response = $this->actingAs($this->user())->postJson('/api/v1/families', $payload)->assertCreated();

            $response->assertJsonPath('data.residence.current_location_id', $this->loc($level, $code));
            $response->assertJsonPath('data.residence.current_location.level', $level->value);
            $response->assertJsonPath('data.residence.original_location.level', 'GOVERNORATE');
        }

        // Absent = not recorded.
        $payload = $this->registration();
        $payload['household_head']['national_id'] = '912340899';
        $this->actingAs($this->user())->postJson('/api/v1/families', $payload)->assertCreated()
            ->assertJsonPath('data.residence.current_location_id', null);
    }

    // ------------------------------------------------------------ T3 / T4 / T5 validation

    public function test_t3_t4_t5_malformed_unknown_and_inactive_locations_are_422(): void
    {
        $inactive = $this->loc(GazaLocationLevel::NEIGHBORHOOD, '101');
        $this->deactivate($inactive);

        foreach (['not-a-uuid', '12345', (string) Str::uuid(), $inactive] as $value) {
            $this->patchResidence(['current_location_id' => $value])->assertUnprocessable()->assertJsonValidationErrors('current_location_id');
            $this->patchResidence(['original_location_id' => $value])->assertUnprocessable()->assertJsonValidationErrors('original_location_id');

            $payload = $this->registration(['current_location_id' => $value]);
            $this->actingAs($this->user())->postJson('/api/v1/families', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('residence.current_location_id');
        }
        $this->assertNull($this->residence->fresh()->current_location_id);
    }

    // ------------------------------------------------------------ T6 unchanged inactive saved value

    public function test_t6_a_saved_location_that_became_inactive_survives_unrelated_and_identical_edits(): void
    {
        $saved = $this->loc(GazaLocationLevel::NEIGHBORHOOD, '101');
        $this->patchResidence(['current_location_id' => $saved])->assertOk();
        $this->deactivate($saved);

        $this->patchResidence(['city' => 'نص آخر'])->assertOk()->assertJsonPath('data.residence.current_location_id', $saved);
        $this->patchResidence(['current_location_id' => $saved])->assertOk()->assertJsonPath('data.residence.current_location.is_active', false);
        // Upper-case form of the same stored value is the same value.
        $this->patchResidence(['current_location_id' => strtoupper($saved)])->assertOk();
        $this->assertSame($saved, $this->residence->fresh()->current_location_id);

        // A DIFFERENT inactive location is still refused.
        $other = $this->loc(GazaLocationLevel::NEIGHBORHOOD, '102');
        $this->deactivate($other);
        $this->patchResidence(['current_location_id' => $other])->assertUnprocessable();
    }

    // ------------------------------------------------------------ T7 omitted vs explicit null

    public function test_t7_omitted_keeps_and_explicit_null_clears(): void
    {
        $this->patchResidence([
            'original_location_id' => $this->loc(GazaLocationLevel::CITY, '20'),
            'current_location_id' => $this->loc(GazaLocationLevel::LANDMARK, '1'),
        ])->assertOk();

        $this->patchResidence(['address_text' => 'تفصيل'])->assertOk();
        $fresh = $this->residence->fresh();
        $this->assertSame($this->loc(GazaLocationLevel::CITY, '20'), $fresh->original_location_id);
        $this->assertSame($this->loc(GazaLocationLevel::LANDMARK, '1'), $fresh->current_location_id);

        $this->patchResidence(['current_location_id' => null])->assertOk()->assertJsonPath('data.residence.current_location', null);
        $fresh = $this->residence->fresh();
        $this->assertNull($fresh->current_location_id);
        $this->assertSame($this->loc(GazaLocationLevel::CITY, '20'), $fresh->original_location_id, 'only the sent key changes');
    }

    // ------------------------------------------------------------ T8 displacement independence

    public function test_t8_displacement_status_never_clears_the_current_location(): void
    {
        $current = $this->loc(GazaLocationLevel::NEIGHBORHOOD, '102');
        $this->patchResidence(['displacement_status' => 'DISPLACED', 'displacement_location_text' => 'نص النزوح', 'current_location_id' => $current])->assertOk();

        // FB-GEO-02: neither the reference nor the historical text is cleared.
        $this->patchResidence(['displacement_status' => 'NOT_DISPLACED'])->assertOk()
            ->assertJsonPath('data.residence.current_location_id', $current)
            ->assertJsonPath('data.residence.displacement_location_text', 'نص النزوح');

        $this->patchResidence(['displacement_status' => null])->assertOk()->assertJsonPath('data.residence.current_location_id', $current);
    }

    // ------------------------------------------------------------ T9 names never decide

    public function test_t9_same_named_locations_are_distinct_by_uuid(): void
    {
        // NEI 100 «حي رقم 7 » and NEI 101 «حي رقم 7» share a parent and (trimmed) name.
        $a = $this->loc(GazaLocationLevel::NEIGHBORHOOD, '100');
        $b = $this->loc(GazaLocationLevel::NEIGHBORHOOD, '101');

        $this->patchResidence(['current_location_id' => $a])->assertOk()->assertJsonPath('data.residence.current_location.id', $a);
        $this->patchResidence(['current_location_id' => $b])->assertOk()->assertJsonPath('data.residence.current_location.id', $b);
        $this->assertSame($b, $this->residence->fresh()->current_location_id);
    }

    // ------------------------------------------------------------ T10 activity grouping

    public function test_t10_activity_events_follow_the_field_groups_without_values(): void
    {
        $events = fn () => FamilyActivity::where('family_id', $this->family->id)->pluck('event_type')->map(fn ($e) => $e instanceof FamilyActivityType ? $e->value : $e)->all();

        $this->patchResidence(['current_location_id' => $this->loc(GazaLocationLevel::CITY, '10')])->assertOk();
        $this->assertSame(['RESIDENCE_UPDATED'], $events());

        $this->patchResidence(['original_location_id' => $this->loc(GazaLocationLevel::CITY, '20')])->assertOk();
        $this->assertSame(['RESIDENCE_UPDATED', 'DISPLACEMENT_UPDATED'], $events());

        // No value of either location in any activity row.
        $rows = json_encode(FamilyActivity::where('family_id', $this->family->id)->get()->toArray());
        $this->assertStringNotContainsString($this->loc(GazaLocationLevel::CITY, '10'), $rows);
        $this->assertStringNotContainsString($this->loc(GazaLocationLevel::CITY, '20'), $rows);

        // Re-sending the same value changes nothing and records nothing.
        $this->patchResidence(['current_location_id' => $this->loc(GazaLocationLevel::CITY, '10')])->assertOk();
        $this->assertCount(2, $events());
    }

    // ------------------------------------------------------------ T11 authorization

    public function test_t11_authorization_is_unchanged(): void
    {
        $id = $this->loc(GazaLocationLevel::CITY, '10');
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'SOCIAL_WORKER'] as $role) {
            $this->patchResidence(['current_location_id' => $id], $role)->assertOk();
        }
        foreach (['REVIEWER', 'REPORTS_VIEWER'] as $role) {
            $this->patchResidence(['current_location_id' => $id], $role)->assertForbidden();
        }
        foreach (['FAMILY_USER', 'COORDINATOR'] as $role) {
            $this->patchResidence(['current_location_id' => $id], $role)->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->patchJson("/api/v1/families/{$this->family->family_code}/residence", ['current_location_id' => $id])->assertUnauthorized();
    }

    // ------------------------------------------------------------ T12 RESIDENCE_UPDATE v1 untouched

    public function test_t12_a_v1_apply_payload_never_touches_location_references(): void
    {
        $id = $this->loc(GazaLocationLevel::CITY, '10');
        $this->patchResidence(['current_location_id' => $id, 'original_location_id' => $id])->assertOk();

        // Exactly what ResidenceUpdateHandler::apply passes: v1 text/status fields only.
        app(UpdateFamilyResidenceAction::class)->handle($this->family, ['city' => 'نص v1', 'displacement_status' => 'NOT_DISPLACED'], null);

        $fresh = $this->residence->fresh();
        $this->assertSame('نص v1', $fresh->city);
        $this->assertSame([$id, $id], [$fresh->current_location_id, $fresh->original_location_id]);
    }

    // ------------------------------------------------------------ T13 coordinator / import callers

    public function test_t13_callers_that_omit_locations_store_null(): void
    {
        $family = Family::factory()->create();
        // As the coordinator REGISTER_FAMILY APPLY and the import call it.
        $residence = app(CreateFamilyResidenceAction::class)->handle($family, ['original_residence_text' => 'نص فقط', 'source' => 'IMPORT'], '2026-09-01', null);

        $this->assertNull($residence->original_location_id);
        $this->assertNull($residence->current_location_id);
        $this->assertSame('نص فقط', $residence->original_residence_text);
    }

    public function test_t13_an_action_caller_cannot_store_an_inactive_location(): void
    {
        $inactive = $this->loc(GazaLocationLevel::CITY, '20');
        $this->deactivate($inactive);

        $this->expectException(ValidationException::class);
        app(CreateFamilyResidenceAction::class)->handle(Family::factory()->create(), ['current_location_id' => $inactive], '2026-09-01', null);
    }

    // ------------------------------------------------------------ T14 serialization

    public function test_t14_staff_detail_serializes_ids_and_summaries_only(): void
    {
        $landmark = $this->loc(GazaLocationLevel::LANDMARK, '3');
        $this->patchResidence(['current_location_id' => $landmark, 'original_location_id' => $this->loc(GazaLocationLevel::GOVERNORATE, '2')])->assertOk();
        // An inactive ANCESTOR is flagged in the path.
        $this->deactivate($this->loc(GazaLocationLevel::AREA, '10'));

        $response = $this->actingAs($this->user())->getJson("/api/v1/families/{$this->family->family_code}")->assertOk();
        $current = $response->json('data.residence.current_location');

        $this->assertSame(['id', 'is_active', 'level', 'name', 'path'], collect($current)->keys()->sort()->values()->all());
        $this->assertSame('النور', $current['name'], 'display-trimmed');
        $this->assertTrue($current['is_active']);
        $this->assertSame(['GOVERNORATE', 'CITY', 'AREA', 'NEIGHBORHOOD'], array_column($current['path'], 'level'));
        $this->assertSame([true, true, false, true], array_column($current['path'], 'is_active'));
        $this->assertSame([], $response->json('data.residence.original_location.path'));

        $json = json_encode($response->json('data.residence'), JSON_UNESCAPED_UNICODE);
        foreach (['source_code', 'landmark_type', 'review', 'flag', 'import', 'sha256', 'created_at', 'updated_at', 'parent_level'] as $internal) {
            $this->assertStringNotContainsString($internal, $json);
        }
        // Every pre-existing residence key is still there.
        foreach (['governorate', 'city', 'area', 'neighborhood', 'address_text', 'residence_type', 'started_at',
            'original_residence_text', 'displacement_status', 'displacement_location_text'] as $key) {
            $this->assertArrayHasKey($key, $response->json('data.residence'));
        }
    }

    // ------------------------------------------------------------ T15 family-side contracts

    /**
     * The coordinator view still carries no location data. The Family profile
     * carries the saved references ONLY as GazaLocationSummary summaries
     * (RESIDENCE_UPDATE v2, ADR §8.5) — no raw *_location_id key; behaviour:
     * ResidenceUpdateV2Test::test_the_family_profile_returns_only_location_summaries.
     */
    public function test_t15_family_portal_reads_expose_locations_only_as_summaries(): void
    {
        $coordinator = file_get_contents(app_path('Http/Resources/CoordinatorFamilyDetailResource.php'));
        $this->assertStringNotContainsString('location_id', $coordinator);
        $this->assertStringNotContainsString('GazaLocation', $coordinator);

        $profile = file_get_contents(app_path('Http/Resources/FamilyHouseholdProfileResource.php'));
        $this->assertStringContainsString('GazaLocationSummary::forResidence', $profile);
        $this->assertStringNotContainsString("'original_location_id'", $profile);
        $this->assertStringNotContainsString("'current_location_id'", $profile);
    }

    // ------------------------------------------------------------ T16 query efficiency

    public function test_t16_staff_detail_runs_a_constant_number_of_location_queries(): void
    {
        $user = $this->user();
        $count = function () use ($user): int {
            $this->actingAs($user);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/v1/families/{$this->family->family_code}")->assertOk();
            $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'gaza_locations'))->count();
            DB::disableQueryLog();

            return $n;
        };
        $count(); // warm the permission cache

        $this->patchResidence(['current_location_id' => $this->loc(GazaLocationLevel::GOVERNORATE, '1'), 'original_location_id' => $this->loc(GazaLocationLevel::GOVERNORATE, '2')])->assertOk();
        $shallow = $count();
        $this->patchResidence(['current_location_id' => $this->loc(GazaLocationLevel::LANDMARK, '1'), 'original_location_id' => $this->loc(GazaLocationLevel::LANDMARK, '4')])->assertOk();
        $deep = $count();

        // 2 relation loads + at most one batched query per ancestor level, for BOTH locations together.
        $this->assertSame(2, $shallow);
        $this->assertSame(6, $deep);
    }

    // ------------------------------------------------------------ T17 FK restrict

    public function test_t17_a_referenced_location_cannot_be_deleted(): void
    {
        $id = $this->loc(GazaLocationLevel::LANDMARK, '2');
        $this->patchResidence(['current_location_id' => $id])->assertOk();
        DB::table('gaza_location_review_flags')->where('gaza_location_id', $id)->delete();

        try {
            DB::transaction(fn () => DB::table('gaza_locations')->where('id', $id)->delete());
            $this->fail('Expected the foreign key to restrict the delete.');
        } catch (QueryException) {
            $this->assertTrue(GazaLocation::whereKey($id)->exists());
        }
    }

    // T18 (PostgreSQL concurrency) lives in ResidenceLocationConcurrencyTest: it needs
    // committed fixtures (DatabaseTruncation) so a second session sees them.

    // ------------------------------------------------------------ T19 guarded rollback

    public function test_t19_the_migration_rollback_refuses_while_references_exist(): void
    {
        $migration = require database_path('migrations/2026_10_22_090003_add_location_references_to_family_residences_table.php');
        $this->patchResidence(['current_location_id' => $this->loc(GazaLocationLevel::CITY, '10')])->assertOk();

        try {
            $migration->down();
            $this->fail('Expected the rollback to refuse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 residence(s)', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('family_residences', 'current_location_id'));

        $this->patchResidence(['current_location_id' => null])->assertOk();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('family_residences', 'current_location_id'));
        $migration->up();
        $this->assertTrue(Schema::hasColumns('family_residences', ['original_location_id', 'current_location_id']));
    }

    // ------------------------------------------------------------ T20 details is_active (see also GazaLocationApiTest)

    public function test_t20_details_expose_is_active_for_the_node_and_its_path_only(): void
    {
        $user = $this->user();
        $this->deactivate($this->loc(GazaLocationLevel::CITY, '10'));

        $details = $this->actingAs($user)->getJson('/api/v1/gaza-locations/'.$this->loc(GazaLocationLevel::NEIGHBORHOOD, '100'))->assertOk();
        $details->assertJsonPath('data.is_active', true);
        $this->assertSame([true, false, true], array_column($details->json('data.path'), 'is_active'));

        foreach (['/api/v1/gaza-locations/governorates', '/api/v1/gaza-locations/search?q=حي', '/api/v1/gaza-locations/'.$this->loc(GazaLocationLevel::GOVERNORATE, '2').'/children'] as $uri) {
            $this->assertStringNotContainsString('is_active', json_encode($this->actingAs($user)->getJson($uri)->assertOk()->json()));
        }
    }
}
