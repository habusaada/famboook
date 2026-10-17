<?php

namespace Tests\Feature\ChangeRequests\Residence;

use App\Actions\ImportGazaGeographyAction;
use App\Actions\UpdateFamilyResidenceAction;
use App\Enums\DisplacementStatus;
use App\Enums\GazaLocationLevel;
use App\Models\ChangeRequest;
use App\Models\FamilyResidence;
use App\Models\GazaLocation;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestBase;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Feature\Geography\BuildsGazaWorkbooks;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * RESIDENCE_UPDATE payload v2 (FB-GEO-01 ADR §8.5) and v1 — LEGACY requests
 * since FB-GEO-02 (new submissions are v3: ResidenceUpdateV3Test). Requests
 * stored as v2 / v1 (StoresLegacyResidenceRequests, never migrated) still go
 * through review → approve / reject → apply with their own base fingerprint
 * and presentation. Synthetic data only.
 */
class ResidenceUpdateV2Test extends TestCase
{
    use BuildsGazaWorkbooks;
    use FamilyIdentityFixtures;
    use RefreshDatabase;
    use StoresLegacyResidenceRequests;

    private const FAMILY_URI = '/api/v1/family/change-requests';

    private const STAFF_URI = '/api/v1/change-requests';

    private FamilyAccessResult $context;

    private FamilyResidence $residence;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        config(['change_requests.family_submission_mode' => 'GENERAL']);
        $this->seed(RolePermissionSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);

        app(ImportGazaGeographyAction::class)->handle($this->geoSource($this->geoWorkbook([
            $this->geoRow([42, 'غزة الاختبار'], [213, 'مدينة الاختبار'], [1, 'غرب'], [10, 'حي الهدى'], [2, 76, 'مصلى', 'بلال']),
            $this->geoRow([42, 'غزة الاختبار'], [213, 'مدينة الاختبار'], [2, 'شرق'], [12, 'حي الشجاعية'], [3, 76, 'مصلى', 'الهدى']),
            $this->geoRow([44, 'خان الاختبار'], [217, 'مدينة الساحل'], [14, 'منطقة الساحل'], [40, 'حي المواصي'], [7, 78, 'مدرسة', 'نقطة']),
        ]), []));

