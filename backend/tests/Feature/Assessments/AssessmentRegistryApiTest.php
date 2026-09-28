<?php

namespace Tests\Feature\Assessments;

use App\Enums\AssessmentStatus;
use App\Models\Assessment;
use App\Models\AssessmentDomain;
use App\Models\AssessmentResult;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use App\Models\User;
use Database\Seeders\AssessmentDomainSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cross-family assessment registry (GET /api/v1/assessments) for the
 * Assessments Pilot Workspace: assessment.view only, server-side status and
 * exact family filters, deterministic order, pagination, and no notes or
 * identity/contact/health data. All values are synthetic.
 */
class AssessmentRegistryApiTest extends TestCase
{
    use RefreshDatabase;

    private const NATIONAL_ID = '9990008881';

    private const MOBILE = '0599000999';

    private const GENERAL_NOTE = 'ملاحظة عامة اختبارية سرية للسجل';

    private const DOMAIN_NOTE = 'ملاحظة مجال اختبارية سرية للسجل';

    private const HEALTH_DETAILS = 'تفاصيل صحية اختبارية سرية للسجل';

    private Family $family;

    private Family $otherFamily;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-24 10:00:00');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(AssessmentDomainSeeder::class);

        $clan = Clan::where('code', Clan::AL_BREEM)->firstOrFail();
        $group = BranchGroup::create(['clan_id' => $clan->id, 'code' => 'G01', 'name' => 'مجموعة تجريبية', 'sort_order' => 1]);
        $this->branch = Branch::create(['branch_group_id' => $group->id, 'clan_id' => $clan->id, 'code' => 'BR_A', 'name' => 'فرع تجريبي', 'sort_order' => 1]);

        $this->family = Family::factory()->create(['branch_id' => $this->branch->id]);
        $head = Person::factory()->create([
            'full_name' => 'رب أسرة اختباري',
            'national_id' => self::NATIONAL_ID,
            'mobile' => self::MOBILE,
        ]);
        FamilyMembership::factory()->householdHead()->create(['family_id' => $this->family->id, 'person_id' => $head->id]);
        PersonHealthRecord::factory()->create(['person_id' => $head->id, 'details' => self::HEALTH_DETAILS]);

