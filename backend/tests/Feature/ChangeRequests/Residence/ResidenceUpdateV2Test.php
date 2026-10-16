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
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Feature\Geography\BuildsGazaWorkbooks;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * RESIDENCE_UPDATE payload v2 (FB-GEO-01 ADR §8.5): the two optional
 * structured references through submit → review → approve / reject → apply,
 * their validation, the base fingerprint, the reviewer presentation, and v1
 * requests submitted before v2 (never migrated). Synthetic data only.
 */
class ResidenceUpdateV2Test extends TestCase
{
    use BuildsGazaWorkbooks;
    use FamilyIdentityFixtures;
    use RefreshDatabase;

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

    /** The current residence as the family form sends it (all ten fields), with overrides. */
    private function proposal(array $overrides = []): array
    {
        $r = $this->residence->fresh();

        return [
            'governorate' => $r->governorate, 'city' => $r->city, 'area' => $r->area, 'neighborhood' => $r->neighborhood,
            'address_text' => $r->address_text, 'original_residence_text' => $r->original_residence_text,
            'displacement_status' => $r->displacement_status?->value, 'displacement_location_text' => $r->displacement_location_text,
            'original_location_id' => $r->original_location_id, 'current_location_id' => $r->current_location_id,
            ...$overrides,
        ];
    }

    private function submit(array $data): TestResponse
    {
        return $this->actingAs($this->context->user->fresh())->postJson(self::FAMILY_URI, [
            'type' => 'RESIDENCE_UPDATE', 'client_reference' => (string) Str::uuid(), 'data' => $data,
        ]);
    }

    private function submitted(array $overrides): ChangeRequest
    {
        return ChangeRequest::where('uuid', $this->submit($this->proposal($overrides))->assertCreated()->json('data.id'))->sole();
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

    // ---------------------------------------------------------------- submission

    public function test_a_v2_submission_carries_both_references_and_changes_nothing_canonical(): void
    {
        $original = $this->loc(GazaLocationLevel::AREA, '1');
        $current = $this->loc(GazaLocationLevel::LANDMARK, '7');
        $before = $this->residence->fresh()->toArray();

        // Upper case is the same value (normalized like the Staff path).
        $request = $this->submitted(['original_location_id' => strtoupper($original), 'current_location_id' => $current]);

        $this->assertSame(2, $request->payload_version);
        $this->assertEquals(['original_location_id' => $original, 'current_location_id' => $current], $request->submitted_data);
        $this->assertSame($before, $this->residence->fresh()->toArray(), 'nothing applied before approval');
    }

    public function test_references_are_optional_omitted_keeps_and_null_clears(): void
    {
        $saved = $this->loc(GazaLocationLevel::NEIGHBORHOOD, '10');
        $this->residence->forceFill(['current_location_id' => $saved])->save();

        // A client sending only the eight v1 fields: references untouched.
        $eight = array_diff_key($this->proposal(['neighborhood' => 'حي النصر']), array_flip(['original_location_id', 'current_location_id']));
        $id = $this->submit($eight)->assertCreated()->json('data.id');
        $this->assertEquals(['neighborhood' => 'حي النصر'], ChangeRequest::where('uuid', $id)->sole()->submitted_data);
        $this->actingAs($this->context->user->fresh())->postJson(self::FAMILY_URI."/{$id}/cancel")->assertOk();

        // Unchanged references are not part of the proposal; nothing else → refused.
        $this->submit($this->proposal())->assertUnprocessable();

        // null clears a saved reference.
        $request = $this->submitted(['current_location_id' => null]);
        $this->assertEquals(['current_location_id' => null], $request->submitted_data);
        $this->approveAndApply($request);
        $this->assertNull($this->residence->fresh()->current_location_id);
    }

    public function test_an_unchanged_inactive_reference_is_kept_and_new_inactive_invalid_or_unknown_ones_are_refused(): void
    {
        $inactive = $this->loc(GazaLocationLevel::NEIGHBORHOOD, '12');
        $this->residence->forceFill(['original_location_id' => $inactive])->save();
        GazaLocation::whereKey($inactive)->update(['is_active' => false]);

        // Sent back unchanged with a text change: accepted, reference not in the proposal.
        $request = $this->submitted(['address_text' => 'شارع جديد 9']);
        $this->assertEquals(['address_text' => 'شارع جديد 9'], $request->submitted_data);
        $this->approveAndApply($request);
        $this->assertSame($inactive, $this->residence->fresh()->original_location_id);

        foreach ([$inactive => 'current_location_id', 'not-a-uuid' => 'current_location_id', '00000000-0000-4000-8000-00000000abcd' => 'current_location_id'] as $value => $field) {
            $this->submit($this->proposal([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
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
            ['label' => 'الموقع الجغرافي للسكن الأصلي', 'current' => 'غزة الاختبار ‹ مدينة الاختبار ‹ شرق ‹ حي الشجاعية (غير مفعّلة)', 'proposed' => 'غزة الاختبار ‹ مدينة الاختبار ‹ غرب'],
            ['label' => 'الموقع الجغرافي الحالي / مكان النزوح', 'current' => null, 'proposed' => 'خان الاختبار ‹ مدينة الساحل ‹ منطقة الساحل ‹ حي المواصي ‹ نقطة'],
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

        $this->assertSame(['id', 'is_active', 'level', 'name', 'path'], collect($residence['original_location'])->keys()->sort()->values()->all());
        $this->assertSame($inactive, $residence['original_location']['id']);
        $this->assertFalse($residence['original_location']['is_active']);
        $this->assertSame(['غزة الاختبار', 'مدينة الاختبار', 'شرق'], array_column($residence['original_location']['path'], 'name'));
        $this->assertSame('نقطة', $residence['current_location']['name']);
        $this->assertArrayNotHasKey('original_location_id', $residence);
        $this->assertArrayNotHasKey('current_location_id', $residence);
        $this->assertSame('شارع التجربة 1', $residence['current_address']['address_text'], 'text unchanged beside it');
        $json = json_encode($residence, JSON_UNESCAPED_UNICODE);
        foreach (['source_code', 'landmark_type', 'review', 'flag'] as $forbidden) {
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
