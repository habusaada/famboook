<?php

namespace Tests\Feature\Assessments;

use App\Enums\AssessmentStatus;
use App\Models\Assessment;
use App\Models\AssessmentDomain;
use App\Models\AssessmentResult;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use App\Models\User;
use Database\Seeders\AssessmentDomainSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

/**
 * Quick multi-domain family assessment V1 (docs/03-BUSINESS-RULES.md §40a,
 * docs/06-PERMISSIONS.md §47). All identity and note values are synthetic.
 */
class AssessmentApiTest extends TestCase
{
    use RefreshDatabase;

    private const NATIONAL_ID = '9990007771';

    private const HEALTH_DETAILS = 'تفاصيل صحية اختبارية سرية';

    private const SHELTER_NOTE = 'ملاحظة سكن اختبارية سرية';

    private const PROTECTION_NOTE = 'ملاحظة حماية اختبارية سرية';

    private const GENERAL_NOTE = 'ملاحظة عامة اختبارية سرية';

    private Family $family;

    private Person $head;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-24 10:00:00');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(AssessmentDomainSeeder::class);

        $this->family = Family::factory()->create();
        $this->head = Person::factory()->create(['national_id' => self::NATIONAL_ID]);
        FamilyMembership::factory()->householdHead()->create([
            'family_id' => $this->family->id,
            'person_id' => $this->head->id,
        ]);