        // No branch, no household head: context must be null, not an error.
        $this->otherFamily = Family::factory()->create(['branch_id' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function assessment(Family $family, string $date, AssessmentStatus $status, int $results = 1): Assessment
    {
        $assessment = Assessment::create([
            'family_id' => $family->id,
            'assessment_date' => $date,
            'status' => AssessmentStatus::DRAFT,
            'general_notes' => self::GENERAL_NOTE,
        ]);
        foreach (AssessmentDomain::orderBy('sort_order')->limit($results)->get() as $domain) {
            AssessmentResult::create([
                'assessment_id' => $assessment->id,
                'assessment_domain_id' => $domain->id,
                'rating' => 'HIGH',
                'notes' => self::DOMAIN_NOTE,
            ]);
        }
        if ($status === AssessmentStatus::COMPLETED) {
            $assessment->update(['status' => AssessmentStatus::COMPLETED, 'completed_at' => now()]);
        }
        Carbon::setTestNow(now()->addMinute()); // distinct created_at per row

        return $assessment;
    }

    private function registry(?User $as = null, string $query = '')
    {
        return $this->actingAs($as ?? $this->user('ADMINISTRATOR'))->getJson('/api/v1/assessments'.$query);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/assessments')->assertUnauthorized();
    }

    public function test_roles_without_assessment_view_are_forbidden(): void
    {
        foreach (['REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $this->registry($this->user($role))->assertForbidden();
        }
    }

    public function test_every_assessment_view_role_can_list(): void
    {
        $this->assessment($this->family, '2026-09-20', AssessmentStatus::DRAFT);

        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'SOCIAL_WORKER', 'REVIEWER'] as $role) {
            $this->registry($this->user($role))->assertOk()->assertJsonCount(1, 'data');
        }
    }

    public function test_rows_carry_status_dates_family_context_and_domain_count(): void
    {
        $draft = $this->assessment($this->family, '2026-09-20', AssessmentStatus::DRAFT, 3);
        $completed = $this->assessment($this->otherFamily, '2026-09-18', AssessmentStatus::COMPLETED, 2);

        $rows = collect($this->registry()->assertOk()->json('data'))->keyBy('id');

        $this->assertSame([
            'id' => $draft->uuid,
            'assessment_date' => '2026-09-20',
            'status' => 'DRAFT',
            'assessed_domain_count' => 3,
            'family' => [
                'family_code' => $this->family->family_code,
                'household_head_name' => 'رب أسرة اختباري',
                'branch_name' => 'فرع تجريبي',
            ],
        ], collect($rows[$draft->uuid])->only(['id', 'assessment_date', 'status', 'assessed_domain_count', 'family'])->all());
        $this->assertNull($rows[$draft->uuid]['completed_at']);

        $this->assertSame('COMPLETED', $rows[$completed->uuid]['status']);
        $this->assertSame(2, $rows[$completed->uuid]['assessed_domain_count']);
        $this->assertNotNull($rows[$completed->uuid]['completed_at']);
        $this->assertSame(['family_code' => $this->otherFamily->family_code, 'household_head_name' => null, 'branch_name' => null], $rows[$completed->uuid]['family']);
    }

    public function test_order_is_drafts_first_then_newest_assessment_date_then_newest_entry(): void
    {
        $oldCompleted = $this->assessment($this->family, '2026-09-01', AssessmentStatus::COMPLETED);
        $newCompleted = $this->assessment($this->family, '2026-09-22', AssessmentStatus::COMPLETED);
        $oldDraft = $this->assessment($this->otherFamily, '2026-09-05', AssessmentStatus::DRAFT);
        $sameDateFirst = $this->assessment($this->family, '2026-09-10', AssessmentStatus::DRAFT);
        $sameDateLater = $this->assessment($this->otherFamily, '2026-09-10', AssessmentStatus::DRAFT);

        $this->assertSame(
            [$sameDateLater->uuid, $sameDateFirst->uuid, $oldDraft->uuid, $newCompleted->uuid, $oldCompleted->uuid],
            array_column($this->registry()->json('data'), 'id'),
        );
    }

    public function test_status_filter_is_server_side_and_validated(): void
    {
        $draft = $this->assessment($this->family, '2026-09-20', AssessmentStatus::DRAFT);
        $completed = $this->assessment($this->family, '2026-09-19', AssessmentStatus::COMPLETED);

        $this->assertSame([$draft->uuid], array_column($this->registry(null, '?status=DRAFT')->json('data'), 'id'));
        $this->assertSame([$completed->uuid], array_column($this->registry(null, '?status=COMPLETED')->json('data'), 'id'));
        $this->registry(null, '?status=APPROVED')->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_family_filter_matches_the_exact_family_code_only(): void
    {
        $mine = $this->assessment($this->family, '2026-09-20', AssessmentStatus::DRAFT);
        $this->assessment($this->otherFamily, '2026-09-20', AssessmentStatus::DRAFT);

        $this->assertSame([$mine->uuid], array_column($this->registry(null, '?family='.$this->family->family_code)->json('data'), 'id'));
        $this->assertSame([], $this->registry(null, '?family='.substr($this->family->family_code, 0, 6))->json('data'));
    }

    public function test_registry_is_paginated_and_summary_counts_the_whole_registry(): void
    {
        foreach (range(1, 3) as $day) {
            $this->assessment($this->family, "2026-09-0{$day}", AssessmentStatus::DRAFT);
        }
        foreach (range(4, 5) as $day) {
            $this->assessment($this->otherFamily, "2026-09-0{$day}", AssessmentStatus::COMPLETED);
        }

        $page = $this->registry(null, '?per_page=2&page=2&status=DRAFT')->assertOk();

        $page->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 3)
            // Filters never change the registry-wide summary.
            ->assertJsonPath('summary', ['total' => 5, 'draft' => 3, 'completed' => 2]);
    }

    public function test_update_ability_follows_assessment_update(): void
    {
        $this->registry($this->user('DATA_ENTRY'))->assertJsonPath('abilities.update', true);
        $this->registry($this->user('REVIEWER'))->assertJsonPath('abilities.update', false);
    }

    public function test_response_contains_no_notes_ratings_identity_contact_or_health_data(): void
    {
        $this->assessment($this->family, '2026-09-20', AssessmentStatus::COMPLETED, 2);

        $body = $this->registry()->assertOk()->getContent();

        foreach ([self::NATIONAL_ID, self::MOBILE, self::GENERAL_NOTE, self::DOMAIN_NOTE, self::HEALTH_DETAILS] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        foreach (['national_id', 'mobile', 'general_notes', 'notes', 'ratings', 'health'] as $key) {
            $this->assertStringNotContainsString("\"{$key}\"", $body);
        }
    }

    public function test_query_count_does_not_grow_with_rows(): void
    {
        $this->assessment($this->family, '2026-09-20', AssessmentStatus::DRAFT);
        $admin = $this->user('ADMINISTRATOR');
        $this->registry($admin)->assertOk(); // warm the permission cache

        DB::enableQueryLog();
        $this->registry($admin)->assertOk();
        $few = count(DB::getQueryLog());

        foreach (range(1, 6) as $day) {
            $this->assessment($day % 2 ? $this->family : $this->otherFamily, "2026-09-0{$day}", AssessmentStatus::DRAFT);
        }
        DB::flushQueryLog();
        $this->registry($admin)->assertOk();

        $this->assertSame($few, count(DB::getQueryLog()));
    }
}
