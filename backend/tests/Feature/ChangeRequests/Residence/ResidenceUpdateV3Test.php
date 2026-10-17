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
use App\Support\ChangeRequests\Handlers\ResidenceUpdateHandler;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Feature\Geography\BuildsGazaWorkbooks;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * RESIDENCE_UPDATE payload v3 (FB-GEO-02): the only version of a NEW family
 * submission — original_location_id (actual home before displacement),
 * current_location_id, address_text and displacement_status, each optional.
 * original_residence_text is the identity-card residence: read-only for
 * families and never written by a v3 APPLY. Synthetic data only.
 */
class ResidenceUpdateV3Test extends TestCase
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

    private function submit(array $data): TestResponse
    {
        return $this->actingAs($this->context->user->fresh())->postJson(self::FAMILY_URI, [
            'type' => 'RESIDENCE_UPDATE', 'client_reference' => (string) Str::uuid(), 'data' => $data,
        ]);
    }

    private function submitted(array $data): ChangeRequest
    {
        return ChangeRequest::where('uuid', $this->submit($data)->assertCreated()->json('data.id'))->sole();
    }

    private function staff(string $method, string $uri, array $body = []): TestResponse
    {
        return $this->actingAs($this->reviewer->fresh())->json($method, self::STAFF_URI.$uri, $body);
    }

    private function approveAndApply(ChangeRequest $request): void
    {
        $this->staff('POST', "/{$request->uuid}/start-review")->assertOk();
        $this->staff('POST', "/{$request->uuid}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.status', 'APPLIED');
    }

    private function staffCorrects(array $data): void
    {
        app(UpdateFamilyResidenceAction::class)->handle($this->context->family, $data, $this->reviewer->id);
    }

    /** Every historical text field of the residence. */
    private function historicalText(): array
    {
        return $this->residence->fresh()->only(['governorate', 'city', 'area', 'neighborhood', 'original_residence_text']);
    }

    // ---------------------------------------------------------------- 1. structured submission

    public function test_a_v3_submission_carries_the_structured_locations_and_changes_nothing_canonical(): void
    {
        $original = $this->loc(GazaLocationLevel::AREA, '1');
        $current = $this->loc(GazaLocationLevel::LANDMARK, '7');
        $before = $this->residence->fresh()->toArray();

        // Upper case is the same value (normalized like the Staff path).
        $request = $this->submitted(['original_location_id' => strtoupper($original), 'current_location_id' => $current]);

        $this->assertSame(3, $request->payload_version);
        $this->assertEquals(['original_location_id' => $original, 'current_location_id' => $current], $request->submitted_data);
        $this->assertSame($before, $this->residence->fresh()->toArray(), 'nothing applied before approval');

        $this->approveAndApply($request);
        $fresh = $this->residence->fresh();
        $this->assertSame([$original, $current], [$fresh->original_location_id, $fresh->current_location_id]);
    }

    // ---------------------------------------------------------------- 2. identity-card residence is read-only

    public function test_the_identity_card_residence_is_read_only_for_families(): void
    {
        $response = $this->submit(['original_residence_text' => 'عنوان آخر'])->assertUnprocessable();
        $this->assertArrayHasKey('original_residence_text', $response->json('errors'));
        $this->submit(['original_residence_text' => 'عنوان آخر', 'address_text' => 'شارع جديد'])->assertUnprocessable()
            ->assertJsonValidationErrors('original_residence_text');
        $this->assertSame(0, ChangeRequest::count());

        // Still shown to the family (read-only), and untouched by a v3 apply.
        $this->actingAs($this->context->user->fresh())->getJson('/api/v1/family/household/profile')
            ->assertOk()->assertJsonPath('data.residence.original_residence_text', 'بني سهيلا');
        $this->approveAndApply($this->submitted(['address_text' => 'شارع جديد']));
        $this->assertSame('بني سهيلا', $this->residence->fresh()->original_residence_text);
    }

    // ---------------------------------------------------------------- 3. legacy and unknown fields

    public function test_legacy_free_text_and_unknown_fields_are_refused_with_or_without_v3_fields(): void
    {
        $refused = [
            'governorate' => 'غزة', 'city' => 'غزة', 'area' => 'الرمال', 'neighborhood' => 'حي النصر',
            'displacement_location_text' => 'مواصي', 'original_residence_text' => 'بني سهيلا',
            'residence_type' => 'خيمة', 'latitude' => '31.5', 'family_id' => 999, 'unknown_key' => 'x',
        ];
        foreach ($refused as $key => $value) {
            foreach ([[$key => $value], ['address_text' => 'شارع جديد', $key => $value], ['current_location_id' => $this->loc(GazaLocationLevel::AREA, '1'), $key => $value]] as $data) {
                $response = $this->submit($data)->assertUnprocessable();
                $this->assertArrayHasKey($key, $response->json('errors'), $key);
            }
        }
        $this->assertSame(0, ChangeRequest::count());
    }

    // ---------------------------------------------------------------- 4. omitted fields

    public function test_omitted_fields_are_never_proposed_and_stay_unchanged_after_apply(): void
    {
        $original = $this->loc(GazaLocationLevel::AREA, '1');
        $current = $this->loc(GazaLocationLevel::NEIGHBORHOOD, '10');
        $this->residence->forceFill(['original_location_id' => $original, 'current_location_id' => $current])->save();

        $request = $this->submitted(['address_text' => 'شارع جديد 9']);
        $this->assertEquals(['address_text' => 'شارع جديد 9'], $request->submitted_data, 'no null built for an omitted field');

        $this->approveAndApply($request);
        $fresh = $this->residence->fresh();
        $this->assertSame('شارع جديد 9', $fresh->address_text);
        $this->assertSame([$original, $current], [$fresh->original_location_id, $fresh->current_location_id]);
        $this->assertSame(DisplacementStatus::DISPLACED, $fresh->displacement_status);
        $this->assertSame('مواصي خانيونس', $fresh->displacement_location_text);
    }

    // ---------------------------------------------------------------- 5. explicit clearing

    public function test_an_explicit_null_clears_only_that_location(): void
    {
        $original = $this->loc(GazaLocationLevel::AREA, '1');
        $current = $this->loc(GazaLocationLevel::NEIGHBORHOOD, '10');
        $this->residence->forceFill(['original_location_id' => $original, 'current_location_id' => $current])->save();

        $request = $this->submitted(['current_location_id' => null]);
        $this->assertEquals(['current_location_id' => null], $request->submitted_data);

        $this->approveAndApply($request);
        $this->assertNull($this->residence->fresh()->current_location_id);
        $this->assertSame($original, $this->residence->fresh()->original_location_id);
    }

    // ---------------------------------------------------------------- 6. historical text preserved

    public function test_historical_text_fields_survive_approval_and_application(): void
    {
        $text = $this->historicalText();

        $request = $this->submitted([
            'original_location_id' => $this->loc(GazaLocationLevel::AREA, '1'),
            'current_location_id' => $this->loc(GazaLocationLevel::LANDMARK, '7'),
            'address_text' => 'شارع جديد 9',
        ]);
        $this->approveAndApply($request);

        $this->assertSame($text, $this->historicalText(), 'no historical text rewritten');
        $this->assertSame('مواصي خانيونس', $this->residence->fresh()->displacement_location_text, 'still displaced: kept');
    }

    // ---------------------------------------------------------------- 7. displacement status

    public function test_displacement_status_is_validated_and_a_known_status_cannot_be_cleared(): void
    {
        $this->submit(['displacement_status' => null])->assertUnprocessable()->assertJsonValidationErrors('displacement_status');
        $this->submit(['displacement_status' => 'MAYBE'])->assertUnprocessable()->assertJsonValidationErrors('displacement_status');
        // Sending the current status alone changes nothing.
        $this->submit(['displacement_status' => 'DISPLACED'])->assertUnprocessable()->assertJsonValidationErrors('data');
        $this->assertSame(0, ChangeRequest::count());

        // A status not collected yet may be set (and "not collected" stays allowed).
        $this->residence->forceFill(['displacement_status' => null, 'displacement_location_text' => null])->save();
        $request = $this->submitted(['displacement_status' => 'NOT_DISPLACED']);
        $this->assertEquals(['displacement_status' => 'NOT_DISPLACED'], $request->submitted_data);
    }

    public function test_leaving_displacement_keeps_the_historical_text_and_the_current_location(): void
    {
        $current = $this->loc(GazaLocationLevel::LANDMARK, '7');
        $this->residence->forceFill(['current_location_id' => $current])->save();
        $text = $this->historicalText();

        $request = $this->submitted(['displacement_status' => 'NOT_DISPLACED']);
        $this->assertEquals(['displacement_status' => 'NOT_DISPLACED'], $request->submitted_data, 'the text is never proposed');

        // The reviewer sees the status change only — nothing is announced as deleted.
        $this->staff('GET', "/{$request->uuid}")->assertOk()->assertJsonPath('data.presentation.rows', [
            ['label' => 'حالة النزوح', 'current' => 'نازحة', 'proposed' => 'غير نازحة'],
        ]);

        $this->approveAndApply($request);
        $fresh = $this->residence->fresh();
        $this->assertSame(DisplacementStatus::NOT_DISPLACED, $fresh->displacement_status);
        $this->assertSame('مواصي خانيونس', $fresh->displacement_location_text, 'historical text kept');
        $this->assertSame($current, $fresh->current_location_id, 'never cleared by a status change');
        $this->assertSame($text, $this->historicalText());

        // And back again: still nothing cleared.
        $this->approveAndApply($this->submitted(['displacement_status' => 'DISPLACED']));
        $fresh = $this->residence->fresh();
        $this->assertSame(['مواصي خانيونس', $current], [$fresh->displacement_location_text, $fresh->current_location_id]);
    }

    // ---------------------------------------------------------------- 8. inactive and invalid references

    public function test_inactive_unknown_and_malformed_references(): void
    {
        $inactive = $this->loc(GazaLocationLevel::NEIGHBORHOOD, '12');
        GazaLocation::whereKey($inactive)->update(['is_active' => false]);

        foreach ([$inactive, 'not-a-uuid', '00000000-0000-4000-8000-00000000abcd'] as $value) {
            $this->submit(['current_location_id' => $value])->assertUnprocessable()->assertJsonValidationErrors('current_location_id');
            $this->submit(['original_location_id' => $value])->assertUnprocessable()->assertJsonValidationErrors('original_location_id');
        }

        // An unchanged saved inactive reference may be sent back: not proposed, kept.
        $this->residence->forceFill(['original_location_id' => $inactive])->save();
        $request = $this->submitted(['original_location_id' => $inactive, 'address_text' => 'شارع جديد 9']);
        $this->assertEquals(['address_text' => 'شارع جديد 9'], $request->submitted_data);
        $this->approveAndApply($request);
        $this->assertSame($inactive, $this->residence->fresh()->original_location_id);
    }

    public function test_a_reference_deactivated_after_submission_fails_the_precondition(): void
    {
        $chosen = $this->loc(GazaLocationLevel::LANDMARK, '7');
        $request = $this->submitted(['current_location_id' => $chosen]);
        $this->staff('POST', "/{$request->uuid}/start-review")->assertOk();
        GazaLocation::whereKey($chosen)->update(['is_active' => false]);

        $this->staff('POST', "/{$request->uuid}/approve")->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_PRECONDITION_FAILED');
        $this->assertNull($this->residence->fresh()->current_location_id);
    }

    // ---------------------------------------------------------------- 9. concurrent Staff changes

    public function test_staff_changes_inside_the_v3_base_block_approval(): void
    {
        foreach ([
            ['current_location_id' => $this->loc(GazaLocationLevel::GOVERNORATE, '44')],
            ['address_text' => 'تصحيح الطاقم'],
            ['original_location_id' => $this->loc(GazaLocationLevel::AREA, '1')],
        ] as $staffChange) {
            $request = $this->submitted(['displacement_status' => 'NOT_DISPLACED']);
            $this->staff('POST', "/{$request->uuid}/start-review")->assertOk();
            $this->staffCorrects($staffChange);

            $this->staff('POST', "/{$request->uuid}/approve")->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_BASE_CHANGED');
            $this->assertSame(DisplacementStatus::DISPLACED, $this->residence->fresh()->displacement_status, 'nothing applied');
            $this->actingAs($this->context->user->fresh())->postJson(self::FAMILY_URI."/{$request->uuid}/cancel")->assertOk();
        }
    }

    public function test_a_staff_change_outside_the_v3_fields_does_not_block_and_is_never_overwritten(): void
    {
        $request = $this->submitted(['current_location_id' => $this->loc(GazaLocationLevel::LANDMARK, '7')]);
        $this->staff('POST', "/{$request->uuid}/start-review")->assertOk();
        // Historical text — the displacement-location text included — is outside the v3 base.
        $this->staffCorrects(['governorate' => 'غزة', 'original_residence_text' => 'تصحيح الطاقم', 'displacement_location_text' => 'مكان آخر']);

        $this->staff('POST', "/{$request->uuid}/approve")->assertOk();
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.status', 'APPLIED');
        $fresh = $this->residence->fresh();
        $this->assertSame(['غزة', 'تصحيح الطاقم', 'مكان آخر'], [$fresh->governorate, $fresh->original_residence_text, $fresh->displacement_location_text]);
    }

    // ---------------------------------------------------------------- 10. reviewer presentation

    public function test_reviewer_and_family_see_corrected_labels_names_and_paths_never_uuids(): void
    {
        $original = $this->loc(GazaLocationLevel::AREA, '1');
        $current = $this->loc(GazaLocationLevel::LANDMARK, '7');
        $request = $this->submitted(['original_location_id' => $original, 'current_location_id' => $current, 'address_text' => 'شارع جديد 9']);

        $expected = [
            ['label' => 'العنوان التفصيلي', 'current' => 'شارع التجربة 1', 'proposed' => 'شارع جديد 9'],
            ['label' => 'الموقع الجغرافي للسكن الفعلي قبل النزوح', 'current' => null, 'proposed' => 'غزة الاختبار ‹ مدينة الاختبار ‹ غرب'],
            ['label' => 'الموقع الجغرافي للسكن الحالي', 'current' => null, 'proposed' => 'خان الاختبار ‹ مدينة الساحل ‹ منطقة الساحل ‹ حي المواصي ‹ نقطة'],
        ];
        $staffView = $this->staff('GET', "/{$request->uuid}")->assertOk()->assertJsonPath('data.presentation.rows', $expected);
        $familyView = $this->actingAs($this->context->user->fresh())->getJson(self::FAMILY_URI."/{$request->uuid}")->assertOk()
            ->assertJsonPath('data.presentation.rows', $expected);
        foreach ([$staffView, $familyView] as $view) {
            $this->assertStringNotContainsString($original, $view->getContent());
            $this->assertStringNotContainsString($current, $view->getContent());
        }

        // The identity-card residence is never labelled as the home before displacement.
        $this->assertSame('السكن حسب بطاقة الهوية', ResidenceUpdateHandler::FIELDS['original_residence_text']);
        $this->assertStringNotContainsString('قبل النزوح', ResidenceUpdateHandler::FIELDS['original_residence_text']);
    }

    // ---------------------------------------------------------------- 11. legacy compatibility

    public function test_stored_v1_and_v2_requests_still_review_approve_and_apply(): void
    {
        $v2 = $this->legacyResidenceRequest(2, ['neighborhood' => 'حي النصر', 'current_location_id' => $this->loc(GazaLocationLevel::AREA, '1')]);
        $this->approveAndApply($v2);
        $this->assertSame('حي النصر', $this->residence->fresh()->neighborhood);
        $this->assertSame(2, $v2->fresh()->payload_version, 'never migrated');

        $v1 = $this->legacyResidenceRequest(1, ['city' => 'رفح']);
        $this->approveAndApply($v1);
        $this->assertSame('رفح', $this->residence->fresh()->city);
        $this->assertSame(1, $v1->fresh()->payload_version, 'never migrated');
    }

    // ---------------------------------------------------------------- 12. no direct family write access

    public function test_a_family_can_never_write_or_apply_directly(): void
    {
        $family = $this->context->user->fresh();
        $this->actingAs($family)
            ->patchJson("/api/v1/families/{$this->context->family->family_code}/residence", ['current_location_id' => $this->loc(GazaLocationLevel::LANDMARK, '7')])
            ->assertForbidden();

        $request = $this->submitted(['address_text' => 'شارع جديد 9']);
        foreach (['start-review', 'approve', 'apply'] as $action) {
            $this->actingAs($family)->postJson(self::STAFF_URI."/{$request->uuid}/{$action}")->assertForbidden();
        }
        $this->assertSame('شارع التجربة 1', $this->residence->fresh()->address_text);
        $this->assertNull($this->residence->fresh()->current_location_id);
    }
}