        $this->user = $this->user('DATA_ENTRY', 'مدخل بيانات تجريبي');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?string $name = null): User
    {
        $user = User::factory()->create($name ? ['name' => $name] : []);
        $user->assignRole($role);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return [
            'assessment_date' => '2026-09-20',
            'general_notes' => self::GENERAL_NOTE,
            'results' => [
                ['domain_code' => 'SHELTER', 'rating' => 'CRITICAL', 'notes' => self::SHELTER_NOTE],
                ['domain_code' => 'FOOD', 'rating' => 'HIGH'],
                ['domain_code' => 'PROTECTION', 'rating' => 'MEDIUM', 'notes' => self::PROTECTION_NOTE],
            ],
            ...$overrides,
        ];
    }

    private function create(array $payload = [], ?User $as = null, ?Family $family = null)
    {
        $code = ($family ?? $this->family)->family_code;

        return $this->actingAs($as ?? $this->user)
            ->postJson("/api/v1/families/{$code}/assessments", $payload ?: $this->payload());
    }

    private function createDraft(array $payload = []): Assessment
    {
        $id = $this->create($payload)->assertCreated()->json('data.id');

        return Assessment::where('uuid', $id)->firstOrFail();
    }

    private function update(Assessment $assessment, array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->patchJson("/api/v1/assessments/{$assessment->uuid}", $payload);
    }

    private function complete(Assessment $assessment, array $payload = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson("/api/v1/assessments/{$assessment->uuid}/complete", $payload);
    }

    /** @return array<string, string> domain code → rating */
    private function storedRatings(Assessment $assessment): array
    {
        return AssessmentResult::where('assessment_id', $assessment->id)
            ->with('domain')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->domain->code => $r->rating->value])
            ->sort()
            ->all();
    }

    private function eventTypes(?Family $family = null): array
    {
        return FamilyActivity::where('family_id', ($family ?? $this->family)->id)
            ->orderBy('id')
            ->pluck('event_type')
            ->map->value
            ->all();
    }

    // ---------------------------------------------------------------- reference data

    public function test_eight_v1_domains_are_seeded_in_order(): void
    {
        $this->assertSame(
            ['SHELTER', 'FOOD', 'WASH', 'HEALTH', 'EDUCATION', 'ECONOMIC', 'PROTECTION', 'SPECIAL_NEEDS'],
            AssessmentDomain::orderBy('sort_order')->pluck('code')->all()
        );
        $this->assertSame(8, AssessmentDomain::where('is_active', true)->count());
        $this->assertSame('السكن والمأوى', AssessmentDomain::where('code', 'SHELTER')->value('name'));
    }

    public function test_domain_seeder_is_idempotent(): void
    {
        $this->seed(AssessmentDomainSeeder::class);
        $this->seed(AssessmentDomainSeeder::class);

        $this->assertSame(8, AssessmentDomain::count());
    }

    public function test_domain_seeder_does_not_reactivate_a_deactivated_domain(): void
    {
        AssessmentDomain::where('code', 'EDUCATION')->update(['is_active' => false]);

        $this->seed(AssessmentDomainSeeder::class);

        $this->assertFalse(AssessmentDomain::where('code', 'EDUCATION')->first()->is_active);
    }

    public function test_reference_endpoint_returns_active_domains_only(): void
    {
        AssessmentDomain::where('code', 'WASH')->update(['is_active' => false]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/reference/assessment-domains')->assertOk();

        $codes = array_column($response->json('data'), 'code');
        $this->assertSame(['SHELTER', 'FOOD', 'HEALTH', 'EDUCATION', 'ECONOMIC', 'PROTECTION', 'SPECIAL_NEEDS'], $codes);
        $this->assertArrayNotHasKey('id', $response->json('data.0'));
    }

    public function test_reference_endpoint_is_available_to_assessment_roles_only(): void
    {
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER'] as $role) {
            $this->actingAs($this->user($role))->getJson('/api/v1/reference/assessment-domains')->assertOk();
        }
        foreach (['REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $this->actingAs($this->user($role))->getJson('/api/v1/reference/assessment-domains')->assertForbidden();
        }
    }

    // ---------------------------------------------------------------- create

    public function test_authorized_user_creates_a_draft(): void
    {
        $response = $this->create()->assertCreated();

        $response->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.assessment_date', '2026-09-20')
            ->assertJsonPath('data.general_notes', self::GENERAL_NOTE)
            ->assertJsonPath('data.family.family_code', $this->family->family_code)
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.completed_by', null)
            ->assertJsonPath('abilities.update', true)
            ->assertJsonPath('abilities.complete', true);

        $this->assertCount(3, $response->json('data.results'));
        // Results come back in domain order.
        $this->assertSame(['SHELTER', 'FOOD', 'PROTECTION'], array_column(array_column($response->json('data.results'), 'domain'), 'code'));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->json('data.id'));
    }

    public function test_creator_is_recorded_and_assessment_date_is_independent_of_created_at(): void
    {
        $assessment = $this->createDraft();

        $this->assertSame($this->user->id, $assessment->created_by);
        $this->assertSame('2026-09-20', $assessment->assessment_date->toDateString());
        $this->assertSame('2026-09-24', $assessment->created_at->toDateString());

        $this->actingAs($this->user)->getJson("/api/v1/assessments/{$assessment->uuid}")
            ->assertOk()
            ->assertJsonPath('data.created_by.name', 'مدخل بيانات تجريبي')
            ->assertJsonMissingPath('data.created_by.email');
    }

    public function test_assessment_date_is_required_and_cannot_be_in_the_future(): void
    {
        $this->create($this->payload(['assessment_date' => null]))->assertUnprocessable()
            ->assertJsonValidationErrors('assessment_date');
        $this->create($this->payload(['assessment_date' => '2026-09-25']))->assertUnprocessable()
            ->assertJsonValidationErrors('assessment_date');

        $this->assertSame(0, Assessment::count());
    }

    public function test_same_family_may_have_multiple_assessments_on_the_same_date(): void
    {
        $this->create()->assertCreated();
        $this->create()->assertCreated();

        $this->assertSame(2, Assessment::where('family_id', $this->family->id)->whereDate('assessment_date', '2026-09-20')->count());
    }

    public function test_draft_may_be_saved_without_any_result(): void
    {
        $assessment = $this->createDraft(['assessment_date' => '2026-09-20']);

        $this->assertSame([], $this->storedRatings($assessment));
    }

    public function test_another_family_is_isolated(): void
    {
        $other = Family::factory()->create();
        $this->create([], null, $other)->assertCreated();
        $this->createDraft();

        $list = $this->actingAs($this->user)->getJson("/api/v1/families/{$this->family->family_code}/assessments")->assertOk();
        $this->assertCount(1, $list->json('data'));

        $otherList = $this->actingAs($this->user)->getJson("/api/v1/families/{$other->family_code}/assessments")->assertOk();
        $this->assertCount(1, $otherList->json('data'));
        $this->assertNotSame($list->json('data.0.id'), $otherList->json('data.0.id'));
    }

    // ---------------------------------------------------------------- results

    public function test_all_five_ratings_are_accepted(): void
    {
        $results = [];
        foreach (['SHELTER' => 'NONE', 'FOOD' => 'LOW', 'WASH' => 'MEDIUM', 'HEALTH' => 'HIGH', 'EDUCATION' => 'CRITICAL'] as $code => $rating) {
            $results[] = ['domain_code' => $code, 'rating' => $rating];
        }

        $assessment = $this->createDraft($this->payload(['results' => $results]));

        $this->assertSame(
            ['EDUCATION' => 'CRITICAL', 'FOOD' => 'LOW', 'HEALTH' => 'HIGH', 'SHELTER' => 'NONE', 'WASH' => 'MEDIUM'],
            collect($this->storedRatings($assessment))->sortKeys()->all()
        );
    }

    public function test_invalid_rating_and_not_assessed_value_are_rejected(): void
    {
        foreach (['NOT_ASSESSED', 'SEVERE', ''] as $rating) {
            $this->create($this->payload(['results' => [['domain_code' => 'FOOD', 'rating' => $rating]]]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('results.0.rating');
        }

        $this->assertSame(0, Assessment::count());
    }

    public function test_duplicate_domain_in_payload_is_rejected(): void
    {
        $this->create($this->payload(['results' => [
            ['domain_code' => 'FOOD', 'rating' => 'HIGH'],
            ['domain_code' => 'FOOD', 'rating' => 'LOW'],
        ]]))->assertUnprocessable()->assertJsonValidationErrors('results.1.domain_code');

        $this->assertSame(0, Assessment::count());
    }

    public function test_unknown_domain_is_rejected(): void
    {
        $this->create($this->payload(['results' => [['domain_code' => 'UNKNOWN', 'rating' => 'HIGH']]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('results.0.domain_code');
    }

    public function test_unassessed_domains_are_stored_as_absence(): void
    {
        $assessment = $this->createDraft();

        // Only the three submitted domains have rows; the other five are
        // simply absent — there is no NOT_ASSESSED value anywhere.
        $this->assertSame(3, AssessmentResult::where('assessment_id', $assessment->id)->count());
        $this->assertFalse(AssessmentResult::where('rating', 'NOT_ASSESSED')->exists());
        $this->assertSame(
            ['FOOD' => 'HIGH', 'PROTECTION' => 'MEDIUM', 'SHELTER' => 'CRITICAL'],
            collect($this->storedRatings($assessment))->sortKeys()->all()
        );
    }

    public function test_inactive_domain_cannot_be_added_to_a_new_or_existing_draft(): void
    {
        AssessmentDomain::where('code', 'EDUCATION')->update(['is_active' => false]);

        $this->create($this->payload(['results' => [['domain_code' => 'EDUCATION', 'rating' => 'HIGH']]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('results.0.domain_code');
        $this->assertSame(0, Assessment::count());

        $assessment = $this->createDraft();
        $this->update($assessment, ['results' => [
            ['domain_code' => 'SHELTER', 'rating' => 'CRITICAL'],
            ['domain_code' => 'EDUCATION', 'rating' => 'LOW'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('results.1.domain_code');

        // Transaction rolled back: the draft is unchanged.
        $this->assertSame(
            ['FOOD' => 'HIGH', 'PROTECTION' => 'MEDIUM', 'SHELTER' => 'CRITICAL'],
            collect($this->storedRatings($assessment))->sortKeys()->all()
        );
    }

    public function test_draft_results_can_be_added_changed_and_removed(): void
    {
        $assessment = $this->createDraft();

        // Add HEALTH, change SHELTER, remove PROTECTION, keep FOOD.
        $response = $this->update($assessment, ['results' => [
            ['domain_code' => 'SHELTER', 'rating' => 'HIGH', 'notes' => 'ملاحظة معدلة'],
            ['domain_code' => 'FOOD', 'rating' => 'HIGH'],
            ['domain_code' => 'HEALTH', 'rating' => 'MEDIUM'],
        ]])->assertOk();

        $this->assertSame(
            ['FOOD' => 'HIGH', 'HEALTH' => 'MEDIUM', 'SHELTER' => 'HIGH'],
            collect($this->storedRatings($assessment))->sortKeys()->all()
        );
        $this->assertSame('ملاحظة معدلة', $response->json('data.results.0.notes'));
        // PROTECTION is now "not assessed": no row at all.
        $this->assertFalse(AssessmentResult::where('assessment_id', $assessment->id)
            ->whereHas('domain', fn ($q) => $q->where('code', 'PROTECTION'))->exists());
    }

    public function test_draft_date_and_notes_can_change_and_omitted_results_are_untouched(): void
    {
        $assessment = $this->createDraft();

        $this->update($assessment, ['assessment_date' => '2026-09-21', 'general_notes' => null])
            ->assertOk()
            ->assertJsonPath('data.assessment_date', '2026-09-21')
            ->assertJsonPath('data.general_notes', null);

        $this->assertCount(3, $this->storedRatings($assessment));
        $this->assertSame($this->user->id, $assessment->fresh()->updated_by);
    }

    public function test_draft_keeps_a_result_whose_domain_was_deactivated_later(): void
    {
        $assessment = $this->createDraft();
        AssessmentDomain::where('code', 'FOOD')->update(['is_active' => false]);

        // Re-saving the draft with the existing inactive result is allowed.
        $this->update($assessment, ['results' => [
            ['domain_code' => 'SHELTER', 'rating' => 'CRITICAL'],
            ['domain_code' => 'FOOD', 'rating' => 'HIGH'],
        ]])->assertOk()->assertJsonPath('data.results.1.domain.is_active', false);

        $this->assertArrayHasKey('FOOD', $this->storedRatings($assessment));
    }

    // ---------------------------------------------------------------- complete

    public function test_draft_with_zero_results_cannot_complete(): void
    {
        $assessment = $this->createDraft(['assessment_date' => '2026-09-20']);

        $this->complete($assessment)->assertUnprocessable()->assertJsonValidationErrors('results');

        $this->assertSame(AssessmentStatus::DRAFT, $assessment->fresh()->status);
    }

    public function test_draft_with_one_result_can_complete_and_records_completion(): void
    {
        $assessment = $this->createDraft($this->payload(['results' => [['domain_code' => 'FOOD', 'rating' => 'LOW']]]));
        $completer = $this->user('SOCIAL_WORKER', 'باحث اجتماعي تجريبي');

        Carbon::setTestNow('2026-09-24 12:30:00');
        $this->complete($assessment, [], $completer)
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.completed_by.name', 'باحث اجتماعي تجريبي')
            ->assertJsonPath('abilities.update', false)
            ->assertJsonPath('abilities.complete', false);

        $assessment->refresh();
        $this->assertSame(AssessmentStatus::COMPLETED, $assessment->status);
        $this->assertSame($completer->id, $assessment->completed_by);
        $this->assertSame('2026-09-24 12:30:00', $assessment->completed_at->toDateTimeString());
        // Creator is unchanged by completion.
        $this->assertSame($this->user->id, $assessment->created_by);
    }

    public function test_complete_with_payload_saves_and_completes_atomically(): void
    {
        $assessment = $this->createDraft();

        $this->complete($assessment, ['results' => [['domain_code' => 'HEALTH', 'rating' => 'HIGH']]])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertSame(['HEALTH' => 'HIGH'], $this->storedRatings($assessment));
    }

    public function test_complete_with_invalid_payload_changes_nothing(): void
    {
        $assessment = $this->createDraft();

        // Valid shape, but results emptied: nothing may be saved, and the
        // assessment stays a draft with its previous results.
        $this->complete($assessment, ['results' => []])->assertUnprocessable();

        $assessment->refresh();
        $this->assertSame(AssessmentStatus::DRAFT, $assessment->status);
        $this->assertCount(3, $this->storedRatings($assessment));
        $this->assertSame(['ASSESSMENT_CREATED'], $this->eventTypes());
    }

    public function test_completed_assessment_cannot_be_edited(): void
    {
        $assessment = $this->createDraft();
        $this->complete($assessment)->assertOk();

        $this->update($assessment, ['assessment_date' => '2026-09-01'])->assertStatus(409);
        $this->update($assessment, ['general_notes' => 'تعديل'])->assertStatus(409);

        $this->assertSame('2026-09-20', $assessment->fresh()->assessment_date->toDateString());
        $this->assertSame(self::GENERAL_NOTE, $assessment->fresh()->general_notes);
    }

    public function test_completed_results_cannot_be_added_changed_or_removed(): void
    {
        $assessment = $this->createDraft();
        $this->complete($assessment)->assertOk();
        $before = $this->storedRatings($assessment);

        $this->update($assessment, ['results' => [['domain_code' => 'WASH', 'rating' => 'LOW']]])->assertStatus(409);
        $this->update($assessment, ['results' => [['domain_code' => 'SHELTER', 'rating' => 'NONE']]])->assertStatus(409);
        $this->update($assessment, ['results' => []])->assertStatus(409);
        $this->complete($assessment, ['results' => [['domain_code' => 'SHELTER', 'rating' => 'NONE']]])->assertStatus(409);

        $this->assertSame($before, $this->storedRatings($assessment));

        // Model-level backstop, independent of the API.
        $result = AssessmentResult::where('assessment_id', $assessment->id)->first();
        $this->expectException(LogicException::class);
        $result->update(['rating' => 'NONE']);
    }

    public function test_completed_result_cannot_be_deleted_at_model_level(): void
    {
        $assessment = $this->createDraft();
        $this->complete($assessment)->assertOk();

        $this->expectException(LogicException::class);
        AssessmentResult::where('assessment_id', $assessment->id)->first()->delete();
    }

    public function test_completed_assessment_cannot_reopen_or_complete_twice(): void
    {
        $assessment = $this->createDraft();
        $this->complete($assessment)->assertOk();
        $completedAt = $assessment->fresh()->completed_at;

        Carbon::setTestNow('2026-09-25 09:00:00');
        $this->complete($assessment)->assertStatus(409);
        $this->update($assessment, ['status' => 'DRAFT'])->assertStatus(409);

        $fresh = $assessment->fresh();
        $this->assertSame(AssessmentStatus::COMPLETED, $fresh->status);
        $this->assertTrue($completedAt->equalTo($fresh->completed_at));

        $this->expectException(LogicException::class);
        $fresh->update(['status' => AssessmentStatus::DRAFT]);
    }

    public function test_status_is_never_accepted_from_the_payload(): void
    {
        $assessment = $this->createDraft($this->payload(['status' => 'COMPLETED']));

        $this->assertSame(AssessmentStatus::DRAFT, $assessment->status);
        $this->update($assessment, ['status' => 'COMPLETED'])->assertOk()->assertJsonPath('data.status', 'DRAFT');
    }

    public function test_draft_with_inactive_domain_result_cannot_complete_until_removed(): void
    {
        $assessment = $this->createDraft();
        AssessmentDomain::where('code', 'PROTECTION')->update(['is_active' => false]);

        $this->complete($assessment)->assertUnprocessable()->assertJsonValidationErrors('results');
        $this->assertSame(AssessmentStatus::DRAFT, $assessment->fresh()->status);
        // The result was not silently deleted.
        $this->assertArrayHasKey('PROTECTION', $this->storedRatings($assessment));

        // Remove it from the draft → completion succeeds.
        $this->update($assessment, ['results' => [
            ['domain_code' => 'SHELTER', 'rating' => 'CRITICAL'],
            ['domain_code' => 'FOOD', 'rating' => 'HIGH'],
        ]])->assertOk();
        $this->complete($assessment)->assertOk();
    }

    public function test_draft_with_inactive_domain_result_completes_after_reactivation(): void
    {
        $assessment = $this->createDraft();
        AssessmentDomain::where('code', 'PROTECTION')->update(['is_active' => false]);
        $this->complete($assessment)->assertUnprocessable();

        AssessmentDomain::where('code', 'PROTECTION')->update(['is_active' => true]);
        $this->complete($assessment)->assertOk();
    }

    // ---------------------------------------------------------------- permissions

    public function test_full_access_roles_can_view_create_update_and_complete(): void
    {
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'SOCIAL_WORKER'] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)->getJson("/api/v1/families/{$this->family->family_code}/assessments")
                ->assertOk()->assertJsonPath('abilities.create', true);
            $id = $this->create([], $user)->assertCreated()->json('data.id');
            $assessment = Assessment::where('uuid', $id)->first();
            $this->actingAs($user)->getJson("/api/v1/assessments/{$id}")->assertOk();
            $this->update($assessment, ['general_notes' => 'تعديل'], $user)->assertOk();
            $this->complete($assessment, [], $user)->assertOk();
        }
    }

    public function test_reviewer_can_only_view(): void
    {
        $reviewer = $this->user('REVIEWER');
        $assessment = $this->createDraft();

        $this->actingAs($reviewer)->getJson("/api/v1/families/{$this->family->family_code}/assessments")
            ->assertOk()->assertJsonPath('abilities.create', false);
        $this->actingAs($reviewer)->getJson("/api/v1/assessments/{$assessment->uuid}")
            ->assertOk()
            ->assertJsonPath('abilities.update', false)
            ->assertJsonPath('abilities.complete', false);

        $this->create([], $reviewer)->assertForbidden();
        $this->update($assessment, ['general_notes' => 'x'], $reviewer)->assertForbidden();
        $this->complete($assessment, [], $reviewer)->assertForbidden();

        $this->assertSame(1, Assessment::count());
        $this->assertSame(AssessmentStatus::DRAFT, $assessment->fresh()->status);
    }

    public function test_reports_viewer_and_family_user_are_denied_everything(): void
    {
        $assessment = $this->createDraft();

        foreach (['REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)->getJson("/api/v1/families/{$this->family->family_code}/assessments")->assertForbidden();
            $this->actingAs($user)->getJson("/api/v1/assessments/{$assessment->uuid}")->assertForbidden();
            $this->create([], $user)->assertForbidden();
            $this->update($assessment, ['general_notes' => 'x'], $user)->assertForbidden();
            $this->complete($assessment, [], $user)->assertForbidden();
        }

        $this->assertSame(1, Assessment::count());
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $assessment = $this->createDraft();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/families/{$this->family->family_code}/assessments")->assertUnauthorized();
        $this->getJson("/api/v1/assessments/{$assessment->uuid}")->assertUnauthorized();
        $this->getJson('/api/v1/reference/assessment-domains')->assertUnauthorized();
    }

    public function test_complete_with_draft_changes_also_requires_update_permission(): void
    {
        $assessment = $this->createDraft();
        // A user holding only complete + view, not update.
        $completer = User::factory()->create();
        $completer->givePermissionTo(['assessment.view', 'assessment.complete']);

        $this->complete($assessment, ['general_notes' => 'تعديل'], $completer)->assertForbidden();
        $this->complete($assessment, [], $completer)->assertOk();
    }

    public function test_family_update_is_not_a_substitute_for_assessment_permissions(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['family.view', 'family.update']);

        $this->create([], $user)->assertForbidden();
        $this->actingAs($user)->getJson("/api/v1/families/{$this->family->family_code}/assessments")->assertForbidden();
    }

    // ---------------------------------------------------------------- activity

    public function test_create_update_and_complete_write_one_activity_each(): void
    {
        $assessment = $this->createDraft();
        $this->update($assessment, ['results' => [['domain_code' => 'FOOD', 'rating' => 'LOW']]])->assertOk();
        $this->complete($assessment)->assertOk();

        $this->assertSame(['ASSESSMENT_CREATED', 'ASSESSMENT_UPDATED', 'ASSESSMENT_COMPLETED'], $this->eventTypes());

        $activities = FamilyActivity::where('family_id', $this->family->id)->get();
        foreach ($activities as $activity) {
            $this->assertSame('assessment', $activity->subject_type);
            $this->assertSame($assessment->id, $activity->subject_id);
            $this->assertSame($this->user->id, $activity->actor_user_id);
        }
    }

    public function test_complete_with_changes_records_update_then_completion(): void
    {
        $assessment = $this->createDraft();
        $this->complete($assessment, ['general_notes' => 'ملاحظة نهائية'])->assertOk();

        $this->assertSame(['ASSESSMENT_CREATED', 'ASSESSMENT_UPDATED', 'ASSESSMENT_COMPLETED'], $this->eventTypes());
    }

    public function test_unchanged_save_records_no_activity(): void
    {
        $assessment = $this->createDraft();

        $this->update($assessment, $this->payload())->assertOk();

        $this->assertSame(['ASSESSMENT_CREATED'], $this->eventTypes());
    }

    public function test_failed_operations_leave_no_activity(): void
    {
        $this->create($this->payload(['results' => [['domain_code' => 'FOOD', 'rating' => 'BAD']]]))->assertUnprocessable();
        $this->assertSame([], $this->eventTypes());

        $assessment = $this->createDraft(['assessment_date' => '2026-09-20']);
        $this->complete($assessment)->assertUnprocessable();
        $this->create([], $this->user('REVIEWER'))->assertForbidden();

        AssessmentDomain::where('code', 'EDUCATION')->update(['is_active' => false]);
        $this->update($assessment, ['general_notes' => 'x', 'results' => [['domain_code' => 'EDUCATION', 'rating' => 'LOW']]])
            ->assertUnprocessable();
        // The notes change was rolled back together with the refused result.
        $this->assertNull($assessment->fresh()->general_notes);

        $this->assertSame(['ASSESSMENT_CREATED'], $this->eventTypes());
    }

    public function test_activity_metadata_and_timeline_contain_no_notes_or_ratings(): void
    {
        $assessment = $this->createDraft();
        $this->update($assessment, ['general_notes' => self::GENERAL_NOTE.' 2'])->assertOk();
        $this->complete($assessment)->assertOk();

        foreach (FamilyActivity::where('family_id', $this->family->id)->get() as $activity) {
            $this->assertNull($activity->metadata);
        }

        $timeline = $this->actingAs($this->user)
            ->getJson("/api/v1/families/{$this->family->family_code}/activities")
            ->assertOk();
        $this->assertSame(
            ['ASSESSMENT_COMPLETED', 'ASSESSMENT_UPDATED', 'ASSESSMENT_CREATED'],
            array_column($timeline->json('data'), 'event_type')
        );
        $this->assertSame('assessment', $timeline->json('data.0.subject.type'));

        $raw = $timeline->getContent();
        foreach ([self::GENERAL_NOTE, self::SHELTER_NOTE, self::PROTECTION_NOTE, 'CRITICAL', 'HIGH', 'MEDIUM'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    public function test_assessment_events_are_hidden_without_assessment_view(): void
    {
        $this->createDraft();
        $user = User::factory()->create();
        $user->givePermissionTo('activity-log.view');

        $timeline = $this->actingAs($user)
            ->getJson("/api/v1/families/{$this->family->family_code}/activities")
            ->assertOk();

        $this->assertSame([], $timeline->json('data'));
    }

    // ---------------------------------------------------------------- privacy

    public function test_assessment_responses_contain_no_national_id_or_health_details(): void
    {
        PersonHealthRecord::factory()->create([
            'person_id' => $this->head->id,
            'type' => 'CHRONIC_DISEASE',
            'condition_name' => 'مرض اختباري',
            'details' => self::HEALTH_DETAILS,
        ]);
        $assessment = $this->createDraft($this->payload(['results' => [['domain_code' => 'HEALTH', 'rating' => 'HIGH']]]));

        $responses = [
            $this->actingAs($this->user)->getJson("/api/v1/assessments/{$assessment->uuid}")->assertOk(),
            $this->actingAs($this->user)->getJson("/api/v1/families/{$this->family->family_code}/assessments")->assertOk(),
        ];

        foreach ($responses as $response) {
            $raw = $response->getContent();
            $this->assertStringNotContainsString(self::NATIONAL_ID, $raw);
            $this->assertStringNotContainsString('national_id', $raw);
            $this->assertStringNotContainsString(self::HEALTH_DETAILS, $raw);
            $this->assertStringNotContainsString('مرض اختباري', $raw);
            $this->assertStringNotContainsString('"id":'.$assessment->id.',', $raw);
            $this->assertStringNotContainsString('family_id', $raw);
            $this->assertStringNotContainsString('email', $raw);
        }
    }

    public function test_assessment_list_contains_ratings_but_no_notes(): void
    {
        $this->createDraft();

        $list = $this->actingAs($this->user)->getJson("/api/v1/families/{$this->family->family_code}/assessments")->assertOk();

        $list->assertJsonPath('data.0.assessed_domain_count', 3)
            ->assertJsonPath('data.0.ratings.0.domain.code', 'SHELTER')
            ->assertJsonPath('data.0.ratings.0.rating', 'CRITICAL');
        $raw = $list->getContent();
        foreach ([self::GENERAL_NOTE, self::SHELTER_NOTE, self::PROTECTION_NOTE] as $note) {
            $this->assertStringNotContainsString($note, $raw);
        }
    }

    public function test_assessment_content_is_absent_from_generic_family_and_person_responses(): void
    {
        $assessment = $this->createDraft();
        $this->complete($assessment)->assertOk();
        $admin = $this->user('SUPER_ADMIN');

        $responses = [
            $this->actingAs($admin)->getJson('/api/v1/families')->assertOk(),
            $this->actingAs($admin)->getJson("/api/v1/families/{$this->family->family_code}")->assertOk(),
            $this->actingAs($admin)->getJson("/api/v1/people/{$this->head->person_code}")->assertOk(),
        ];

        foreach ($responses as $response) {
            $raw = $response->getContent();
            $this->assertStringNotContainsString('assessment', $raw);
            $this->assertStringNotContainsString($assessment->uuid, $raw);
            foreach ([self::GENERAL_NOTE, self::SHELTER_NOTE, self::PROTECTION_NOTE, 'CRITICAL'] as $secret) {
                $this->assertStringNotContainsString($secret, $raw);
            }
        }
    }

    // ---------------------------------------------------------------- ordering

    public function test_family_list_orders_by_assessment_date_then_newest_entry(): void
    {
        $older = $this->createDraft($this->payload(['assessment_date' => '2026-09-10']));
        Carbon::setTestNow('2026-09-24 11:00:00');
        $firstSameDay = $this->createDraft($this->payload(['assessment_date' => '2026-09-20']));
        Carbon::setTestNow('2026-09-24 12:00:00');
        $secondSameDay = $this->createDraft($this->payload(['assessment_date' => '2026-09-20']));
        // Entered last, but assessed earliest.
        Carbon::setTestNow('2026-09-24 13:00:00');
        $earliest = $this->createDraft($this->payload(['assessment_date' => '2026-08-01']));

        $ids = array_column(
            $this->actingAs($this->user)->getJson("/api/v1/families/{$this->family->family_code}/assessments")->assertOk()->json('data'),
            'id'
        );

        $this->assertSame([$secondSameDay->uuid, $firstSameDay->uuid, $older->uuid, $earliest->uuid], $ids);
    }

    public function test_family_list_is_paginated(): void
    {
        foreach (range(1, 3) as $i) {
            $this->createDraft();
        }

        $this->actingAs($this->user)
            ->getJson("/api/v1/families/{$this->family->family_code}/assessments?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    // ---------------------------------------------------------------- immutability

    public function test_completed_snapshot_remains_readable_after_domain_deactivation(): void
    {
        $assessment = $this->createDraft();
        $this->complete($assessment)->assertOk();

        AssessmentDomain::where('code', 'SHELTER')->update(['is_active' => false, 'name' => 'السكن والمأوى']);

        $response = $this->actingAs($this->user('REVIEWER'))->getJson("/api/v1/assessments/{$assessment->uuid}")->assertOk();

        $response->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.results.0.domain.code', 'SHELTER')
            ->assertJsonPath('data.results.0.domain.is_active', false)
            ->assertJsonPath('data.results.0.rating', 'CRITICAL')
            ->assertJsonPath('data.results.0.notes', self::SHELTER_NOTE);
        $this->assertCount(3, $response->json('data.results'));
    }

    public function test_no_delete_endpoint_exists_and_model_refuses_delete(): void
    {
        $assessment = $this->createDraft();

        $admin = $this->user('SUPER_ADMIN');
        $this->actingAs($admin)->deleteJson("/api/v1/assessments/{$assessment->uuid}")->assertStatus(405);

        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'assessment')) {
                $this->assertNotContains('DELETE', $route->methods(), $route->uri());
            }
        }

        $this->expectException(LogicException::class);
        $assessment->delete();
    }

    public function test_unknown_assessment_returns_404(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/assessments/00000000-0000-0000-0000-000000000000')->assertNotFound();
    }
}
