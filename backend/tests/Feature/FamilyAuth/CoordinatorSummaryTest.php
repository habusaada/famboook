<?php

namespace Tests\Feature\FamilyAuth;

use App\Enums\ChangeRequestRejectionReason;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\SubmissionChannel;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\Person;
use App\Models\User;
use App\Support\FamilyAuth\FamilyAccessResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FB-FAMILY-DASHBOARD-01: GET /api/v1/family/coordinator/summary — scope-wide
 * aggregates over the SAME authorized queries as Coordinator Space. Requests
 * are the Coordinator's own, grouped: in_progress (open statuses), completed
 * (APPLIED only), closed (REJECTED, CANCELLED). Synthetic data only.
 */
class CoordinatorSummaryTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/coordinator/summary';

    private ?User $staff = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
    }

    private function fetch(User $as): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->getJson(self::URI);
    }

    /** A coordinator-channel request in the given status, with exactly the columns that status requires. */
    private function requestIn(Family $family, ChangeRequestStatus $status, User $by): ChangeRequest
    {
        $this->staff ??= User::factory()->create();
        $reviewed = ! in_array($status, [ChangeRequestStatus::SUBMITTED, ChangeRequestStatus::CANCELLED], true);
        $approved = in_array($status, [ChangeRequestStatus::APPROVED, ChangeRequestStatus::APPLIED], true);

        $request = new ChangeRequest;
        $request->forceFill([
            'family_id' => $family->id,
            'submission_channel' => SubmissionChannel::COORDINATOR,
            'type' => ChangeRequestType::ADD_FAMILY_MEMBER,
            'payload_version' => 1,
            'submitted_data' => ['full_name' => 'فرد تجريبي'],
            'submitted_by' => $by->id,
            'submitted_by_person_id' => app(FamilyAccessResolver::class)->familyContext($by->fresh())->person->id,
            'client_reference' => (string) Str::uuid(),
            'status' => $status,
            'submitted_at' => now(),
            'reviewed_at' => $reviewed ? now() : null,
            'reviewed_by' => $reviewed ? $this->staff->id : null,
            'approved_at' => $approved ? now() : null,
            'approved_by' => $approved ? $this->staff->id : null,
            'applied_at' => $status === ChangeRequestStatus::APPLIED ? now() : null,
            'applied_by' => $status === ChangeRequestStatus::APPLIED ? $this->staff->id : null,
            'rejected_at' => $status === ChangeRequestStatus::REJECTED ? now() : null,
            'rejected_by' => $status === ChangeRequestStatus::REJECTED ? $this->staff->id : null,
            'rejection_reason_code' => $status === ChangeRequestStatus::REJECTED ? ChangeRequestRejectionReason::CANNOT_VERIFY : null,
            'cancelled_at' => $status === ChangeRequestStatus::CANCELLED ? now() : null,
            'cancelled_by' => $status === ChangeRequestStatus::CANCELLED ? $by->id : null,
        ])->save();

        return $request;
    }

    public function test_counts_follow_the_scope_and_the_approved_definitions(): void
    {
        $clan = $this->clan('SCOPE');
        $f1 = $this->familyIn($clan, members: 3);
        $f2 = $this->familyIn($clan, members: 2);
        // An ended membership never counts.
        DB::table('family_memberships')->insert([
            'family_id' => $f1->id, 'person_id' => Person::factory()->create()->id, 'is_household_head' => false,
            'is_active' => false, 'started_at' => now()->subYear()->toDateString(), 'ended_at' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Outside the scope: an inactive Branch of the Clan, and another Clan.
        $this->familyIn($clan, $this->branch($clan, code: 'OFF', active: false), members: 5);
        $outside = $this->familyIn($this->clan('ELSEWHERE'), members: 4);

        $coordinator = $this->coordinator();
        $this->assign($coordinator, $clan);
        $other = $this->coordinator('222222220');
        $this->assign($other, $clan);

        foreach ([ChangeRequestStatus::SUBMITTED, ChangeRequestStatus::UNDER_REVIEW, ChangeRequestStatus::RETURNED_FOR_CLARIFICATION,
            ChangeRequestStatus::RESUBMITTED, ChangeRequestStatus::APPROVED] as $open) {
            $this->requestIn($f1, $open, $coordinator);
        }
        foreach ([ChangeRequestStatus::APPLIED, ChangeRequestStatus::APPLIED, ChangeRequestStatus::REJECTED, ChangeRequestStatus::CANCELLED] as $done) {
            $this->requestIn($f2, $done, $coordinator);
        }
        // Never counted: another Coordinator's request, and an own request outside the scope.
        $this->requestIn($f1, ChangeRequestStatus::SUBMITTED, $other);
        $this->requestIn($outside, ChangeRequestStatus::APPLIED, $coordinator);

        $response = $this->fetch($coordinator)->assertOk()->assertExactJson(['data' => [
            'family_count' => 2,
            'person_count' => 5,
            'requests' => ['in_progress' => 5, 'completed' => 2, 'closed' => 2],
        ]]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        // The same family count as Coordinator Space's own context.
        $this->actingAs($coordinator->fresh())->getJson('/api/v1/family/coordinator/context')->assertJsonPath('data.family_count', 2);
    }

    public function test_overlapping_scopes_never_count_a_family_or_a_member_twice(): void
    {
        $clan = $this->clan('SCOPE');
        $group = $this->group($clan, 'GRP');
        $branch = $this->branch($clan, $group, 'BR');
        $this->familyIn($clan, $branch, members: 3);
        $this->familyIn($clan, members: 2);

        $coordinator = $this->coordinator();
        $this->assign($coordinator, $clan);
        $this->assign($coordinator, $group);
        $this->assign($coordinator, $branch);

        $this->fetch($coordinator)->assertOk()
            ->assertJsonPath('data.family_count', 2)
            ->assertJsonPath('data.person_count', 5);
    }

    public function test_a_coordinator_with_no_families_or_requests_gets_zeros(): void
    {
        $coordinator = $this->coordinator();
        $this->assign($coordinator, $this->clan('EMPTY'));

        $this->fetch($coordinator)->assertOk()->assertExactJson(['data' => [
            'family_count' => 0, 'person_count' => 0, 'requests' => ['in_progress' => 0, 'completed' => 0, 'closed' => 0],
        ]]);
    }

    public function test_without_an_effective_coordinator_scope_nothing_is_answered(): void
    {
        $clan = $this->clan('SCOPE');
        $this->familyIn($clan, members: 3);

        // Revoked assignment; inactive Clan; a plain household head; a Staff user; a guest.
        $revoked = $this->coordinator();
        $this->assign($revoked, $clan, revoked: true);
        $this->fetch($revoked)->assertForbidden();

        $inactive = $this->coordinator('333333330');
        $this->assign($inactive, $this->clan('OFF', active: false));
        $this->fetch($inactive)->assertForbidden();

        $this->fetch($this->activatedHead('444444440')['user'])->assertForbidden();
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('SUPER_ADMIN', 'web')));
        $this->fetch($staff)->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson(self::URI)->assertUnauthorized();
    }

    public function test_the_query_count_does_not_grow_with_the_scope(): void
    {
        $clan = $this->clan('SCOPE');
        $coordinator = $this->coordinator();
        $this->assign($coordinator, $clan);
        $family = $this->familyIn($clan, members: 2);
        $this->requestIn($family, ChangeRequestStatus::SUBMITTED, $coordinator);

        $count = function () use ($coordinator): int {
            $this->app['auth']->forgetGuards();
            $this->actingAs($coordinator->fresh());
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::URI)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $count(); // warms the permission cache
        $small = $count();

        for ($i = 0; $i < 6; $i++) {
            $more = $this->familyIn($clan, members: 3);
            $this->requestIn($more, ChangeRequestStatus::APPLIED, $coordinator);
        }
        $this->assertSame($small, $count());
        $this->fetch($coordinator)->assertJsonPath('data.family_count', 7)->assertJsonPath('data.person_count', 20)
            ->assertJsonPath('data.requests.completed', 6);
    }
}
