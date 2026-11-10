<?php

namespace Tests\Feature\FamilyPortal;

use App\Enums\ChangeRequestType;
use App\Enums\FamilyRegistryAdmissionMode as Mode;
use App\Enums\HouseholdReconciliationMode;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Support\ChangeRequests\HouseholdReconciliationPolicy;
use App\Support\FamilyPortal\FamilyRegistryAdmissionPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 ADD-WP-04: the foundation activates nothing. Runs on both
 * drivers; the CHECKs and triggers are in FamilyRegistryAdmissionSchemaTest.
 * Synthetic data only.
 */
class FamilyRegistryAdmissionFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'family-member.admit-from-registry';

    private const MIGRATION = __DIR__.'/../../../database/migrations/2026_11_01_100000_create_family_registry_admission_tables.php';

    /** @return array<string, mixed> */
    private function admission(FamilyMembership $membership, int $user, int $head, array $o = []): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'family_id' => $membership->family_id, 'person_id' => $membership->person_id,
            'family_membership_id' => $membership->id, 'admitted_by' => $user, 'admitted_by_person_id' => $head,
            'client_reference' => (string) Str::uuid(), 'outcome_type' => 'PERSON_ATTACHED', 'parent_link' => 'FATHER',
            'reference_fingerprint' => str_repeat('a', 64), 'reference_key_version' => 1, 'release_id' => 1,
            'declaration_version' => '2026-11-v1', 'declared_at' => now(), 'created_at' => now(), ...$o,
        ];
    }

    // ---------------------------------------------------------------- flag

    public function test_the_flag_is_off_by_default_and_in_the_test_environment(): void
    {
        $this->assertSame('OFF', config('family_registry_admission.mode'));
        $this->assertSame(Mode::OFF, FamilyRegistryAdmissionPolicy::mode());
    }

    public function test_the_flag_accepts_exactly_its_three_values(): void
    {
        foreach (['OFF' => Mode::OFF, 'PILOT' => Mode::PILOT, 'ON' => Mode::ON, ' pilot ' => Mode::PILOT, 'on' => Mode::ON] as $raw => $mode) {
            config(['family_registry_admission.mode' => $raw]);
            $this->assertSame($mode, FamilyRegistryAdmissionPolicy::mode(), (string) $raw);
        }
    }

    public function test_the_flag_fails_closed_on_anything_else(): void
    {
        foreach (['', '  ', 'GENERAL', 'ENABLED', '1', 'true', null, true, 1, ['ON']] as $raw) {
            config(['family_registry_admission.mode' => $raw]);
            $this->assertSame(Mode::OFF, FamilyRegistryAdmissionPolicy::mode(), var_export($raw, true));
        }
    }

    public function test_the_flag_is_independent_of_reconciliation_in_both_directions(): void
    {
        config(['family_registry_admission.mode' => 'ON', 'household_reconciliation.mode' => 'OFF']);
        $this->assertSame(Mode::ON, FamilyRegistryAdmissionPolicy::mode());
        $this->assertSame(HouseholdReconciliationMode::OFF, HouseholdReconciliationPolicy::mode());

        config(['family_registry_admission.mode' => 'OFF', 'household_reconciliation.mode' => 'ON']);
        $this->assertSame(Mode::OFF, FamilyRegistryAdmissionPolicy::mode());
        $this->assertSame(HouseholdReconciliationMode::ON, HouseholdReconciliationPolicy::mode());
    }

    // ADD-WP-06B-3: the guarded, read-only discovery; ADD-WP-07C: the guarded admission POST. Nothing else.
    public function test_the_only_routes_are_the_guarded_discovery_and_admission(): void
    {
        foreach (['OFF', 'PILOT', 'ON'] as $mode) {
            config(['family_registry_admission.mode' => $mode]);
            $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => preg_match('/registry-children|admissions|suggested/', $route->uri()) === 1)->sortBy(fn ($route) => $route->uri())->values();
            $this->assertSame(['api/v1/family/household/registry-children', 'api/v1/family/household/registry-children/admissions'],
                $routes->map(fn ($route) => $route->uri())->all(), $mode);
            $this->assertSame([['GET', 'HEAD'], ['POST']], $routes->map(fn ($route) => $route->methods())->all(), $mode);
            $this->assertContains('family.registry-discovery', $routes[0]->gatherMiddleware(), $mode);
            $this->assertContains('family.registry-admission', $routes[1]->gatherMiddleware(), $mode);
        }
    }

    public function test_direct_admission_is_not_a_change_request_type(): void
    {
        $values = array_column(ChangeRequestType::cases(), 'value');

        $this->assertSame([], array_values(array_filter($values, fn (string $value) => str_contains($value, 'ADMISSION'))));
    }

    // ---------------------------------------------------------------- permission

    public function test_the_permission_is_defined_and_assigned_to_family_user_only(): void
    {
        // Owner decision 2026-11-10 (general release, docs/06 §61d-2): FAMILY_USER only.
        $this->seed(RolePermissionSeeder::class);

        $this->assertContains(self::PERMISSION, RolePermissionSeeder::PERMISSIONS);
        $this->assertSame(['FAMILY_USER'], Permission::findByName(self::PERMISSION, 'web')->roles()->pluck('name')->all());
        foreach (RolePermissionSeeder::ROLES as $role) {
            $holds = $role === 'FAMILY_USER';
            $this->assertSame($holds, in_array(self::PERMISSION, RolePermissionSeeder::rolePermissions($role), true), $role);
            $user = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName($role, 'web')));
            $this->assertSame($holds, $user->can(self::PERMISSION), $role);
        }
    }

    public function test_seeding_twice_keeps_it_on_family_user_only_and_verify_permissions_passes(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(['FAMILY_USER'], Permission::findByName(self::PERMISSION, 'web')->roles()->pluck('name')->all());
        $this->assertSame(0, Artisan::call('famboook:verify-permissions'));
        $this->assertEqualsCanonicalizing(['SUPER_ADMIN', 'ADMINISTRATOR'],
            Permission::findByName('family-membership.transfer', 'web')->roles()->pluck('name')->all());
    }

    // ---------------------------------------------------------------- schema on both drivers

    public function test_the_tables_hold_no_identity_or_payload_columns(): void
    {
        foreach (['family_registry_admissions', 'family_registry_admission_events'] as $table) {
            $columns = Schema::getColumnListing($table);
            $this->assertSame([], array_values(array_intersect($columns, [
                'record_id', 'record_version_id', 'mainid', 'national_id', 'full_name', 'fullname', 'name',
                'fatherid', 'motherid', 'source_payload', 'payload', 'metadata', 'request_body', 'updated_at',
            ])), $table);
        }
    }

    public function test_the_membership_and_person_schema_are_unchanged(): void
    {
        $this->assertEqualsCanonicalizing(['id', 'family_id', 'person_id', 'relationship_type_id', 'is_household_head', 'paper_sequence_no',
            'started_at', 'ended_at', 'is_active', 'end_reason', 'notes', 'created_by', 'updated_by', 'created_at', 'updated_at'],
            Schema::getColumnListing('family_memberships'));
        foreach (['registry_admission', 'admission_id', 'admitted_by'] as $column) {
            $this->assertFalse(Schema::hasColumn('persons', $column), $column);
            $this->assertFalse(Schema::hasColumn('family_memberships', $column), $column);
        }
    }

    public function test_one_evidence_row_per_membership_and_per_client_reference(): void
    {
        $user = User::factory()->familySide()->create()->id;
        $head = FamilyMembership::factory()->householdHead()->create();
        $first = FamilyMembership::factory()->create(['family_id' => $head->family_id]);
        $second = FamilyMembership::factory()->create(['family_id' => $head->family_id]);
        $row = $this->admission($first, $user, $head->person_id);
        DB::table('family_registry_admissions')->insert($row);

        foreach ([
            [$first, []],
            [$second, ['client_reference' => $row['client_reference']]],
        ] as [$membership, $override]) {
            try {
                DB::transaction(fn () => DB::table('family_registry_admissions')->insert($this->admission($membership, $user, $head->person_id, $override)));
                $this->fail('A duplicate evidence row was accepted.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, DB::table('family_registry_admissions')->count());
    }

    public function test_the_migration_rolls_back_when_empty_and_refuses_with_history(): void
    {
        $migration = require self::MIGRATION;

        DB::transaction(fn () => $migration->down());
        $this->assertFalse(Schema::hasTable('family_registry_admissions'));
        $this->assertFalse(Schema::hasTable('family_registry_admission_events'));
        DB::transaction(fn () => $migration->up());
        $this->assertTrue(Schema::hasTable('family_registry_admissions'));

        $head = FamilyMembership::factory()->householdHead()->create();
        DB::table('family_registry_admission_events')->insert([
            'event_type' => 'DISCOVERY_LISTED', 'actor_user_id' => User::factory()->familySide()->create()->id,
            'family_id' => $head->family_id, 'outcome_code' => 'LISTED', 'result_count' => 0, 'created_at' => now(),
        ]);
        try {
            $migration->down();
            $this->fail('The rollback ran with history.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('history exists', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('family_registry_admission_events'));
        $this->assertSame(1, DB::table('family_registry_admission_events')->count());
    }
}
