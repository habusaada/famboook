<?php

namespace Tests\Feature\Notifications;

use App\Console\Commands\VerifyPermissions;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Family;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\NotificationActionFixtures;
use Tests\TestCase;

/**
 * FB-NOTIFY-01B.2b.6d: the notification permissions in the canonical RBAC
 * baseline (AUTH-ADR-097) — announcement.view / .create / .send for
 * SUPER_ADMIN and ADMINISTRATOR, announcement.send-all for SUPER_ADMIN only,
 * coordinator-announcement.view / .create / .send for the existing
 * COORDINATOR role only. No role is created. Everything here uses the
 * SEEDED roles — never a direct grant — so it proves the baseline itself,
 * and famboook:verify-permissions detects every deviation. Synthetic data
 * only.
 */
class NotificationPermissionsTest extends TestCase
{
    use NotificationActionFixtures, RefreshDatabase;

    private const ANNOUNCEMENT = ['announcement.view', 'announcement.create', 'announcement.send'];

    private const COORDINATOR = ['coordinator-announcement.view', 'coordinator-announcement.create', 'coordinator-announcement.send'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNotificationActions();
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'web'));

        return $user;
    }

    private function staffJson(User $user, string $method, string $uri = '', array $body = []): TestResponse
    {
        return $this->actingAs($user->fresh())->json($method, '/api/v1/notifications'.$uri, $body);
    }

    private function coordinatorJson(User $user, string $method, string $uri = '', array $body = []): TestResponse
    {
        return $this->actingAs($user->fresh())->json($method, '/api/v1/family/coordinator/notifications'.$uri, $body);
    }

    // ------------------------------------------------------------ inventory

    public function test_the_catalog_and_the_grants_are_exactly_the_approved_matrix(): void
    {
        foreach ([...self::ANNOUNCEMENT, 'announcement.send-all', ...self::COORDINATOR] as $permission) {
            $this->assertContains($permission, RolePermissionSeeder::PERMISSIONS);
            $this->assertSame(1, Permission::query()->where('name', $permission)->count(), $permission);
        }
        $this->assertSame([], array_values(array_filter(RolePermissionSeeder::PERMISSIONS, fn ($p) => str_contains($p, 'notification-followup'))),
            'follow-up permissions stay unseeded');

        $expected = [
            'SUPER_ADMIN' => [...self::ANNOUNCEMENT, 'announcement.send-all'],
            'ADMINISTRATOR' => self::ANNOUNCEMENT,
            'COORDINATOR' => self::COORDINATOR,
        ];
        foreach (RolePermissionSeeder::ROLES as $role) {
            $held = array_values(array_filter(Role::findByName($role, 'web')->permissions->pluck('name')->all(),
                fn ($p) => str_starts_with($p, 'announcement.') || str_starts_with($p, 'coordinator-announcement.')));
            $this->assertEqualsCanonicalizing($expected[$role] ?? [], $held, $role);
        }
    }

    public function test_no_role_is_created_and_seeding_is_idempotent(): void
    {
        $this->assertSame(['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER', 'FAMILY_USER', 'COORDINATOR'],
            RolePermissionSeeder::ROLES);
        $snapshot = fn () => [
            Role::query()->count(), Permission::query()->count(),
            DB::table(config('permission.table_names.role_has_permissions'))->count(),
        ];
        $before = $snapshot();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame($before, $snapshot());
        $this->assertSame(8, Role::query()->count());
        $this->artisan('famboook:verify-permissions')->assertExitCode(0);
    }

    // ------------------------------------------------------------- verifier

    public function test_the_verifier_detects_every_notification_deviation(): void
    {
        $deviations = [
            'ADMINISTRATOR with send-all' => [fn () => Role::findByName('ADMINISTRATOR', 'web')->givePermissionTo('announcement.send-all'),
                'CRITICAL: ADMINISTRATOR must NOT have announcement.send-all'],
            'SUPER_ADMIN without send-all' => [fn () => Role::findByName('SUPER_ADMIN', 'web')->revokePermissionTo('announcement.send-all'),
                'CRITICAL: SUPER_ADMIN must have announcement.send-all'],
            'ADMINISTRATOR without send' => [fn () => Role::findByName('ADMINISTRATOR', 'web')->revokePermissionTo('announcement.send'),
                'CRITICAL: ADMINISTRATOR must have announcement.send'],
            'FAMILY_USER as a coordinator sender' => [fn () => Role::findByName('FAMILY_USER', 'web')->givePermissionTo('coordinator-announcement.create'),
                'CRITICAL: FAMILY_USER must NOT have coordinator-announcement.create'],
            'COORDINATOR with a Staff permission' => [fn () => Role::findByName('COORDINATOR', 'web')->givePermissionTo('announcement.view'),
                'CRITICAL: COORDINATOR must NOT have announcement.view'],
            'COORDINATOR without its send' => [fn () => Role::findByName('COORDINATOR', 'web')->revokePermissionTo('coordinator-announcement.send'),
                'CRITICAL: COORDINATOR must have coordinator-announcement.send'],
            'REVIEWER with a Staff permission' => [fn () => Role::findByName('REVIEWER', 'web')->givePermissionTo('announcement.create'),
                'CRITICAL: REVIEWER must NOT have announcement.create'],
            'send-all granted directly' => [fn () => User::factory()->create()->givePermissionTo('announcement.send-all'),
                'CRITICAL: 1 notification permission grant(s) made directly to a model'],
            'a missing permission' => [fn () => Permission::findByName('coordinator-announcement.view', 'web')->delete(),
                'permission missing: coordinator-announcement.view'],
        ];

        foreach ($deviations as $what => [$deviate, $message]) {
            $this->seed(RolePermissionSeeder::class);
            DB::table(config('permission.table_names.model_has_permissions'))->delete();
            $this->artisan('famboook:verify-permissions')->assertExitCode(0);

            $deviate();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->artisan('famboook:verify-permissions')->expectsOutputToContain($message)->assertExitCode(1);
        }
        $this->assertSame(VerifyPermissions::COORDINATOR_ANNOUNCEMENT, self::COORDINATOR);
    }

    // --------------------------------------------------- the seeded roles at work

    public function test_super_admin_may_send_to_every_active_family_and_administrator_may_not(): void
    {
        Family::factory()->create();
        $global = ['title' => 'إعلان عام', 'body' => 'نص.', 'audience' => ['type' => 'STAFF_ALL_ACTIVE']];
        $superAdmin = $this->withRole('SUPER_ADMIN');
        $administrator = $this->withRole('ADMINISTRATOR');

        $this->staffJson($superAdmin, 'POST', '/audience-preview', ['audience' => ['type' => 'STAFF_ALL_ACTIVE']])->assertOk();
        $uuid = $this->staffJson($superAdmin, 'POST', '', $global)->assertCreated()->json('data.id');
        $this->staffJson($superAdmin, 'POST', "/{$uuid}/publish")->assertStatus(202);

        $this->staffJson($administrator, 'POST', '', $global)->assertForbidden();
        $this->staffJson($administrator, 'POST', '/audience-preview', ['audience' => ['type' => 'STAFF_ALL_ACTIVE']])->assertForbidden();
        // Selected families: yes.
        $this->staffJson($administrator, 'POST', '', [...$global, 'audience' => ['type' => 'STAFF_FAMILIES', 'family_ids' => [Family::factory()->create()->id]]])
            ->assertCreated();
    }

    public function test_super_admin_never_bypasses_sender_ownership(): void
    {
        $administrator = $this->withRole('ADMINISTRATOR');
        $superAdmin = $this->withRole('SUPER_ADMIN');
        $uuid = $this->staffJson($administrator, 'POST', '', [
            'title' => 'إعلان', 'body' => 'نص.', 'audience' => ['type' => 'STAFF_FAMILIES', 'family_ids' => [Family::factory()->create()->id]],
        ])->assertCreated()->json('data.id');

        $this->staffJson($superAdmin, 'GET', "/{$uuid}")->assertNotFound();
        foreach (['publish', 'cancel', 'retry'] as $action) {
            $this->staffJson($superAdmin, 'POST', "/{$uuid}/{$action}")->assertNotFound();
        }
        $this->assertSame([], array_column($this->staffJson($superAdmin, 'GET')->json('data'), 'id'));
    }

    public function test_other_staff_roles_and_every_family_side_account_are_refused_on_the_staff_api(): void
    {
        foreach (['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $this->staffJson($this->withRole($role), 'GET')->assertForbidden();
        }
        $coordinator = $this->coordinator('111111110');
        $this->assign($coordinator, $this->clan('NTF_CLAN'));
        $this->staffJson($coordinator, 'GET')->assertForbidden();
        $this->staffJson($this->activatedHead('222222220')['user'], 'GET')->assertForbidden();
    }

    public function test_only_the_coordinator_role_with_a_current_scope_operates_and_the_history_follows_the_role(): void
    {
        $clan = $this->clan('NTF_CLAN');
        $this->familyIn($clan);
        $coordinator = $this->coordinator('111111110');
        $this->assign($coordinator, $clan);
        $head = $this->activatedHead('222222220')['user'];
        $draft = ['title' => 'إعلان المنسق', 'body' => 'نص.', 'audience' => ['type' => 'COORDINATOR_SCOPE']];

        // An ordinary household head: no coordinator announcement access at all.
        $this->coordinatorJson($head, 'GET')->assertForbidden();
        $this->coordinatorJson($head, 'POST', '', $draft)->assertForbidden();
        // Staff accounts never reach the family-side routes.
        $this->coordinatorJson($this->withRole('SUPER_ADMIN'), 'GET')->assertForbidden();

        // The seeded COORDINATOR role, with a current scope.
        $uuid = $this->coordinatorJson($coordinator, 'POST', '', $draft)->assertCreated()->json('data.id');
        $this->coordinatorJson($coordinator, 'POST', '/audience-preview', ['audience' => ['type' => 'COORDINATOR_SCOPE']])->assertOk();
        $failed = $this->coordinatorJson($coordinator, 'POST', '', $draft)->assertCreated()->json('data.id');

        // Scope revoked: no operation; own history and withdrawal remain.
        CoordinatorScopeAssignment::query()->where('user_id', $coordinator->id)
            ->update(['revoked_at' => now(), 'revoked_by' => $this->withRole('ADMINISTRATOR')->id, 'revoke_reason' => 'ADMINISTRATIVE']);
        foreach ([['POST', '', $draft], ['POST', '/audience-preview', $draft], ['POST', "/{$uuid}/publish", []], ['POST', "/{$failed}/retry", []]] as [$method, $uri, $body]) {
            $this->coordinatorJson($coordinator, $method, $uri, $body)->assertForbidden()->assertJson(['code' => 'COORDINATOR_SPACE_UNAVAILABLE']);
        }
        $this->coordinatorJson($coordinator, 'GET')->assertOk();
        $this->coordinatorJson($coordinator, 'GET', "/{$uuid}")->assertOk()->assertJson(['data' => ['statistics_basis' => null]]);
        $this->coordinatorJson($coordinator, 'POST', "/{$uuid}/cancel")->assertOk()->assertJson(['data' => ['status' => 'CANCELLED']]);

        // The COORDINATOR role itself removed: the history and withdrawal end too.
        $coordinator->removeRole('COORDINATOR');
        $this->coordinatorJson($coordinator, 'GET')->assertForbidden();
        $this->coordinatorJson($coordinator, 'POST', "/{$failed}/cancel")->assertForbidden();
    }
}
