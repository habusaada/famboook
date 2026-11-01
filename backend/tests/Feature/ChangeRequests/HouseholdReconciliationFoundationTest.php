<?php

namespace Tests\Feature\ChangeRequests;

use App\Enums\ChangeRequestType;
use App\Enums\HouseholdReconciliationMode as Mode;
use App\Enums\SubmissionChannel;
use App\Enums\WorkflowActorSide;
use App\Exceptions\ChangeRequestException;
use App\Http\Requests\Api\V1\ChangeRequestIndexRequest;
use App\Http\Requests\Api\V1\Family\FamilyChangeRequestIndexRequest;
use App\Http\Requests\Api\V1\Family\SubmitFamilyChangeRequestRequest;
use App\Models\Clan;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestCodes;
use App\Support\ChangeRequests\ChangeRequestSubmissionContext;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\HouseholdReconciliationPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * FB-FAMILY-RECON-01 WP-03: the foundation activates nothing. Runs on both
 * drivers; the CHECKs are in HouseholdReconciliationSchemaTest. Synthetic data only.
 */
class HouseholdReconciliationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'household-reconciliation.submit',
        'household-reconciliation.view',
        'household-reconciliation.approve',
        'household-reconciliation.apply',
    ];

    // ---------------------------------------------------------------- flag

    public function test_the_flag_is_off_by_default_and_in_the_test_environment(): void
    {
        $this->assertSame('OFF', config('household_reconciliation.mode'));
        $this->assertSame(Mode::OFF, HouseholdReconciliationPolicy::mode());
    }

    public function test_the_flag_accepts_exactly_its_three_values(): void
    {
        foreach (['OFF' => Mode::OFF, 'PILOT' => Mode::PILOT, 'ON' => Mode::ON, ' pilot ' => Mode::PILOT, 'on' => Mode::ON] as $raw => $mode) {
            config(['household_reconciliation.mode' => $raw]);
            $this->assertSame($mode, HouseholdReconciliationPolicy::mode(), (string) $raw);
        }
    }

    public function test_the_flag_fails_closed_on_anything_else(): void
    {
        foreach (['', '  ', 'GENERAL', 'ENABLED', '1', 'true', null, true, 1, ['ON']] as $raw) {
            config(['household_reconciliation.mode' => $raw]);
            $this->assertSame(Mode::OFF, HouseholdReconciliationPolicy::mode(), var_export($raw, true));
        }
    }

    public function test_the_flag_is_independent_of_other_switches(): void
    {
        config(['household_reconciliation.mode' => 'ON', 'change_requests.family_submission_mode' => 'OFF', 'change_requests.coordinator_submission_mode' => 'OFF']);
        $this->assertSame(Mode::ON, HouseholdReconciliationPolicy::mode());

        config(['household_reconciliation.mode' => 'OFF', 'change_requests.family_submission_mode' => 'GENERAL', 'family_registry_admission.mode' => 'ON']);
        $this->assertSame(Mode::OFF, HouseholdReconciliationPolicy::mode());
    }

    public function test_setting_the_flag_on_opens_no_route_and_no_handler(): void
    {
        config(['household_reconciliation.mode' => 'ON']);

        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri());
        $this->assertSame([], $routes->filter(fn (string $uri) => str_contains($uri, 'household-reconciliation'))->values()->all());

        $this->assertArrayNotHasKey(ChangeRequestType::HOUSEHOLD_RECONCILIATION->value, ChangeRequestTypes::PRODUCTION);
        try {
            ChangeRequestTypes::production()->handler(ChangeRequestType::HOUSEHOLD_RECONCILIATION);
            $this->fail('The type has no handler in this work package.');
        } catch (ChangeRequestException $e) {
            $this->assertSame(ChangeRequestException::TYPE_UNAVAILABLE, $e->reason);
        }
    }

    public function test_no_existing_endpoint_accepts_the_new_values(): void
    {
        $refuses = function (string $request, string $field, string $value): void {
            $rules = [$field => (new $request)->rules()[$field]];
            $this->assertTrue(Validator::make([$field => $value], $rules)->fails(), "{$request} {$field} {$value}");
        };

        $refuses(ChangeRequestIndexRequest::class, 'channel', 'STAFF');
        $refuses(ChangeRequestIndexRequest::class, 'type', 'HOUSEHOLD_RECONCILIATION');
        $refuses(FamilyChangeRequestIndexRequest::class, 'type', 'HOUSEHOLD_RECONCILIATION');
        $refuses(SubmitFamilyChangeRequestRequest::class, 'type', 'HOUSEHOLD_RECONCILIATION');
        $refuses(SubmitFamilyChangeRequestRequest::class, 'type', 'REGISTER_FAMILY');
    }

    // ---------------------------------------------------------------- permissions

    public function test_the_permissions_are_defined_and_assigned_to_no_role(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach (self::PERMISSIONS as $permission) {
            $this->assertContains($permission, RolePermissionSeeder::PERMISSIONS);
            $this->assertTrue(Permission::where('name', $permission)->exists(), $permission);
            $this->assertSame(0, Permission::findByName($permission, 'web')->roles()->count(), $permission);
        }
        foreach (RolePermissionSeeder::ROLES as $role) {
            $this->assertSame([], array_values(array_intersect(self::PERMISSIONS, RolePermissionSeeder::rolePermissions($role))), $role);
        }
    }

    public function test_no_role_gains_access_and_the_transfer_permission_stays_as_it_was(): void
    {
        $this->seed(RolePermissionSeeder::class);

        foreach (RolePermissionSeeder::ROLES as $role) {
            $user = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName($role, 'web')));
            foreach (self::PERMISSIONS as $permission) {
                $this->assertFalse($user->can($permission), "{$role} {$permission}");
            }
        }
        $this->assertEqualsCanonicalizing(['SUPER_ADMIN', 'ADMINISTRATOR'],
            Permission::findByName('family-membership.transfer', 'web')->roles()->pluck('name')->all());
    }

    public function test_seeding_twice_keeps_them_unassigned_and_verify_permissions_passes(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(0, DB::table('role_has_permissions')->whereIn('permission_id', Permission::whereIn('name', self::PERMISSIONS)->pluck('id'))->count());
        $this->assertSame(0, Artisan::call('famboook:verify-permissions'));
    }

    // ---------------------------------------------------------------- enums and index

    public function test_the_enums_have_the_new_values_and_the_channel_maps_to_its_actor_side(): void
    {
        $this->assertSame('HOUSEHOLD_RECONCILIATION', ChangeRequestType::HOUSEHOLD_RECONCILIATION->value);
        $this->assertSame('STAFF', SubmissionChannel::STAFF->value);
        $this->assertFalse(ChangeRequestType::HOUSEHOLD_RECONCILIATION->isSystemVerified());

        $context = (new \ReflectionClass(ChangeRequestSubmissionContext::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ChangeRequestSubmissionContext::class, 'channel'))->setValue($context, SubmissionChannel::STAFF);
        $this->assertSame(WorkflowActorSide::STAFF, $context->actorSide());
    }

    public function test_one_open_reconciliation_per_person_and_a_null_submitter_person_on_this_driver(): void
    {
        $membership = FamilyMembership::factory()->create(['is_household_head' => false, 'is_active' => true]);
        $row = fn (string $status = 'SUBMITTED', array $o = []) => [
            'uuid' => (string) Str::uuid(), 'request_code' => ChangeRequestCodes::next(), 'family_id' => null,
            'submission_channel' => 'STAFF', 'type' => 'HOUSEHOLD_RECONCILIATION', 'payload_version' => 1, 'status' => $status,
            'person_id' => $membership->person_id, 'target_membership_id' => $membership->id,
            'proposed_clan_id' => Clan::where('code', Clan::AL_BREEM)->firstOrFail()->id, 'submitted_data' => '{"example":"synthetic"}',
            'submitted_by' => User::factory()->create()->id, 'submitted_by_person_id' => null, 'submitted_at' => now(),
            'created_at' => now(), 'updated_at' => now(), ...$o,
        ];

        $staff = User::factory()->create()->id;
        DB::table('change_requests')->insert($row('CANCELLED', ['cancelled_by' => $staff, 'cancelled_at' => now()]));
        DB::table('change_requests')->insert($row());

        try {
            DB::transaction(fn () => DB::table('change_requests')->insert($row()));
            $this->fail('A second open reconciliation was accepted.');
        } catch (QueryException $e) {
            // SQLite names the columns, PostgreSQL the index.
            $this->assertMatchesRegularExpression('/uq_change_request_open_reconciliation_person|change_requests\.person_id/', $e->getMessage());
        }
    }
}
