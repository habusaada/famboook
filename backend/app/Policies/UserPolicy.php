<?php

namespace App\Policies;

use App\Actions\ManageStaffUsersAction;
use App\Models\User;

/**
 * Staff user administration (docs/06 §59c, AUTH-ADR-057), consulted by
 * the Filament resource. The operations themselves re-check everything in
 * ManageStaffUsersAction. Users are never deleted: deactivate instead.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('user.view');
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->can('user.view') && ManageStaffUsersAction::canManage($actor, $user);
    }

    public function create(User $actor): bool
    {
        return $actor->can('user.create') && ManageStaffUsersAction::assignableRoles($actor) !== [];
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->can('user.update') && ManageStaffUsersAction::canManage($actor, $user);
    }

    public function activate(User $actor, User $user): bool
    {
        return ! $user->is_active && $actor->can('user.activate') && ManageStaffUsersAction::canManage($actor, $user);
    }

    public function deactivate(User $actor, User $user): bool
    {
        return $user->is_active && $actor->isNot($user)
            && $actor->can('user.suspend') && ManageStaffUsersAction::canManage($actor, $user);
    }

    public function resetPassword(User $actor, User $user): bool
    {
        return $actor->can('user.reset-access') && ManageStaffUsersAction::canManage($actor, $user);
    }

    public function delete(User $actor, User $user): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
