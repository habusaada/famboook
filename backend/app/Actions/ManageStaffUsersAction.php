<?php

namespace App\Actions;

use App\Models\User;
use App\Support\StaffRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Staff user administration (docs/06 §59c, AUTH-ADR-057), used by the
 * Filament Staff Users resource. Every rule lives here, not in the form,
 * so it holds whatever the caller:
 *
 * - a Staff user holds exactly one Staff role; FAMILY_USER is never
 *   assignable and FAMILY_USER accounts are not managed here;
 * - user.create / user.update / user.activate / user.suspend /
 *   user.reset-access gate each operation, role.assign gates setting a role;
 * - only a SUPER_ADMIN may assign SUPER_ADMIN or ADMINISTRATOR, or manage
 *   a user who holds one (no escalation, no administrator-on-administrator);
 * - nobody changes their own role or deactivates themselves;
 * - the last active SUPER_ADMIN cannot be deactivated or demoted.
 *
 * Passwords are write-only: hashed by the model cast, never returned or
 * logged. Audit is an application-log line without credential values.
 */
class ManageStaffUsersAction
{
    public const PASSWORD_MIN = 10;

    /** @return list<string> Staff roles $actor may assign. */
    public static function assignableRoles(User $actor): array
    {
        if (! $actor->can('role.assign')) {
            return [];
        }

        return $actor->hasRole(StaffRoles::SUPER_ADMIN)
            ? StaffRoles::ALL
            : array_values(array_diff(StaffRoles::ALL, StaffRoles::PRIVILEGED));
    }

    /** Whether $actor may administer $target at all (role hierarchy). */
    public static function canManage(User $actor, User $target): bool
    {
        if ($target->hasRole('FAMILY_USER')) {
            return false;
        }
        if ($target->hasAnyRole(StaffRoles::PRIVILEGED)) {
            return $actor->hasRole(StaffRoles::SUPER_ADMIN);
        }

        return true;
    }

    /** @param array{name: string, email: string, role: string, password: string, is_active?: bool} $data */
    public function create(User $actor, array $data): User
    {
        $this->authorize($actor->can('user.create') && $actor->can('role.assign'));
        $data = $this->validate($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(self::assignableRoles($actor))],
            'password' => ['required', 'string', Password::min(self::PASSWORD_MIN)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return DB::transaction(function () use ($actor, $data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);
            $user->syncRoles([$data['role']]);
            $this->audit('staff_user.created', $actor, $user, ['role' => $data['role'], 'is_active' => $user->is_active]);

            return $user;
        });
    }

    /** @param array{name?: string, email?: string, role?: string} $data */
    public function update(User $actor, User $user, array $data): User
    {
        $this->authorize($actor->can('user.update') && self::canManage($actor, $user));
        $data = $this->validate($data, [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', 'required', 'string'],
        ]);

        return DB::transaction(function () use ($actor, $user, $data) {
            $user->fill(array_intersect_key($data, array_flip(['name', 'email'])))->save();

            $current = $user->getRoleNames()->first();
            if (isset($data['role']) && $data['role'] !== $current) {
                $this->authorize(in_array($data['role'], self::assignableRoles($actor), true), 'role');
                $this->guard($actor->isNot($user), 'role', 'لا يمكنك تغيير دورك بنفسك.');
                $this->guard(! $this->isLastActiveSuperAdmin($user), 'role', 'لا يمكن تغيير دور آخر مدير نظام نشط.');
                $user->syncRoles([$data['role']]);
                $this->audit('staff_user.role_changed', $actor, $user, ['from' => $current, 'to' => $data['role']]);
            } elseif ($user->wasChanged(['name', 'email'])) {
                $this->audit('staff_user.updated', $actor, $user, ['fields' => array_keys($user->getChanges())]);
            }

            return $user;
        });
    }

    public function setActive(User $actor, User $user, bool $active): User
    {
        $this->authorize($actor->can($active ? 'user.activate' : 'user.suspend') && self::canManage($actor, $user));
        if (! $active) {
            $this->guard($actor->isNot($user), 'is_active', 'لا يمكنك إيقاف حسابك بنفسك.');
            $this->guard(! $this->isLastActiveSuperAdmin($user), 'is_active', 'لا يمكن إيقاف آخر مدير نظام نشط.');
        }

        return DB::transaction(function () use ($actor, $user, $active) {
            if ($user->is_active !== $active) {
                $user->forceFill(['is_active' => $active])->save();
                $this->audit($active ? 'staff_user.activated' : 'staff_user.deactivated', $actor, $user);
            }

            return $user;
        });
    }

    /** Sets a new temporary password; the user's other sessions end. */
    public function resetPassword(User $actor, User $user, string $password): User
    {
        $this->authorize($actor->can('user.reset-access') && self::canManage($actor, $user));
        $this->validate(['password' => $password], ['password' => ['required', 'string', Password::min(self::PASSWORD_MIN)]]);

        return DB::transaction(function () use ($actor, $user, $password) {
            $user->forceFill(['password' => $password, 'remember_token' => null])->save();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
            $this->audit('staff_user.password_reset', $actor, $user);

            return $user;
        });
    }

    private function isLastActiveSuperAdmin(User $user): bool
    {
        if (! $user->hasRole(StaffRoles::SUPER_ADMIN) || ! $user->is_active) {
            return false;
        }

        return User::role(StaffRoles::SUPER_ADMIN)->where('is_active', true)->whereKeyNot($user->id)->doesntExist();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $data, array $rules): array
    {
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower(trim($data['email']));
        }

        return Validator::make($data, $rules, [
            'email.unique' => 'البريد الإلكتروني مستخدم لحساب آخر.',
            'role.in' => 'لا يمكنك إسناد هذا الدور.',
            'password.min' => 'كلمة المرور يجب ألا تقل عن :min أحرف.',
        ])->validate();
    }

    private function authorize(bool $allowed, ?string $field = null): void
    {
        if (! $allowed) {
            throw new AuthorizationException($field === 'role' ? 'لا يمكنك إسناد هذا الدور.' : 'لا تملك صلاحية تنفيذ هذا الإجراء.');
        }
    }

    private function guard(bool $ok, string $field, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /** @param array<string, mixed> $context never credential values */
    private function audit(string $event, User $actor, User $target, array $context = []): void
    {
        Log::info($event, ['actor_user_id' => $actor->id, 'target_user_id' => $target->id, ...$context]);
    }
}
