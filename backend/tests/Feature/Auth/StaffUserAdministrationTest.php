<?php

namespace Tests\Feature\Auth;

use App\Actions\ManageStaffUsersAction;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Staff user administration in Filament (docs/06 §59c, AUTH-ADR-057):
 * who may enter the panel, what each admin may do, one Staff role per
 * user, no FAMILY_USER, no privilege escalation, last-SUPER_ADMIN guard.
 */
class StaffUserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'synthetic-pass-123';

    private ManageStaffUsersAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->action = app(ManageStaffUsersAction::class);
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, mixed> */
    private function data(string $role = 'DATA_ENTRY', string $email = 'new.staff@example.test'): array
    {
        return ['name' => 'موظف تجريبي', 'email' => $email, 'role' => $role, 'password' => self::PASSWORD];
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the operation to be refused.');
        } catch (AuthorizationException|ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    // ------------------------------------------------------------- panel

    public function test_only_active_administrators_can_enter_filament(): void
    {
        $this->get('/admin/users')->assertRedirect('/admin/login');

        $this->actingAs($this->user('ADMINISTRATOR'))->get('/admin/users')->assertOk();
        $this->actingAs($this->user('SUPER_ADMIN'))->get('/admin/users')->assertOk();

        foreach (['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $this->actingAs($this->user($role))->get('/admin/users')->assertForbidden();
        }

        $inactive = $this->user('SUPER_ADMIN', ['is_active' => false]);
        $this->actingAs($inactive)->get('/admin/users')->assertRedirect('/admin/login');
        $this->assertGuest('web');
    }

    public function test_the_list_shows_staff_users_but_not_family_users(): void
    {
        $admin = $this->user('ADMINISTRATOR');
        $staff = $this->user('DATA_ENTRY', ['name' => 'مدخل ظاهر']);
        $family = $this->user('FAMILY_USER', ['name' => 'مستخدم أسرة مخفي']);

        $this->actingAs($admin);
        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$admin, $staff])
            ->assertCanNotSeeTableRecords([$family]);
    }

    // ------------------------------------------------------ create / edit

    public function test_an_administrator_creates_a_staff_user_with_one_role_and_a_hashed_password(): void
    {
        $admin = $this->user('ADMINISTRATOR');
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm($this->data('DATA_ENTRY', 'Form.Created@Example.test'))
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'form.created@example.test')->sole();
        $this->assertSame(['DATA_ENTRY'], $created->getRoleNames()->all());
        $this->assertTrue($created->is_active);
        $this->assertNotSame(self::PASSWORD, $created->getRawOriginal('password'));
        $this->assertTrue(Hash::check(self::PASSWORD, $created->password));
    }

    public function test_family_user_and_privileged_roles_are_not_assignable_by_an_administrator(): void
    {
        $admin = $this->user('ADMINISTRATOR');
        $this->assertSame(['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'], ManageStaffUsersAction::assignableRoles($admin));
        $this->assertNotContains('FAMILY_USER', ManageStaffUsersAction::assignableRoles($this->user('SUPER_ADMIN')));

        foreach (['FAMILY_USER', 'SUPER_ADMIN', 'ADMINISTRATOR'] as $role) {
            $this->assertDenied(fn () => $this->action->create($admin, $this->data($role, strtolower($role).'@example.test')));
        }
        $this->actingAs($admin);
        Livewire::test(CreateUser::class)
            ->fillForm($this->data('FAMILY_USER', 'family@example.test'))
            ->call('create')
            ->assertHasFormErrors(['role']);
        $this->assertDatabaseMissing('users', ['email' => 'family@example.test']);

        // SUPER_ADMIN may create any Staff role — but never FAMILY_USER.
        $super = $this->user('SUPER_ADMIN');
        $this->assertTrue($this->action->create($super, $this->data('ADMINISTRATOR', 'admin2@example.test'))->hasRole('ADMINISTRATOR'));
        $this->assertDenied(fn () => $this->action->create($super, $this->data('FAMILY_USER', 'fu@example.test')));
    }

    public function test_edit_changes_details_and_role_and_keeps_a_blank_password(): void
    {
        $admin = $this->user('ADMINISTRATOR');
        $target = $this->user('DATA_ENTRY', ['password' => self::PASSWORD]);
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->assertFormSet(['password' => null, 'role' => 'DATA_ENTRY'])
            ->fillForm(['name' => 'اسم معدّل', 'role' => 'REVIEWER', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $target->refresh();
        $this->assertSame('اسم معدّل', $target->name);
        $this->assertSame(['REVIEWER'], $target->getRoleNames()->all());
        $this->assertTrue(Hash::check(self::PASSWORD, $target->password), 'A blank password keeps the current one.');
    }

    // --------------------------------------------- activation / password

    public function test_deactivate_and_reactivate(): void
    {
        $admin = $this->user('ADMINISTRATOR');
        $target = $this->user('SOCIAL_WORKER');

        $this->action->setActive($admin, $target, false);
        $this->assertFalse($target->fresh()->is_active);
        $this->action->setActive($admin, $target, true);
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_password_reset_replaces_the_password_and_ends_sessions(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->user('ADMINISTRATOR');
        $target = $this->user('DATA_ENTRY', ['password' => self::PASSWORD]);
        \DB::table('sessions')->insert(['id' => 'synthetic-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);

        $this->action->resetPassword($admin, $target, 'new-temporary-456');

        $target->refresh();
        $this->assertFalse(Hash::check(self::PASSWORD, $target->password));
        $this->assertTrue(Hash::check('new-temporary-456', $target->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDenied(fn () => $this->action->resetPassword($admin, $target, 'short'));
    }

    // ------------------------------------------------ escalation / limits

    public function test_users_without_user_permissions_cannot_manage_staff(): void
    {
        $target = $this->user('DATA_ENTRY');
        foreach (['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $actor = $this->user($role);
            $this->assertFalse($actor->can('viewAny', User::class));
            $this->assertDenied(fn () => $this->action->create($actor, $this->data('DATA_ENTRY', "{$role}@example.test")));
            $this->assertDenied(fn () => $this->action->update($actor, $target, ['name' => 'x']));
            $this->assertDenied(fn () => $this->action->setActive($actor, $target, false));
            $this->assertDenied(fn () => $this->action->resetPassword($actor, $target, 'new-temporary-456'));
        }
    }

    public function test_privilege_escalation_is_blocked(): void
    {
        $admin = $this->user('ADMINISTRATOR');
        $otherAdmin = $this->user('ADMINISTRATOR');
        $super = $this->user('SUPER_ADMIN');
        $staff = $this->user('DATA_ENTRY');

        // No self-promotion, no promoting others to privileged roles.
        $this->assertDenied(fn () => $this->action->update($admin, $admin, ['role' => 'SUPER_ADMIN']));
        $this->assertDenied(fn () => $this->action->update($admin, $staff, ['role' => 'ADMINISTRATOR']));
        $this->assertDenied(fn () => $this->action->update($admin, $staff, ['role' => 'FAMILY_USER']));
        // No managing SUPER_ADMINs or other ADMINISTRATORs.
        foreach ([$super, $otherAdmin] as $privileged) {
            $this->assertFalse($admin->can('update', $privileged));
            $this->assertDenied(fn () => $this->action->update($admin, $privileged, ['role' => 'DATA_ENTRY']));
            $this->assertDenied(fn () => $this->action->setActive($admin, $privileged, false));
            $this->assertDenied(fn () => $this->action->resetPassword($admin, $privileged, 'new-temporary-456'));
        }
        $this->assertSame(['DATA_ENTRY'], $staff->fresh()->getRoleNames()->all());
        $this->assertTrue($super->fresh()->is_active);

        // Nobody changes their own role or deactivates themselves.
        $this->assertDenied(fn () => $this->action->update($super, $super, ['role' => 'DATA_ENTRY']));
        $this->assertFalse($super->can('deactivate', $super));
    }

    public function test_the_last_active_super_admin_cannot_be_deactivated_or_demoted(): void
    {
        $last = $this->user('SUPER_ADMIN');
        $second = $this->user('SUPER_ADMIN');

        // Two active: one may be deactivated by the other.
        $this->action->setActive($last, $second, false);
        $this->assertFalse($second->fresh()->is_active);

        // Now $last is the only active SUPER_ADMIN: $second (reactivated
        // later) cannot remove them — simulate an actor that could.
        $this->action->setActive($last, $second, true);
        $this->action->setActive($second, $last, false);
        $this->assertDenied(fn () => $this->action->setActive($last->fresh(), $second->fresh(), false));
        $this->assertDenied(fn () => $this->action->update($last->fresh(), $second->fresh(), ['role' => 'DATA_ENTRY']));
        $this->assertTrue($second->fresh()->is_active);
        $this->assertTrue($second->fresh()->hasRole('SUPER_ADMIN'));
    }

    public function test_an_inactive_administrator_is_logged_out_of_filament_on_the_next_request(): void
    {
        $admin = $this->user('ADMINISTRATOR');
        $this->actingAs($admin)->get('/admin/users')->assertOk();

        User::whereKey($admin->id)->update(['is_active' => false]);
        Auth::guard('web')->setUser($admin->fresh());

        $this->get('/admin/users')->assertRedirect('/admin/login');
        $this->assertGuest('web');
    }
}
