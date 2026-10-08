<?php

namespace Tests\Feature\ChangeRequests\Residence;

use App\Actions\ChangeRequests\ApplyChangeRequestAction;
use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Actions\UpdateFamilyResidenceAction;
use App\Enums\AuthIdentityStatus;
use App\Enums\ChangeRequestApplyFailure;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Enums\DisplacementStatus;
use App\Enums\FamilyActivityType;
use App\Enums\FamilyStatus;
use App\Enums\FingerprintContext;
use App\Enums\WorkflowEventType;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyAuthIdentity;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Models\WorkflowEvent;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyAuth\KeyedFingerprint;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * RESIDENCE_UPDATE end to end (PWA-6.1) on the REAL Production registry,
 * through the Family and Staff APIs: submission, validation, isolation,
 * idempotency, open conflict, base fingerprint, approve / apply through
 * UpdateFamilyResidenceAction, rollback and retry, presentation and privacy.
 * Synthetic data only.
 */
class ResidenceUpdateTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const FAMILY_URI = '/api/v1/family/change-requests';

    private const STAFF_URI = '/api/v1/change-requests';

    private FamilyAccessResult $context;

    private FamilyResidence $residence;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        config(['change_requests.family_submission_enabled' => true]);
        $this->seed(RolePermissionSeeder::class);

        $head = $this->activatedHead();
        $this->context = app(FamilyAccessResolver::class)->familyContext($head['user']);
        $this->residence = FamilyResidence::factory()->create([
            'family_id' => $this->context->family->id,
            'residence_type' => 'شقة', 'governorate' => 'خانيونس', 'city' => 'خانيونس', 'area' => 'البلد',
            'neighborhood' => 'حي الأمل', 'address_text' => 'شارع التجربة 1', 'original_residence_text' => 'بني سهيلا',
            'displacement_status' => DisplacementStatus::NOT_DISPLACED, 'displacement_location_text' => null,
            'latitude' => '31.3400000', 'longitude' => '34.3000000', 'notes' => 'ملاحظة داخلية', 'started_at' => '2020-01-01',
        ]);
        $this->reviewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('REVIEWER', 'web')));
    }

    /** The current residence as the family would send it, with overrides. */
    private function proposal(array $overrides = []): array
    {
        return [
            'governorate' => 'خانيونس', 'city' => 'خانيونس', 'area' => 'البلد', 'neighborhood' => 'حي الأمل',
            'address_text' => 'شارع التجربة 1', 'original_residence_text' => 'بني سهيلا',
            'displacement_status' => 'NOT_DISPLACED', 'displacement_location_text' => null,
            ...$overrides,
        ];
    }

    private function family(string $method, string $uri = '', array $body = [], ?User $as = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->context->user)->fresh())->json($method, self::FAMILY_URI.$uri, $body);
    }

    private function staff(string $method, string $uri, array $body = []): TestResponse
    {
        return $this->actingAs($this->reviewer->fresh())->json($method, self::STAFF_URI.$uri, $body);
    }

    private function submit(array $data, ?string $reference = null, ?string $reason = null, ?User $as = null): TestResponse
    {
        return $this->family('POST', '', array_filter([
            'type' => 'RESIDENCE_UPDATE', 'client_reference' => $reference ?? (string) Str::uuid(), 'reason' => $reason, 'data' => $data,
        ], fn ($v) => $v !== null), $as);
    }

    private function submitted(array $overrides = ['neighborhood' => 'حي النصر']): ChangeRequest
    {
        $id = $this->submit($this->proposal($overrides))->assertCreated()->json('data.id');

        return ChangeRequest::where('uuid', $id)->sole();
    }

    private function approved(array $overrides = ['neighborhood' => 'حي النصر']): ChangeRequest
    {
        $request = $this->submitted($overrides);
        $this->staff('POST', "/{$request->uuid}/start-review")->assertOk();
        $this->staff('POST', "/{$request->uuid}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');

        return $request->fresh();
    }

    /** A Staff correction of the residence through the canonical action (a parallel registry change). */
    private function staffCorrects(array $data): void
    {
        app(UpdateFamilyResidenceAction::class)->handle($this->context->family, $data, $this->reviewer->id);
    }

    private function residenceSnapshot(): array
    {
        return FamilyResidence::query()->whereKey($this->residence->id)->first()->only([
            'governorate', 'city', 'area', 'neighborhood', 'address_text', 'original_residence_text',
            'displacement_location_text', 'residence_type', 'latitude', 'longitude', 'notes', 'is_current', 'updated_by',
        ]);
    }

    // ---------------------------------------------------------------- submission

    public function test_a_valid_submission_stores_only_the_changed_fields_and_changes_nothing_canonical(): void
    {
        $before = $this->residenceSnapshot();

        $response = $this->submit($this->proposal(['neighborhood' => '  حي النصر  ', 'address_text' => 'شارع الشهداء 4']), reason: 'انتقلنا داخل المدينة')
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.status', 'SUBMITTED')->assertJsonPath('data.replayed', false);
        $request = ChangeRequest::where('uuid', $response->json('data.id'))->sole();

        $this->assertSame(ChangeRequestType::RESIDENCE_UPDATE, $request->type);
        // jsonb does not keep key order; the presentation has its own fixed order.
        $this->assertEquals(['neighborhood' => 'حي النصر', 'address_text' => 'شارع الشهداء 4'], $request->submitted_data);
        $this->assertSame($this->context->family->id, $request->family_id);
        $this->assertNull($request->target_membership_id);
        $this->assertNull($request->person_id);
        $this->assertSame(1, $request->payload_version);
        $this->assertNotNull($request->base_fingerprint);
        $this->assertSame($before, $this->residenceSnapshot());

        $this->assertSame(1, WorkflowEvent::where('event_type', WorkflowEventType::SUBMITTED)->count());
        $this->assertSame(['request_type' => 'RESIDENCE_UPDATE'],
            FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_SUBMITTED)->sole()->metadata);
        $this->assertSame(0, FamilyActivity::whereIn('event_type', [FamilyActivityType::RESIDENCE_UPDATED, FamilyActivityType::DISPLACEMENT_UPDATED])->count());
    }

    public function test_invalid_values_are_refused_field_by_field(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $cases = [
            'missing field' => [array_diff_key($this->proposal(['city' => 'غزة']), ['area' => 1]), 'data.area'],
            'too long' => [$this->proposal(['city' => str_repeat('أ', 256)]), 'data.city'],
            'not a string' => [$this->proposal(['city' => ['nested' => 'x']]), 'data.city'],
            'unknown status' => [$this->proposal(['displacement_status' => 'MAYBE']), 'data.displacement_status'],
            'location while not displaced' => [$this->proposal(['displacement_location_text' => 'مواصي خانيونس']), 'data.displacement_location_text'],
            'clearing a known status' => [$this->proposal(['displacement_status' => null, 'city' => 'غزة']), 'data.displacement_status'],
        ];
        foreach ($cases as $label => [$data, $error]) {
            $response = $this->submit($data)->assertUnprocessable();
            $key = substr($error, 5);
            $this->assertArrayHasKey($key, $response->json('errors'), $label);
        }
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_unknown_fields_are_refused_not_dropped(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (['family_id' => 999, 'residence_id' => 1, 'residence_type' => 'خيمة', 'latitude' => '31.5', 'is_current' => false,
            'notes' => 'x', 'updated_by' => 1, 'status' => 'APPLIED', 'member_ref' => str_repeat('a', 64)] as $key => $value) {
            $response = $this->submit([...$this->proposal(['city' => 'غزة']), $key => $value])->assertUnprocessable();
            $this->assertArrayHasKey($key, $response->json('errors'), $key);
        }
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_a_proposal_that_changes_nothing_is_refused(): void
    {
        $this->submit($this->proposal(['city' => ' خانيونس ']))->assertUnprocessable()->assertJsonValidationErrors('data');
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_a_family_without_a_current_residence_cannot_submit(): void
    {
        $this->residence->forceFill(['is_current' => false, 'ended_at' => now()])->save();

        $this->submit($this->proposal(['city' => 'غزة']))->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_PRECONDITION_FAILED');
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_the_switch_off_refuses_before_anything_is_read(): void
    {
        config(['change_requests.family_submission_enabled' => false]);

        $this->submit(['anything' => 1])->assertStatus(503)->assertJsonPath('code', 'CHANGE_REQUEST_SUBMISSION_DISABLED');
        $this->family('GET', '/types')->assertExactJson(['data' => [], 'meta' => ['submission_enabled' => false]]);
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_type_discovery_lists_residence_update_while_the_switch_is_on(): void
    {
        $this->family('GET', '/types')->assertOk()
            ->assertExactJson(['data' => [['type' => 'RESIDENCE_UPDATE']], 'meta' => ['submission_enabled' => true]]);
    }

    public function test_the_target_is_always_the_context_family(): void
    {
        $other = Family::factory()->create();
        $otherResidence = FamilyResidence::factory()->create(['family_id' => $other->id, 'city' => 'رفح']);

        $request = $this->submitted(['city' => 'غزة']);
        $this->assertSame($this->context->family->id, $request->family_id);

        $this->staff('POST', "/{$request->uuid}/start-review")->assertOk();
        $this->staff('POST', "/{$request->uuid}/approve")->assertOk();
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk();

        $this->assertSame('غزة', $this->residence->fresh()->city);
        $this->assertSame('رفح', $otherResidence->fresh()->city);
    }

    public function test_another_familys_request_is_not_found(): void
    {
        $request = $this->submitted();
        $stranger = $this->activatedHead('222222222')['user'];

        $this->family('GET', "/{$request->uuid}", as: $stranger)->assertNotFound()->assertJsonPath('code', 'CHANGE_REQUEST_NOT_FOUND');
        $this->family('POST', "/{$request->uuid}/cancel", as: $stranger)->assertNotFound();
        $this->assertSame(S::SUBMITTED, $request->fresh()->status);
    }

    public function test_an_inactive_family_has_no_family_context(): void
    {
        $this->context->family->forceFill(['status' => FamilyStatus::INACTIVE])->save();

        $this->submit($this->proposal(['city' => 'غزة']))->assertForbidden();
        $this->assertSame(0, ChangeRequest::count());
    }

    // ---------------------------------------------------------------- idempotency and conflicts

    public function test_the_same_client_reference_replays_and_a_different_proposal_conflicts(): void
    {
        $reference = (string) Str::uuid();
        $first = $this->submit($this->proposal(['city' => 'غزة']), $reference)->assertCreated();
        $this->submit($this->proposal(['city' => 'غزة']), $reference)->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('data.replayed', true);
        $this->submit($this->proposal(['city' => 'رفح']), $reference)->assertConflict()
            ->assertJsonPath('code', 'CHANGE_REQUEST_IDEMPOTENCY_CONFLICT');

        $this->assertSame(1, ChangeRequest::count());
        $this->assertSame(1, WorkflowEvent::where('event_type', WorkflowEventType::SUBMITTED)->count());
    }

    public function test_only_one_open_residence_update_per_family(): void
    {
        $first = $this->submitted(['city' => 'غزة']);
        $this->submit($this->proposal(['area' => 'الكتيبة']))->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_ALREADY_OPEN');

        $this->family('POST', "/{$first->uuid}/cancel")->assertOk();
        $this->submit($this->proposal(['area' => 'الكتيبة']))->assertCreated();
        $this->assertSame(2, ChangeRequest::count());
    }

    public function test_a_new_head_sees_and_can_cancel_the_previous_heads_request(): void
    {
        $request = $this->submitted();
        $family = $this->context->family;

        FamilyMembership::query()->where('family_id', $family->id)->update(['is_household_head' => false]);
        $person = Person::factory()->create(['national_id' => '333333333']);
        FamilyMembership::factory()->create(['family_id' => $family->id, 'person_id' => $person->id, 'is_household_head' => true]);
        $user = $this->familyUser();
        UserPersonLink::factory()->create(['user_id' => $user->id, 'person_id' => $person->id]);
        FamilyAuthIdentity::factory()->create([
            'user_id' => $user->id, 'login_key' => KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '333333333'),
            'key_version' => 1, 'status' => AuthIdentityStatus::ACTIVE->value,
        ]);

        // The previous head no longer has a family context.
        $this->family('GET', "/{$request->uuid}")->assertForbidden();
        $this->family('GET', "/{$request->uuid}", as: $user)->assertOk()->assertJsonPath('data.available_actions', ['cancel']);
        $this->family('POST', "/{$request->uuid}/cancel", as: $user)->assertOk();
        $this->assertSame(S::CANCELLED, $request->fresh()->status);
        $this->assertSame('حي الأمل', $this->residence->fresh()->neighborhood);
    }

    // ---------------------------------------------------------------- approve and apply

    public function test_approve_changes_nothing_and_apply_writes_only_the_proposal_through_the_domain_action(): void
    {
        $request = $this->approved(['neighborhood' => 'حي النصر', 'displacement_status' => 'DISPLACED', 'displacement_location_text' => 'مواصي خانيونس']);
        $this->assertSame('حي الأمل', $this->residence->fresh()->neighborhood);
        $this->assertSame(0, FamilyActivity::whereIn('event_type', [FamilyActivityType::RESIDENCE_UPDATED, FamilyActivityType::DISPLACEMENT_UPDATED])->count());

        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.status', 'APPLIED');

        $fresh = $this->residence->fresh();
        $this->assertSame('حي النصر', $fresh->neighborhood);
        $this->assertSame(DisplacementStatus::DISPLACED, $fresh->displacement_status);
        $this->assertSame('مواصي خانيونس', $fresh->displacement_location_text);
        // Untouched: everything outside the proposal, and the row itself (a correction, not a move).
        $this->assertSame(['خانيونس', 'خانيونس', 'البلد', 'شارع التجربة 1', 'بني سهيلا', 'شقة', '31.3400000', '34.3000000', 'ملاحظة داخلية'],
            [$fresh->governorate, $fresh->city, $fresh->area, $fresh->address_text, $fresh->original_residence_text, $fresh->residence_type,
                $fresh->latitude, $fresh->longitude, $fresh->notes]);
        $this->assertTrue($fresh->is_current);
        $this->assertSame('2020-01-01', $fresh->started_at->toDateString());
        $this->assertSame($this->reviewer->id, $fresh->updated_by);
        $this->assertSame(1, FamilyResidence::where('family_id', $this->context->family->id)->count());

        $request->refresh();
        $this->assertSame(S::APPLIED, $request->status);
        $this->assertSame($this->reviewer->id, $request->applied_by);
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::RESIDENCE_UPDATED)->count());
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::DISPLACEMENT_UPDATED)->count());
        $this->assertSame(['request_type' => 'RESIDENCE_UPDATE'],
            FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_APPLIED)->sole()->metadata);

        // A second apply is a replay: nothing runs again.
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::RESIDENCE_UPDATED)->count());
    }

    public function test_apply_calls_the_canonical_residence_action_once_with_the_stored_proposal(): void
    {
        $calls = [];
        $this->app->bind(UpdateFamilyResidenceAction::class, function () use (&$calls) {
            return new class($calls) extends UpdateFamilyResidenceAction
            {
                public function __construct(private array &$calls) {}

                public function handle(Family $family, array $data, ?int $actingUserId): Family
                {
                    $this->calls[] = [$family->id, $data, $actingUserId];

                    return parent::handle($family, $data, $actingUserId);
                }
            };
        });
        $request = $this->approved(['city' => 'غزة']);

        $this->staff('POST', "/{$request->uuid}/apply")->assertOk();

        $this->assertSame([[$this->context->family->id, ['city' => 'غزة'], $this->reviewer->id]], $calls);
    }

    public function test_switching_to_not_displaced_clears_the_location_in_the_proposal_and_on_apply(): void
    {
        $this->residence->forceFill(['displacement_status' => DisplacementStatus::DISPLACED, 'displacement_location_text' => 'مواصي خانيونس'])->save();

        $request = $this->submitted(['displacement_status' => 'NOT_DISPLACED', 'displacement_location_text' => null]);
        $this->assertEquals(['displacement_status' => 'NOT_DISPLACED', 'displacement_location_text' => null], $request->submitted_data);
        $this->family('GET', "/{$request->uuid}")->assertJsonPath('data.presentation.rows', [
            ['label' => 'حالة النزوح', 'current' => 'نازحة', 'proposed' => 'غير نازحة'],
            ['label' => 'مكان النزوح الحالي', 'current' => 'مواصي خانيونس', 'proposed' => null],
        ]);

        $this->staff('POST', "/{$request->uuid}/start-review")->assertOk();
        $this->staff('POST', "/{$request->uuid}/approve")->assertOk();
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk();
        $fresh = $this->residence->fresh();
        $this->assertSame(DisplacementStatus::NOT_DISPLACED, $fresh->displacement_status);
        $this->assertNull($fresh->displacement_location_text);
    }

    public function test_a_registry_change_after_submission_blocks_approval(): void
    {
        $request = $this->submitted(['neighborhood' => 'حي النصر']);
        $this->staff('POST', "/{$request->uuid}/start-review")->assertOk();

        // Even a field outside the proposal: the family proposed against the whole residence.
        $this->staffCorrects(['area' => 'الكتيبة']);

        $this->staff('POST', "/{$request->uuid}/approve")->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_BASE_CHANGED');
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);
        $this->assertSame('حي الأمل', $this->residence->fresh()->neighborhood);
    }

    public function test_a_registry_change_after_approval_blocks_apply_and_keeps_the_newer_data(): void
    {
        $request = $this->approved(['neighborhood' => 'حي النصر']);
        $this->staffCorrects(['neighborhood' => 'حي الزيتون']);

        $this->staff('POST', "/{$request->uuid}/apply")->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_BASE_CHANGED');

        $this->assertSame('حي الزيتون', $this->residence->fresh()->neighborhood);
        $fresh = $request->fresh();
        $this->assertSame(S::APPROVED, $fresh->status);
        $this->assertSame(1, $fresh->apply_failure_count);
        $this->assertSame(ChangeRequestApplyFailure::BASE_CHANGED->value,
            WorkflowEvent::where('event_type', WorkflowEventType::APPLY_FAILED)->sole()->reason_code);
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_APPLIED)->count());
    }

    public function test_a_residence_that_stopped_being_current_fails_the_precondition(): void
    {
        $request = $this->approved();
        $this->residence->forceFill(['is_current' => false, 'ended_at' => now()])->save();

        $this->staff('POST', "/{$request->uuid}/apply")->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_PRECONDITION_FAILED');
        $this->assertSame(S::APPROVED, $request->fresh()->status);
    }

    public function test_an_inactive_family_cannot_take_the_change(): void
    {
        $request = $this->approved();
        $this->context->family->forceFill(['status' => FamilyStatus::INACTIVE])->save();

        $this->staff('POST', "/{$request->uuid}/apply")->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_NOT_APPLICABLE');
        $this->assertSame('حي الأمل', $this->residence->fresh()->neighborhood);
    }

    public function test_an_unexpected_failure_rolls_everything_back_and_a_retry_applies(): void
    {
        $this->app->bind(UpdateFamilyResidenceAction::class, fn () => new class extends UpdateFamilyResidenceAction
        {
            public function handle(Family $family, array $data, ?int $actingUserId): Family
            {
                parent::handle($family, $data, $actingUserId);

                throw new RuntimeException('SQLSTATE secret حي النصر');
            }
        });
        $request = $this->approved(['neighborhood' => 'حي النصر']);

        $response = $this->staff('POST', "/{$request->uuid}/apply")->assertStatus(500)->assertJsonPath('code', 'CHANGE_REQUEST_APPLY_FAILED');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertSame('حي الأمل', $this->residence->fresh()->neighborhood);
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::RESIDENCE_UPDATED)->count());
        $this->assertSame(S::APPROVED, $request->fresh()->status);
        $this->assertSame(1, $request->fresh()->apply_failure_count);

        $this->app->offsetUnset(UpdateFamilyResidenceAction::class);
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.status', 'APPLIED');
        $this->assertSame('حي النصر', $this->residence->fresh()->neighborhood);
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::RESIDENCE_UPDATED)->count());
    }

    public function test_the_engine_actions_never_write_before_apply(): void
    {
        $before = $this->residenceSnapshot();
        $request = $this->submitted(['city' => 'غزة']);
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer);
        $this->assertSame($before, $this->residenceSnapshot());

        app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);
        $this->assertSame('غزة', $this->residence->fresh()->city);
    }

    // ---------------------------------------------------------------- presentation and privacy

    public function test_staff_and_family_see_the_same_ordered_comparison_rows(): void
    {
        $request = $this->submitted(['address_text' => 'شارع الشهداء 4', 'governorate' => 'غزة', 'displacement_status' => 'DISPLACED', 'displacement_location_text' => 'مواصي خانيونس']);
        $rows = [
            ['label' => 'المحافظة', 'current' => 'خانيونس', 'proposed' => 'غزة'],
            ['label' => 'العنوان التفصيلي', 'current' => 'شارع التجربة 1', 'proposed' => 'شارع الشهداء 4'],
            ['label' => 'حالة النزوح', 'current' => 'غير نازحة', 'proposed' => 'نازحة'],
            ['label' => 'مكان النزوح الحالي', 'current' => null, 'proposed' => 'مواصي خانيونس'],
        ];

        $staff = $this->staff('GET', "/{$request->uuid}")->assertOk()->assertJsonPath('data.type_available', true)->assertJsonPath('data.presentation', ['rows' => $rows]);
        $family = $this->family('GET', "/{$request->uuid}")->assertOk()->assertJsonPath('data.type_available', true)->assertJsonPath('data.presentation', ['rows' => $rows]);

        foreach ([$staff, $family] as $response) {
            foreach (['submitted_data', 'base_fingerprint', 'displacement_location_text', '"governorate"', 'NOT_DISPLACED', '123456789', 'ملاحظة داخلية', '31.34'] as $leak) {
                $this->assertStringNotContainsString($leak, $response->getContent(), $leak);
            }
        }
    }

    public function test_the_family_timeline_hides_apply_failures_and_internal_notes(): void
    {
        $request = $this->approved();
        $this->staffCorrects(['neighborhood' => 'حي الزيتون']);
        $this->staff('POST', "/{$request->uuid}/apply")->assertConflict();

        $family = $this->family('GET', "/{$request->uuid}")->assertOk();
        $this->assertSame(['SUBMITTED', 'REVIEW_STARTED', 'APPROVED'], array_column($family->json('data.timeline'), 'event_type'));
        $this->assertStringNotContainsString('APPLY_FAILED', $family->getContent());
        $staff = $this->staff('GET', "/{$request->uuid}")->assertOk();
        $this->assertContains('APPLY_FAILED', array_column($staff->json('data.timeline'), 'event_type'));
    }

    public function test_the_production_registry_serves_residence_update(): void
    {
        $this->assertTrue(app(ChangeRequestTypes::class)->has(ChangeRequestType::RESIDENCE_UPDATE));
        $this->assertTrue(app(ChangeRequestTypes::class)->handler(ChangeRequestType::RESIDENCE_UPDATE)->familySubmittable());
    }
}
