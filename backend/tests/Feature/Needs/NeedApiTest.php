<?php

namespace Tests\Feature\Needs;

use App\Enums\AssessmentStatus;
use App\Enums\NeedStatus;
use App\Models\Assessment;
use App\Models\AssessmentDomain;
use App\Models\AssessmentResult;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\FamilyNeed;
use App\Models\NeedCategory;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use App\Models\User;
use Database\Seeders\AssessmentDomainSeeder;
use Database\Seeders\NeedCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

/**
 * Needs Management V1 (docs/03-BUSINESS-RULES.md §46a, docs/06 §49). All
 * identity, phone, health and note values are synthetic.
 */
class NeedApiTest extends TestCase
{
    use RefreshDatabase;

    private const NATIONAL_ID = '9990005551';

    private const MOBILE = '0799995551';

    private const HEALTH_DETAILS = 'تفاصيل صحية اختبارية سرية';

    private const ASSESSMENT_NOTE = 'ملاحظة تقييم اختبارية سرية';

    private const DESCRIPTION = 'وصف احتياج اختباري سري';

    private const REASON = 'سبب إغلاق اختباري سري';

    private Family $family;

    private Person $head;

    private Person $member;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-24 10:00:00');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(NeedCategorySeeder::class);
        $this->seed(AssessmentDomainSeeder::class);

        $this->family = Family::factory()->create();
        $this->head = Person::factory()->create([
            'full_name' => 'رب أسرة تجريبي',
            'national_id' => self::NATIONAL_ID,
            'mobile' => self::MOBILE,
        ]);
        FamilyMembership::factory()->householdHead()->create([
            'family_id' => $this->family->id,
            'person_id' => $this->head->id,
        ]);
        $this->member = $this->memberOf($this->family, ['full_name' => 'فرد تجريبي']);

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

    private function memberOf(Family $family, array $attributes = [], bool $active = true): Person
    {
        $person = Person::factory()->create($attributes);
        FamilyMembership::factory()->create([
            'family_id' => $family->id,
            'person_id' => $person->id,
            'is_active' => $active,
            'ended_at' => $active ? null : '2026-09-01',
        ]);

        return $person;
    }

    private function completedAssessment(?Family $family = null, AssessmentStatus $status = AssessmentStatus::COMPLETED): Assessment
    {
        $assessment = Assessment::create([
            'family_id' => ($family ?? $this->family)->id,
            'assessment_date' => '2026-09-20',
            'status' => AssessmentStatus::DRAFT,
            'general_notes' => self::ASSESSMENT_NOTE,
        ]);
        AssessmentResult::create([
            'assessment_id' => $assessment->id,
            'assessment_domain_id' => AssessmentDomain::where('code', 'HEALTH')->value('id'),
            'rating' => 'CRITICAL',
            'notes' => self::ASSESSMENT_NOTE,
        ]);
        if ($status === AssessmentStatus::COMPLETED) {
            $assessment->update(['status' => AssessmentStatus::COMPLETED, 'completed_at' => now()]);
        }

        return $assessment;
    }

    private function payload(array $overrides = []): array
    {
        return [
            'category_code' => 'SHELTER',
            'title' => 'مواد إيواء',
            'description' => self::DESCRIPTION,
            'priority' => 'HIGH',
            'quantity' => 5,
            'unit' => 'فرشة',
            ...$overrides,
        ];
    }

    private function create(array $payload = [], ?User $as = null, ?Family $family = null)
    {
        $code = ($family ?? $this->family)->family_code;

        return $this->actingAs($as ?? $this->user)
            ->postJson("/api/v1/families/{$code}/needs", $payload ?: $this->payload());
    }

    private function createNeed(array $payload = []): FamilyNeed
    {
        $id = $this->create($payload ?: $this->payload())->assertCreated()->json('data.id');

        return FamilyNeed::where('uuid', $id)->firstOrFail();
    }

    private function update(FamilyNeed $need, array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->patchJson("/api/v1/needs/{$need->uuid}", $payload);
    }

    private function fulfill(FamilyNeed $need, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson("/api/v1/needs/{$need->uuid}/fulfill");
    }