        $this->context = app(FamilyAccessResolver::class)->familyContext($this->activatedHead()['user']);
        $this->residence = FamilyResidence::factory()->create([
            'family_id' => $this->context->family->id,
            'governorate' => 'خانيونس', 'city' => 'خانيونس', 'area' => 'البلد', 'neighborhood' => 'حي الأمل',
            'address_text' => 'شارع التجربة 1', 'original_residence_text' => 'بني سهيلا',
            'displacement_status' => DisplacementStatus::DISPLACED, 'displacement_location_text' => 'مواصي خانيونس',
            'original_location_id' => null, 'current_location_id' => null,
        ]);
        $this->reviewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('REVIEWER', 'web')));
    }

    private function loc(GazaLocationLevel $level, string $code): string
    {
        return GazaLocation::idFor($level, $code);
    }

    /** A v2 request as stored before v3 (its data: only the changed fields). */
    private function submitted(array $data): ChangeRequest
    {
        return $this->legacyResidenceRequest(2, $data);
    }

    private function staff(string $method, string $uri, array $body = []): TestResponse
    {
        return $this->actingAs($this->reviewer->fresh())->json($method, self::STAFF_URI.$uri, $body);
    }

    private function review(ChangeRequest $request): void
    {
        $this->staff('POST', "/{$request->uuid}/start-review")->assertOk();
    }

    private function approveAndApply(ChangeRequest $request): void
    {
        $this->review($request);
        $this->staff('POST', "/{$request->uuid}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.status', 'APPLIED');
    }

    private function staffCorrects(array $data): void
    {
        app(UpdateFamilyResidenceAction::class)->handle($this->context->family, $data, $this->reviewer->id);
    }

    private function text(): array
    {
        return $this->residence->fresh()->only(['governorate', 'city', 'area', 'neighborhood', 'address_text', 'original_residence_text', 'displacement_location_text']);
    }

    // ---------------------------------------------------------------- review, approval, rejection

    public function test_approval_persists_the_references_and_keeps_every_text_field(): void
    {
        $original = $this->loc(GazaLocationLevel::AREA, '1');
        $current = $this->loc(GazaLocationLevel::LANDMARK, '7');
        $text = $this->text();

        $request = $this->submitted(['original_location_id' => $original, 'current_location_id' => $current]);
        $this->approveAndApply($request);

        $fresh = $this->residence->fresh();
        $this->assertSame($original, $fresh->original_location_id);
        $this->assertSame($current, $fresh->current_location_id);
        $this->assertSame($text, $this->text(), 'no text field rewritten');
    }

    public function test_a_stored_v2_null_reference_clears_it_at_apply(): void
    {
        $saved = $this->loc(GazaLocationLevel::NEIGHBORHOOD, '10');
        $this->residence->forceFill(['current_location_id' => $saved])->save();

        $request = $this->submitted(['current_location_id' => null]);
        $this->approveAndApply($request);

        $this->assertNull($this->residence->fresh()->current_location_id);
        $this->assertSame(2, $request->fresh()->payload_version, 'never migrated');
    }

    /**
     * FB-GEO-02 compatibility: v1 / v2 normalize() stored an explicit
     * displacement_location_text = null whenever it cleared a text, so a stored
     * request still clears exactly what its reviewer approved — although the
     * Action no longer clears anything implicitly.
     */
    public function test_a_stored_legacy_explicit_null_still_clears_the_displacement_text(): void
    {
        foreach ([2, 1] as $version) {
            $this->residence->refresh()->forceFill(['displacement_status' => DisplacementStatus::DISPLACED, 'displacement_location_text' => 'مواصي خانيونس'])->save();
            $request = $this->legacyResidenceRequest($version, ['displacement_status' => 'NOT_DISPLACED', 'displacement_location_text' => null]);

            $this->staff('GET', "/{$request->uuid}")->assertOk()->assertJsonPath('data.presentation.rows', [
                ['label' => 'حالة النزوح', 'current' => 'نازحة', 'proposed' => 'غير نازحة'],
                ['label' => 'مكان النزوح الحالي', 'current' => 'مواصي خانيونس', 'proposed' => null],
            ]);
            $this->approveAndApply($request);

            $fresh = $this->residence->fresh();
            $this->assertSame(DisplacementStatus::NOT_DISPLACED, $fresh->displacement_status, "v{$version}");
            $this->assertNull($fresh->displacement_location_text, "v{$version}: the stored null is applied");
        }
    }

    /**
     * A stored v1 / v2 status change WITHOUT a text key was taken while the
     * text was already null; the legacy base includes the text, so a later
     * Staff text can never be silently kept or cleared by it.
     */
    public function test_a_stored_legacy_status_change_without_text_is_guarded_by_its_base(): void
    {
        $this->residence->forceFill(['displacement_location_text' => null])->save();
        $request = $this->submitted(['displacement_status' => 'NOT_DISPLACED']);
        $this->review($request);
        $this->staffCorrects(['displacement_location_text' => 'مكان أضافه الطاقم']);

        $this->staff('POST', "/{$request->uuid}/approve")->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_BASE_CHANGED');
        $this->assertSame(DisplacementStatus::DISPLACED, $this->residence->fresh()->displacement_status);
        $this->assertSame('مكان أضافه الطاقم', $this->residence->fresh()->displacement_location_text);
    }

    public function test_rejection_changes_nothing(): void
    {
        $request = $this->submitted(['current_location_id' => $this->loc(GazaLocationLevel::LANDMARK, '7'), 'city' => 'غزة']);
        $before = $this->residence->fresh()->toArray();
        $this->review($request);

        $this->staff('POST', "/{$request->uuid}/reject", ['rejection_reason_code' => 'CANNOT_VERIFY', 'public_message' => 'تعذر التحقق'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED');

        $this->assertSame($before, $this->residence->fresh()->toArray());
    }

    public function test_a_staff_reference_change_after_submission_blocks_approval(): void
    {
        $request = $this->submitted(['neighborhood' => 'حي النصر']);
        $this->review($request);

        // Outside the proposal, but part of the v2 base.
        $this->staffCorrects(['current_location_id' => $this->loc(GazaLocationLevel::GOVERNORATE, '44')]);

        $this->staff('POST', "/{$request->uuid}/approve")->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_BASE_CHANGED');
        $this->assertSame('حي الأمل', $this->residence->fresh()->neighborhood);
    }

    public function test_a_reference_deactivated_after_submission_fails_the_precondition(): void
    {
        $chosen = $this->loc(GazaLocationLevel::LANDMARK, '7');
        $request = $this->submitted(['current_location_id' => $chosen]);
        $this->review($request);
        GazaLocation::whereKey($chosen)->update(['is_active' => false]);

        $this->staff('POST', "/{$request->uuid}/approve")->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_PRECONDITION_FAILED');
        $this->assertNull($this->residence->fresh()->current_location_id);
    }

    public function test_reviewer_and_family_see_names_and_paths_never_uuids(): void
    {
        $inactive = $this->loc(GazaLocationLevel::NEIGHBORHOOD, '12');
        $this->residence->forceFill(['original_location_id' => $inactive])->save();
        GazaLocation::whereKey($inactive)->update(['is_active' => false]);
        $current = $this->loc(GazaLocationLevel::LANDMARK, '7');

        $request = $this->submitted(['original_location_id' => $this->loc(GazaLocationLevel::AREA, '1'), 'current_location_id' => $current]);

        $expected = [
            // FB-GEO-02 labels: the actual home before displacement, and the current residence.
            ['label' => 'الموقع الجغرافي للسكن الفعلي قبل النزوح', 'current' => 'غزة الاختبار ‹ مدينة الاختبار ‹ شرق ‹ حي الشجاعية (غير مفعّلة)', 'proposed' => 'غزة الاختبار ‹ مدينة الاختبار ‹ غرب'],
            ['label' => 'الموقع الجغرافي للسكن الحالي', 'current' => null, 'proposed' => 'خان الاختبار ‹ مدينة الساحل ‹ منطقة الساحل ‹ حي المواصي ‹ نقطة'],
        ];
        $staffView = $this->staff('GET', "/{$request->uuid}")->assertOk()->assertJsonPath('data.presentation.rows', $expected);
        $familyView = $this->actingAs($this->context->user->fresh())->getJson(self::FAMILY_URI."/{$request->uuid}")->assertOk()
            ->assertJsonPath('data.presentation.rows', $expected);
        foreach ([$staffView, $familyView] as $view) {
            $this->assertStringNotContainsString($current, $view->getContent());
            $this->assertStringNotContainsString($inactive, $view->getContent());
        }
    }

    public function test_the_family_profile_returns_only_location_summaries(): void
    {
        $inactive = $this->loc(GazaLocationLevel::NEIGHBORHOOD, '12');
        $current = $this->loc(GazaLocationLevel::LANDMARK, '7');
        $this->residence->forceFill(['original_location_id' => $inactive, 'current_location_id' => $current])->save();
        GazaLocation::whereKey($inactive)->update(['is_active' => false]);

        $residence = $this->actingAs($this->context->user->fresh())->getJson('/api/v1/family/household/profile')
            ->assertOk()->json('data.residence');

        $this->assertSame(['id', 'is_active', 'landmark_type', 'level', 'name', 'path'], collect($residence['original_location'])->keys()->sort()->values()->all());
        // FB-GEO-03: the family-safe landmark type — null below LANDMARK.
        $this->assertNull($residence['original_location']['landmark_type']);
        $this->assertSame('مدرسة', $residence['current_location']['landmark_type']);
        $this->assertSame($inactive, $residence['original_location']['id']);
        $this->assertFalse($residence['original_location']['is_active']);
        $this->assertSame(['غزة الاختبار', 'مدينة الاختبار', 'شرق'], array_column($residence['original_location']['path'], 'name'));
        $this->assertSame('نقطة', $residence['current_location']['name']);
        $this->assertArrayNotHasKey('original_location_id', $residence);
        $this->assertArrayNotHasKey('current_location_id', $residence);
        $this->assertSame('شارع التجربة 1', $residence['current_address']['address_text'], 'text unchanged beside it');
        $json = json_encode($residence, JSON_UNESCAPED_UNICODE);
        foreach (['source_code', 'landmark_type_code', 'review', 'flag'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }

        // Not recorded → null.
        $this->residence->forceFill(['original_location_id' => null, 'current_location_id' => null])->save();
        $residence = $this->actingAs($this->context->user->fresh())->getJson('/api/v1/family/household/profile')->json('data.residence');
        $this->assertNull($residence['original_location']);
        $this->assertNull($residence['current_location']);
    }

    public function test_a_family_cannot_write_the_residence_directly(): void
    {
        $this->actingAs($this->context->user->fresh())
            ->patchJson("/api/v1/families/{$this->context->family->family_code}/residence", ['current_location_id' => $this->loc(GazaLocationLevel::LANDMARK, '7')])
            ->assertForbidden();
        $this->assertNull($this->residence->fresh()->current_location_id);
    }

    // ---------------------------------------------------------------- v1 compatibility

    /**
     * A request exactly as v1 stored it: payload_version 1, the eight-field
     * data, and the base fingerprint the v1 handler took (the residence id
     * and its eight fields — no location reference).
     */
    private function legacyV1Request(array $overrides): ChangeRequest
    {
        $request = $this->submitted($overrides);
        $r = $this->residence->fresh();
        $base = ChangeRequestBase::of([
            'residence' => $r->id,
            'governorate' => $r->governorate, 'city' => $r->city, 'area' => $r->area, 'neighborhood' => $r->neighborhood,
            'address_text' => $r->address_text, 'original_residence_text' => $r->original_residence_text,
            'displacement_status' => $r->displacement_status?->value, 'displacement_location_text' => $r->displacement_location_text,
        ]);
        // Below the model: a submitted proposal is immutable through Eloquent.
        DB::table('change_requests')->where('id', $request->id)
            ->update(['payload_version' => 1, 'base_fingerprint' => $base['fingerprint'], 'base_key_version' => $base['key_version']]);

        return $request->fresh();
    }

    public function test_a_v1_request_submitted_before_v2_still_reviews_approves_and_applies(): void
    {
        $request = $this->legacyV1Request(['neighborhood' => 'حي النصر']);
        $this->assertSame(1, $request->payload_version);

        $this->staff('GET', "/{$request->uuid}")->assertOk()->assertJsonPath('data.presentation.rows', [
            ['label' => 'الحي', 'current' => 'حي الأمل', 'proposed' => 'حي النصر'],
        ]);
        $this->approveAndApply($request);

        $this->assertSame('حي النصر', $this->residence->fresh()->neighborhood);
        $this->assertSame(1, $request->fresh()->payload_version, 'never migrated');
        $this->assertEquals(['neighborhood' => 'حي النصر'], $request->fresh()->submitted_data);
    }

    public function test_a_v1_request_keeps_v1_concurrency_and_never_touches_the_references(): void
    {
        $saved = $this->loc(GazaLocationLevel::NEIGHBORHOOD, '10');
        $request = $this->legacyV1Request(['city' => 'غزة']);
        $this->review($request);

        // A reference change is outside the v1 base: the v1 request still applies…
        $this->staffCorrects(['current_location_id' => $saved]);
        $this->staff('POST', "/{$request->uuid}/approve")->assertOk();
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.status', 'APPLIED');
        $this->assertSame('غزة', $this->residence->fresh()->city);
        $this->assertSame($saved, $this->residence->fresh()->current_location_id, 'a v1 apply never clears a reference');

        // …while a text change is inside it, as before.
        $second = $this->legacyV1Request(['area' => 'الكتيبة']);
        $this->review($second);
        $this->staffCorrects(['neighborhood' => 'حي آخر']);
        $this->staff('POST', "/{$second->uuid}/approve")->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_BASE_CHANGED');
    }
}
