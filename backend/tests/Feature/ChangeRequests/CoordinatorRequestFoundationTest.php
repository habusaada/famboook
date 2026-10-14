<?php

namespace Tests\Feature\ChangeRequests;

use App\Actions\RecordHouseholdDeclarationAction;
use App\Enums\ChangeRequestType;
use App\Enums\RegistrationSource;
use App\Enums\SubmissionChannel;
use App\Enums\WorkflowActorSide;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestTypes;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\Support\ChangeRequests\ChangeRequestFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * CO-02.3a domain foundation (docs/04 DB-ADR-062): the new constants exist,
 * but nothing can use them yet — REGISTER_FAMILY is not registered and the
 * family route refuses it, COORDINATOR_REQUEST is never a selectable source,
 * no coordinator request route or permission exists. Runs on every driver.
 * Synthetic data only.
 */
class CoordinatorRequestFoundationTest extends TestCase
{
    use ChangeRequestFixtures, FamilyIdentityFixtures, RefreshDatabase;

    public function test_the_approved_constants_exist(): void
    {
        $this->assertSame('REGISTER_FAMILY', ChangeRequestType::REGISTER_FAMILY->value);
        $this->assertFalse(ChangeRequestType::REGISTER_FAMILY->isSystemVerified());
        $this->assertSame(['FAMILY', 'COORDINATOR'], array_map(fn (SubmissionChannel $c) => $c->value, SubmissionChannel::cases()));
        $this->assertSame('COORDINATOR', WorkflowActorSide::COORDINATOR->value);
        $this->assertSame('COORDINATOR_REQUEST', RegistrationSource::COORDINATOR_REQUEST->value);
        $this->assertSame([RegistrationSource::PAPER_FORM, RegistrationSource::MANUAL_ENTRY, RegistrationSource::IMPORT, RegistrationSource::VERIFIED_SOURCE],
            RegistrationSource::selectable());
    }

    public function test_requests_are_family_channel_by_default_and_the_channel_is_part_of_the_proposal(): void
    {
        $this->assertSame(SubmissionChannel::FAMILY, (new ChangeRequest)->submission_channel);

        $request = ChangeRequest::factory()->create();
        $this->assertSame(SubmissionChannel::FAMILY, $request->fresh()->submission_channel);
        $this->assertNull($request->created_family_id);
        $this->assertNull($request->createdFamily);
        $this->assertNull($request->proposedClan);
        $this->assertNotContains('created_family_id', $request->getFillable());

        foreach (['submission_channel' => 'COORDINATOR', 'proposed_clan_id' => 1, 'proposed_branch_id' => 1] as $field => $value) {
            try {
                $request->fresh()->forceFill([$field => $value])->save();
                $this->fail("{$field} changed after submission");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_register_family_is_registered_but_never_family_submittable(): void
    {
        // CO-02.5 registered it; it stays a Coordinator proposal only.
        $registry = app(ChangeRequestTypes::class);
        $this->assertTrue($registry->has(ChangeRequestType::REGISTER_FAMILY));
        $this->assertFalse($registry->handler(ChangeRequestType::REGISTER_FAMILY)->familySubmittable());
        $this->assertNotContains(ChangeRequestType::REGISTER_FAMILY, $registry->familySubmittable());
    }

    public function test_the_family_submission_route_never_accepts_register_family(): void
    {
        $this->setUpChangeRequestEngine();
        $this->withoutMiddleware(ThrottleRequests::class);
        $head = $this->headContext();

        $this->actingAs($head->user->fresh())->postJson('/api/v1/family/change-requests', [
            'type' => 'REGISTER_FAMILY', 'client_reference' => (string) Str::uuid(), 'data' => ['head' => ['full_name' => 'اسم تجريبي']],
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);

        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_the_coordinator_request_permissions_are_coordinator_only(): void
    {
        // CO-02.4 added the coordinator request routes (CoordinatorChangeRequestApiTest
        // checks their boundaries). CO-02.3b (AUTH-ADR-093): the four
        // permissions exist, held by COORDINATOR only.
        $this->seed(RolePermissionSeeder::class);
        $permissions = Permission::where('name', 'like', 'coordinator-request.%')->with('roles')->get();
        $this->assertEqualsCanonicalizing(
            ['coordinator-request.view', 'coordinator-request.submit', 'coordinator-request.resubmit', 'coordinator-request.cancel'],
            $permissions->pluck('name')->all(),
        );
        foreach ($permissions as $permission) {
            $this->assertSame(['COORDINATOR'], $permission->roles->pluck('name')->all(), $permission->name);
        }
    }

    public function test_staff_registration_never_accepts_the_coordinator_request_source(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('SUPER_ADMIN');

        $this->actingAs($admin)->postJson('/api/v1/families', [
            'registration_date' => '2026-09-01',
            'registration_source' => 'COORDINATOR_REQUEST',
            'household_head' => ['full_name' => 'رب أسرة تجريبي', 'national_id' => '741234567', 'gender' => 'MALE'],
        ])->assertStatus(422)->assertJsonValidationErrors(['registration_source']);

        $this->assertSame(0, Family::count());
    }

    public function test_a_household_declaration_never_accepts_the_coordinator_request_source(): void
    {
        $family = Family::factory()->create();

        try {
            app(RecordHouseholdDeclarationAction::class)->handle($family, ['declared_household_size' => 4, 'source' => 'COORDINATOR_REQUEST'], null, null);
            $this->fail('A declaration accepted COORDINATOR_REQUEST.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('source', $e->errors());
        }
    }
}