    private function close(FamilyNeed $need, ?string $reason = self::REASON, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)
            ->postJson("/api/v1/needs/{$need->uuid}/close", $reason === null ? [] : ['closure_reason' => $reason]);
    }

    private function familyList(array $query = [], ?User $as = null, ?Family $family = null)
    {
        $code = ($family ?? $this->family)->family_code;
        $qs = $query ? '?'.http_build_query($query) : '';

        return $this->actingAs($as ?? $this->user)->getJson("/api/v1/families/{$code}/needs{$qs}");
    }

    private function eventTypes(): array
    {
        return FamilyActivity::where('family_id', $this->family->id)
            ->orderBy('id')
            ->pluck('event_type')
            ->map->value
            ->all();
    }

    // ---------------------------------------------------------------- reference data

    public function test_fourteen_categories_are_seeded_in_order(): void
    {
        $this->assertSame(
            ['SHELTER', 'FOOD', 'WATER', 'HYGIENE', 'HEALTHCARE', 'MEDICATION', 'ASSISTIVE_DEVICE',
                'EDUCATION', 'CASH', 'CLOTHING', 'CHILDCARE', 'PROTECTION', 'LIVELIHOOD', 'OTHER'],
            NeedCategory::orderBy('sort_order')->pluck('code')->all()
        );
        $this->assertSame(14, NeedCategory::where('is_active', true)->count());
        $this->assertSame('الأجهزة والمستلزمات المساعدة', NeedCategory::where('code', 'ASSISTIVE_DEVICE')->value('name'));
    }

    public function test_category_seeder_is_idempotent(): void
    {
        $this->seed(NeedCategorySeeder::class);
        $this->seed(NeedCategorySeeder::class);

        $this->assertSame(14, NeedCategory::count());
    }

    public function test_category_seeder_does_not_reactivate_a_deactivated_category(): void
    {
        NeedCategory::where('code', 'CLOTHING')->update(['is_active' => false]);

        $this->seed(NeedCategorySeeder::class);

        $this->assertFalse(NeedCategory::where('code', 'CLOTHING')->first()->is_active);
    }

    public function test_reference_endpoint_returns_active_categories_only(): void
    {
        NeedCategory::where('code', 'CASH')->update(['is_active' => false]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/reference/need-categories')->assertOk();

        $codes = array_column($response->json('data'), 'code');
        $this->assertCount(13, $codes);
        $this->assertNotContains('CASH', $codes);
        $this->assertArrayNotHasKey('id', $response->json('data.0'));
    }

    public function test_reference_endpoint_is_available_to_need_roles_only(): void
    {
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER'] as $role) {
            $this->actingAs($this->user($role))->getJson('/api/v1/reference/need-categories')->assertOk();
        }
        foreach (['REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $this->actingAs($this->user($role))->getJson('/api/v1/reference/need-categories')->assertForbidden();
        }
    }

    // ---------------------------------------------------------------- create

    public function test_family_level_need_is_created_open(): void
    {
        $response = $this->create()->assertCreated();

        $response->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.person', null)
            ->assertJsonPath('data.family.family_code', $this->family->family_code)
            ->assertJsonPath('data.family.household_head_name', 'رب أسرة تجريبي')
            ->assertJsonPath('data.category.code', 'SHELTER')
            ->assertJsonPath('data.title', 'مواد إيواء')
            ->assertJsonPath('data.priority', 'HIGH')
            ->assertJsonPath('data.quantity', '5')
            ->assertJsonPath('data.unit', 'فرشة')
            ->assertJsonPath('data.source_assessment', null)
            ->assertJsonPath('data.created_by.name', 'مدخل بيانات تجريبي')
            ->assertJsonPath('data.resolved_at', null)
            ->assertJsonPath('data.resolved_by', null)
            ->assertJsonPath('data.closure_reason', null)
            ->assertJsonPath('abilities.update', true)
            ->assertJsonPath('abilities.fulfill', true)
            ->assertJsonPath('abilities.close', true);

        $need = FamilyNeed::first();
        $this->assertSame(NeedStatus::OPEN, $need->status);
        $this->assertNull($need->person_id);
        $this->assertSame($this->user->id, $need->created_by);
    }

    public function test_person_level_need_targets_an_active_member(): void
    {
        $this->create($this->payload(['person_code' => $this->member->person_code, 'category_code' => 'ASSISTIVE_DEVICE']))
            ->assertCreated()
            ->assertJsonPath('data.person.person_code', $this->member->person_code)
            ->assertJsonPath('data.person.full_name', 'فرد تجريبي');

        $this->assertSame($this->member->id, FamilyNeed::first()->person_id);
    }

    public function test_unrelated_or_former_member_is_rejected(): void
    {
        $stranger = $this->memberOf(Family::factory()->create());
        $former = $this->memberOf($this->family, [], active: false);

        foreach ([$stranger->person_code, $former->person_code, 'PER-UNKNOWN'] as $code) {
            $this->create($this->payload(['person_code' => $code]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('person_code');
        }

        $this->assertSame(0, FamilyNeed::count());
    }

    public function test_need_without_assessment_and_default_priority(): void
    {
        $this->create(['category_code' => 'FOOD', 'title' => 'طرد غذائي'])
            ->assertCreated()
            ->assertJsonPath('data.source_assessment', null)
            ->assertJsonPath('data.priority', 'MEDIUM')
            ->assertJsonPath('data.quantity', null)
            ->assertJsonPath('data.unit', null);
    }

    public function test_need_with_same_family_completed_assessment(): void
    {
        $assessment = $this->completedAssessment();

        $this->create($this->payload(['source_assessment_id' => $assessment->uuid]))
            ->assertCreated()
            ->assertJsonPath('data.source_assessment.id', $assessment->uuid)
            ->assertJsonPath('data.source_assessment.assessment_date', '2026-09-20')
            ->assertJsonPath('data.source_assessment.status', 'COMPLETED');

        // Creating a need never touches the assessment.
        $this->assertSame(AssessmentStatus::COMPLETED, $assessment->fresh()->status);
    }

    public function test_other_family_or_draft_assessment_is_rejected(): void
    {
        $other = $this->completedAssessment(Family::factory()->create());
        $draft = $this->completedAssessment(null, AssessmentStatus::DRAFT);

        foreach ([$other->uuid, $draft->uuid, '00000000-0000-0000-0000-000000000000'] as $uuid) {
            $this->create($this->payload(['source_assessment_id' => $uuid]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('source_assessment_id');
        }

        $this->assertSame(0, FamilyNeed::count());
    }

    public function test_client_cannot_create_a_resolved_need(): void
    {
        foreach ([
            ['status' => 'FULFILLED'],
            ['status' => 'CLOSED', 'closure_reason' => 'x'],
            ['resolved_at' => '2026-09-01 10:00:00'],
            ['resolved_by' => $this->user->id],
        ] as $extra) {
            $this->create($this->payload($extra))->assertUnprocessable();
        }

        $this->assertSame(0, FamilyNeed::count());
    }

    public function test_assessments_never_generate_needs(): void
    {
        $this->completedAssessment();

        $this->assertSame(0, FamilyNeed::count());
    }

    // ---------------------------------------------------------------- fields

    public function test_all_four_priorities_are_accepted(): void
    {
        foreach (['LOW', 'MEDIUM', 'HIGH', 'URGENT'] as $priority) {
            $this->create($this->payload(['priority' => $priority]))->assertCreated()->assertJsonPath('data.priority', $priority);
        }
    }

    public function test_invalid_priority_is_rejected(): void
    {
        foreach (['CRITICAL', 'urgent', ''] as $priority) {
            $this->create($this->payload(['priority' => $priority]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('priority');
        }
    }

    public function test_quantity_and_unit_are_optional_and_decimal(): void
    {
        $this->create($this->payload(['quantity' => null, 'unit' => null]))->assertCreated()
            ->assertJsonPath('data.quantity', null);
        $this->create($this->payload(['quantity' => 2.5, 'unit' => 'لتر']))->assertCreated()
            ->assertJsonPath('data.quantity', '2.5');
        $this->create($this->payload(['quantity' => 1000, 'unit' => null]))->assertCreated()
            ->assertJsonPath('data.quantity', '1000')
            ->assertJsonPath('data.unit', null);
    }

    public function test_non_positive_or_invalid_quantity_is_rejected(): void
    {
        foreach ([0, -1, 'abc', 1.234] as $quantity) {
            $this->create($this->payload(['quantity' => $quantity]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('quantity');
        }
    }

    public function test_unit_without_quantity_is_rejected(): void
    {
        $this->create($this->payload(['quantity' => null, 'unit' => 'قطعة']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit');
    }

    public function test_title_and_category_are_required(): void
    {
        $this->create(['category_code' => 'FOOD'])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->create(['title' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('category_code');
        $this->create($this->payload(['title' => str_repeat('أ', 151)]))->assertUnprocessable()->assertJsonValidationErrors('title');
    }

    public function test_inactive_category_is_rejected_for_new_needs(): void
    {
        NeedCategory::where('code', 'CASH')->update(['is_active' => false]);

        $this->create($this->payload(['category_code' => 'CASH']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_code');

        $need = $this->createNeed();
        $this->update($need, ['category_code' => 'CASH'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_code');
    }

    public function test_open_need_keeps_a_category_deactivated_later(): void
    {
        $need = $this->createNeed();
        NeedCategory::where('code', 'SHELTER')->update(['is_active' => false]);

        $this->update($need, ['category_code' => 'SHELTER', 'title' => 'مواد إيواء محدثة'])
            ->assertOk()
            ->assertJsonPath('data.category.is_active', false);
    }

    // ---------------------------------------------------------------- update

    public function test_open_need_is_editable(): void
    {
        $assessment = $this->completedAssessment();
        $need = $this->createNeed();

        $this->update($need, [
            'person_code' => $this->member->person_code,
            'source_assessment_id' => $assessment->uuid,
            'category_code' => 'HEALTHCARE',
            'title' => 'فحص طبي',
            'description' => null,
            'priority' => 'URGENT',
            'quantity' => 1,
            'unit' => 'زيارة',
        ])->assertOk()
            ->assertJsonPath('data.person.person_code', $this->member->person_code)
            ->assertJsonPath('data.source_assessment.id', $assessment->uuid)
            ->assertJsonPath('data.category.code', 'HEALTHCARE')
            ->assertJsonPath('data.title', 'فحص طبي')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.priority', 'URGENT')
            ->assertJsonPath('data.quantity', '1');

        // Back to a family-level need without a source.
        $this->update($need, ['person_code' => null, 'source_assessment_id' => null, 'quantity' => null, 'unit' => null])
            ->assertOk()
            ->assertJsonPath('data.person', null)
            ->assertJsonPath('data.source_assessment', null)
            ->assertJsonPath('data.quantity', null);

        $this->assertSame($this->user->id, $need->fresh()->updated_by);
    }

    public function test_open_need_keeps_a_target_who_left_the_family(): void
    {
        $need = $this->createNeed($this->payload(['person_code' => $this->member->person_code]));
        FamilyMembership::where('person_id', $this->member->id)->update(['is_active' => false]);

        $this->update($need, ['person_code' => $this->member->person_code, 'priority' => 'LOW'])->assertOk();
        $this->assertSame($this->member->id, $need->fresh()->person_id);
    }

    public function test_no_op_update_creates_no_activity(): void
    {
        $need = $this->createNeed();

        $this->update($need, $this->payload())->assertOk();
        $this->update($need, ['quantity' => '5.00'])->assertOk();

        $this->assertSame(['NEED_CREATED'], $this->eventTypes());
    }

    public function test_resolved_need_cannot_be_edited(): void
    {
        $fulfilled = $this->createNeed();
        $closed = $this->createNeed();
        $this->fulfill($fulfilled)->assertOk();
        $this->close($closed)->assertOk();

        foreach ([$fulfilled, $closed] as $need) {
            $this->update($need, ['title' => 'تعديل'])->assertStatus(409);
            $this->assertSame('مواد إيواء', $need->fresh()->title);
        }

        $this->expectException(LogicException::class);
        $fulfilled->fresh()->update(['title' => 'تعديل مباشر']);
    }

    // ---------------------------------------------------------------- fulfill

    public function test_open_need_can_be_fulfilled_with_resolver_and_timestamp(): void
    {
        $need = $this->createNeed();
        $resolver = $this->user('SOCIAL_WORKER', 'باحث اجتماعي تجريبي');

        Carbon::setTestNow('2026-09-24 12:30:00');
        $this->fulfill($need, $resolver)
            ->assertOk()
            ->assertJsonPath('data.status', 'FULFILLED')
            ->assertJsonPath('data.resolved_by.name', 'باحث اجتماعي تجريبي')
            ->assertJsonPath('data.closure_reason', null)
            ->assertJsonPath('abilities.update', false)
            ->assertJsonPath('abilities.fulfill', false)
            ->assertJsonPath('abilities.close', false);

        $need->refresh();
        $this->assertSame(NeedStatus::FULFILLED, $need->status);
        $this->assertSame($resolver->id, $need->resolved_by);
        $this->assertSame('2026-09-24 12:30:00', $need->resolved_at->toDateTimeString());
        $this->assertNull($need->closure_reason);
    }

    public function test_fulfill_ignores_client_resolution_fields(): void
    {
        $need = $this->createNeed();
        $other = $this->user('ADMINISTRATOR');

        $this->actingAs($this->user)->postJson("/api/v1/needs/{$need->uuid}/fulfill", [
            'resolved_by' => $other->id,
            'resolved_at' => '2020-01-01 00:00:00',
            'closure_reason' => 'x',
        ])->assertOk();

        $need->refresh();
        $this->assertSame($this->user->id, $need->resolved_by);
        $this->assertSame('2026-09-24', $need->resolved_at->toDateString());
        $this->assertNull($need->closure_reason);
    }

    public function test_cannot_fulfill_twice_or_close_after_fulfilment(): void
    {
        $need = $this->createNeed();
        $this->fulfill($need)->assertOk();
        $resolvedAt = $need->fresh()->resolved_at;

        Carbon::setTestNow('2026-09-25 09:00:00');
        $this->fulfill($need)->assertStatus(409);
        $this->close($need)->assertStatus(409);

        $fresh = $need->fresh();
        $this->assertSame(NeedStatus::FULFILLED, $fresh->status);
        $this->assertTrue($resolvedAt->equalTo($fresh->resolved_at));
        $this->assertSame(['NEED_CREATED', 'NEED_FULFILLED'], $this->eventTypes());
    }

    public function test_fulfilled_need_cannot_reopen(): void
    {
        $need = $this->createNeed();
        $this->fulfill($need)->assertOk();

        $this->update($need, ['status' => 'OPEN'])->assertUnprocessable();
        $this->assertSame(NeedStatus::FULFILLED, $need->fresh()->status);

        $this->expectException(LogicException::class);
        $need->fresh()->update(['status' => NeedStatus::OPEN]);
    }

    // ---------------------------------------------------------------- close

    public function test_open_need_can_be_closed_with_reason_resolver_and_timestamp(): void
    {
        $need = $this->createNeed();

        Carbon::setTestNow('2026-09-24 13:00:00');
        $this->close($need)
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.closure_reason', self::REASON)
            ->assertJsonPath('data.resolved_by.name', 'مدخل بيانات تجريبي');

        $need->refresh();
        $this->assertSame(NeedStatus::CLOSED, $need->status);
        $this->assertSame($this->user->id, $need->resolved_by);
        $this->assertSame('2026-09-24 13:00:00', $need->resolved_at->toDateTimeString());
    }

    public function test_closure_reason_is_required(): void
    {
        $need = $this->createNeed();

        $this->close($need, null)->assertUnprocessable()->assertJsonValidationErrors('closure_reason');
        $this->close($need, '')->assertUnprocessable()->assertJsonValidationErrors('closure_reason');
        $this->close($need, '   ')->assertUnprocessable()->assertJsonValidationErrors('closure_reason');

        $this->assertSame(NeedStatus::OPEN, $need->fresh()->status);
    }

    public function test_cannot_close_twice_or_fulfil_after_closing(): void
    {
        $need = $this->createNeed();
        $this->close($need)->assertOk();

        $this->close($need, 'سبب آخر')->assertStatus(409);
        $this->fulfill($need)->assertStatus(409);

        $this->assertSame(self::REASON, $need->fresh()->closure_reason);
        $this->assertSame(['NEED_CREATED', 'NEED_CLOSED'], $this->eventTypes());
    }

    public function test_closed_need_cannot_reopen(): void
    {
        $need = $this->createNeed();
        $this->close($need)->assertOk();

        $this->update($need, ['status' => 'OPEN'])->assertUnprocessable();
        $this->update($need, ['priority' => 'LOW'])->assertStatus(409);
        $this->assertSame(NeedStatus::CLOSED, $need->fresh()->status);
    }

    // ---------------------------------------------------------------- permissions

    public function test_operational_roles_have_full_access(): void
    {
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'SOCIAL_WORKER'] as $role) {
            $user = $this->user($role);

            $this->familyList([], $user)->assertOk()->assertJsonPath('abilities.create', true);
            $this->actingAs($user)->getJson('/api/v1/needs')->assertOk();

            $id = $this->create([], $user)->assertCreated()->json('data.id');
            $need = FamilyNeed::where('uuid', $id)->first();
            $this->actingAs($user)->getJson("/api/v1/needs/{$id}")->assertOk();
            $this->update($need, ['title' => 'تعديل'], $user)->assertOk();
            $this->fulfill($need, $user)->assertOk();

            $second = FamilyNeed::where('uuid', $this->create([], $user)->json('data.id'))->first();
            $this->close($second, self::REASON, $user)->assertOk();
        }
    }

    public function test_reviewer_can_only_view(): void
    {
        $reviewer = $this->user('REVIEWER');
        $need = $this->createNeed();

        $this->familyList([], $reviewer)->assertOk()->assertJsonPath('abilities.create', false);
        $this->actingAs($reviewer)->getJson('/api/v1/needs')->assertOk();
        $this->actingAs($reviewer)->getJson("/api/v1/needs/{$need->uuid}")
            ->assertOk()
            ->assertJsonPath('abilities.update', false)
            ->assertJsonPath('abilities.fulfill', false)
            ->assertJsonPath('abilities.close', false);

        $this->create([], $reviewer)->assertForbidden();
        $this->update($need, ['title' => 'x'], $reviewer)->assertForbidden();
        $this->fulfill($need, $reviewer)->assertForbidden();
        $this->close($need, self::REASON, $reviewer)->assertForbidden();

        $this->assertSame(NeedStatus::OPEN, $need->fresh()->status);
    }

    public function test_reports_viewer_and_family_user_are_denied_everything(): void
    {
        $need = $this->createNeed();

        foreach (['REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $user = $this->user($role);

            $this->familyList([], $user)->assertForbidden();
            $this->actingAs($user)->getJson('/api/v1/needs')->assertForbidden();
            $this->actingAs($user)->getJson("/api/v1/needs/{$need->uuid}")->assertForbidden();
            $this->create([], $user)->assertForbidden();
            $this->update($need, ['title' => 'x'], $user)->assertForbidden();
            $this->fulfill($need, $user)->assertForbidden();
            $this->close($need, self::REASON, $user)->assertForbidden();
        }

        $this->assertSame(1, FamilyNeed::count());
    }

    public function test_family_update_is_not_a_substitute_for_need_permissions(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['family.view', 'family.update']);

        $this->create([], $user)->assertForbidden();
        $this->familyList([], $user)->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/needs')->assertUnauthorized();
        $this->getJson("/api/v1/families/{$this->family->family_code}/needs")->assertUnauthorized();
        $this->getJson('/api/v1/reference/need-categories')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- activity

    public function test_each_operation_writes_one_activity(): void
    {
        $need = $this->createNeed();
        $this->update($need, ['priority' => 'URGENT'])->assertOk();
        $this->fulfill($need)->assertOk();
        $closed = $this->createNeed();
        $this->close($closed)->assertOk();

        $this->assertSame(
            ['NEED_CREATED', 'NEED_UPDATED', 'NEED_FULFILLED', 'NEED_CREATED', 'NEED_CLOSED'],
            $this->eventTypes()
        );

        foreach (FamilyActivity::where('family_id', $this->family->id)->get() as $activity) {
            $this->assertSame('need', $activity->subject_type);
            $this->assertSame($this->user->id, $activity->actor_user_id);
        }
    }

    public function test_failed_operations_produce_no_activity(): void
    {
        $this->create($this->payload(['priority' => 'CRITICAL']))->assertUnprocessable();
        $this->create($this->payload(['person_code' => 'PER-UNKNOWN']))->assertUnprocessable();
        $this->create([], $this->user('REVIEWER'))->assertForbidden();
        $this->assertSame([], $this->eventTypes());

        $need = $this->createNeed();
        // Title change + invalid unit in one request: all rolled back.
        $this->update($need, ['title' => 'عنوان جديد', 'quantity' => null])->assertUnprocessable();
        $this->assertSame('مواد إيواء', $need->fresh()->title);
        $this->close($need, null)->assertUnprocessable();
        $this->fulfill($need, $this->user('REVIEWER'))->assertForbidden();

        $this->assertSame(['NEED_CREATED'], $this->eventTypes());
    }

    public function test_activity_holds_no_sensitive_need_fields(): void
    {
        $assessment = $this->completedAssessment();
        $need = $this->createNeed($this->payload([
            'person_code' => $this->member->person_code,
            'source_assessment_id' => $assessment->uuid,
        ]));
        $this->update($need, ['description' => self::DESCRIPTION.' 2'])->assertOk();
        $this->close($need)->assertOk();

        foreach (FamilyActivity::where('family_id', $this->family->id)->get() as $activity) {
            $this->assertNull($activity->metadata);
        }

        $timeline = $this->actingAs($this->user)
            ->getJson("/api/v1/families/{$this->family->family_code}/activities")
            ->assertOk();
        $this->assertSame(['NEED_CLOSED', 'NEED_UPDATED', 'NEED_CREATED'], array_column($timeline->json('data'), 'event_type'));
        // Title and target person are resolved at read time.
        $timeline->assertJsonPath('data.0.subject.type', 'need')
            ->assertJsonPath('data.0.subject.title', 'مواد إيواء')
            ->assertJsonPath('data.0.subject.person.full_name', 'فرد تجريبي');

        $raw = $timeline->getContent();
        foreach ([self::DESCRIPTION, self::REASON, self::ASSESSMENT_NOTE, 'فرشة', self::NATIONAL_ID, self::MOBILE] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    public function test_need_events_are_hidden_without_need_view(): void
    {
        $this->createNeed();
        $user = User::factory()->create();
        $user->givePermissionTo('activity-log.view');

        $this->actingAs($user)
            ->getJson("/api/v1/families/{$this->family->family_code}/activities")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ---------------------------------------------------------------- privacy

    public function test_need_responses_contain_no_identity_phone_health_ids_or_assessment_content(): void
    {
        PersonHealthRecord::factory()->create([
            'person_id' => $this->member->id,
            'condition_name' => 'مرض اختباري',
            'details' => self::HEALTH_DETAILS,
        ]);
        $this->member->update(['national_id' => '9990005552', 'mobile' => '0799995552']);
        $assessment = $this->completedAssessment();
        $need = $this->createNeed($this->payload([
            'person_code' => $this->member->person_code,
            'source_assessment_id' => $assessment->uuid,
        ]));

        $responses = [
            $this->actingAs($this->user)->getJson("/api/v1/needs/{$need->uuid}")->assertOk(),
            $this->familyList(),
            $this->actingAs($this->user)->getJson('/api/v1/needs')->assertOk(),
        ];

        foreach ($responses as $response) {
            $raw = $response->getContent();
            foreach ([self::NATIONAL_ID, '9990005552', self::MOBILE, '0799995552', 'national_id', 'mobile',
                self::HEALTH_DETAILS, 'مرض اختباري', self::ASSESSMENT_NOTE, 'CRITICAL', 'email', 'family_id',
                'person_id', 'need_category_id', 'source_assessment_id'] as $secret) {
                $this->assertStringNotContainsString($secret, $raw, $secret);
            }
            $this->assertStringNotContainsString('"id":'.$need->id.',', $raw);
            $this->assertStringNotContainsString('"id":'.$assessment->id.',', $raw);
        }
    }

    public function test_need_content_is_absent_from_family_person_and_assessment_responses(): void
    {
        $assessment = $this->completedAssessment();
        $need = $this->createNeed($this->payload([
            'person_code' => $this->member->person_code,
            'source_assessment_id' => $assessment->uuid,
        ]));
        $admin = $this->user('SUPER_ADMIN');

        foreach ([
            '/api/v1/families',
            "/api/v1/families/{$this->family->family_code}",
            "/api/v1/people/{$this->member->person_code}",
            "/api/v1/assessments/{$assessment->uuid}",
        ] as $url) {
            $raw = $this->actingAs($admin)->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($need->uuid, $raw, $url);
            $this->assertStringNotContainsString(self::DESCRIPTION, $raw, $url);
            $this->assertStringNotContainsString('مواد إيواء', $raw, $url);
        }
    }

    // ---------------------------------------------------------------- listing

    public function test_family_list_is_isolated_and_summarised(): void
    {
        $other = Family::factory()->create();
        $this->create([], null, $other)->assertCreated();
        $this->createNeed($this->payload(['priority' => 'URGENT']));
        $fulfilled = $this->createNeed();
        $this->fulfill($fulfilled)->assertOk();

        $list = $this->familyList()->assertOk();
        $this->assertCount(2, $list->json('data'));
        $list->assertJsonPath('summary.open', 1)
            ->assertJsonPath('summary.urgent_open', 1)
            ->assertJsonPath('summary.fulfilled', 1)
            ->assertJsonPath('summary.closed', 0);

        $this->assertCount(1, $this->familyList([], null, $other)->json('data'));
    }

    public function test_list_orders_open_first_then_priority_then_newest(): void
    {
        $lowOld = $this->createNeed($this->payload(['priority' => 'LOW']));
        Carbon::setTestNow('2026-09-24 11:00:00');
        $resolvedUrgent = $this->createNeed($this->payload(['priority' => 'URGENT']));
        $this->fulfill($resolvedUrgent)->assertOk();
        $highOld = $this->createNeed($this->payload(['priority' => 'HIGH']));
        Carbon::setTestNow('2026-09-24 12:00:00');
        $urgent = $this->createNeed($this->payload(['priority' => 'URGENT']));
        $highNew = $this->createNeed($this->payload(['priority' => 'HIGH']));
        $medium = $this->createNeed($this->payload(['priority' => 'MEDIUM']));

        $ids = array_column($this->familyList()->json('data'), 'id');

        $this->assertSame(
            [$urgent->uuid, $highNew->uuid, $highOld->uuid, $medium->uuid, $lowOld->uuid, $resolvedUrgent->uuid],
            $ids
        );
    }

    public function test_filters_work(): void
    {
        $familyUrgent = $this->createNeed($this->payload(['priority' => 'URGENT', 'category_code' => 'FOOD']));
        $person = $this->createNeed($this->payload(['person_code' => $this->member->person_code, 'category_code' => 'MEDICATION']));
        $closed = $this->createNeed($this->payload(['priority' => 'LOW']));
        $this->close($closed)->assertOk();

        $ids = fn (array $query) => array_column($this->familyList($query)->assertOk()->json('data'), 'id');

        $this->assertSame([$familyUrgent->uuid], $ids(['priority' => 'URGENT']));
        $this->assertSame([$person->uuid], $ids(['category' => 'MEDICATION']));
        $this->assertSame([$person->uuid], $ids(['target' => 'person']));
        $this->assertEqualsCanonicalizing([$familyUrgent->uuid, $closed->uuid], $ids(['target' => 'family']));
        $this->assertSame([$closed->uuid], $ids(['status' => 'CLOSED']));
        $this->assertCount(2, $ids(['status' => 'OPEN']));

        $this->familyList(['status' => 'PENDING'])->assertUnprocessable();
        $this->familyList(['priority' => 'CRITICAL'])->assertUnprocessable();
        $this->familyList(['target' => 'group'])->assertUnprocessable();
    }

    public function test_global_work_queue_spans_families_and_filters(): void
    {
        $otherFamily = Family::factory()->create();
        $mine = $this->createNeed($this->payload(['priority' => 'HIGH']));
        $theirs = FamilyNeed::where('uuid', $this->create($this->payload(['priority' => 'URGENT', 'category_code' => 'WATER']), null, $otherFamily)->json('data.id'))->first();
        $done = $this->createNeed();
        $this->fulfill($done)->assertOk();

        $open = $this->actingAs($this->user('REVIEWER'))->getJson('/api/v1/needs?status=OPEN')->assertOk();
        $this->assertSame([$theirs->uuid, $mine->uuid], array_column($open->json('data'), 'id'));
        $open->assertJsonPath('data.0.family.family_code', $otherFamily->family_code);

        $this->assertSame([$theirs->uuid], array_column(
            $this->actingAs($this->user)->getJson('/api/v1/needs?category=WATER')->json('data'), 'id'
        ));
        $this->assertSame([$done->uuid], array_column(
            $this->actingAs($this->user)->getJson('/api/v1/needs?status=FULFILLED')->json('data'), 'id'
        ));
        $this->actingAs($this->user)->getJson('/api/v1/needs?per_page=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    // ---------------------------------------------------------------- immutability

    public function test_resolved_need_remains_readable(): void
    {
        $need = $this->createNeed();
        $this->close($need)->assertOk();
        NeedCategory::where('code', 'SHELTER')->update(['is_active' => false]);

        $this->actingAs($this->user('REVIEWER'))->getJson("/api/v1/needs/{$need->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.title', 'مواد إيواء')
            ->assertJsonPath('data.category.code', 'SHELTER')
            ->assertJsonPath('data.category.is_active', false)
            ->assertJsonPath('data.closure_reason', self::REASON);

        $this->assertCount(1, $this->familyList(['status' => 'CLOSED'])->json('data'));
    }

    public function test_no_delete_endpoint_exists_and_model_refuses_delete(): void
    {
        $need = $this->createNeed();

        $this->actingAs($this->user('SUPER_ADMIN'))->deleteJson("/api/v1/needs/{$need->uuid}")->assertStatus(405);

        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'needs')) {
                $this->assertNotContains('DELETE', $route->methods(), $route->uri());
            }
        }

        $this->expectException(LogicException::class);
        $need->delete();
    }

    public function test_unknown_need_returns_404(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/needs/00000000-0000-0000-0000-000000000000')->assertNotFound();
    }
}
