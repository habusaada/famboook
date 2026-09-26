<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\ManageStaffUsersAction;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Edits name / email / role; a filled password field sets a new temporary
 * password (blank keeps the current one). Activation has its own table
 * actions. No delete.
 */
class EditUser extends EditRecord
{
    use RunsStaffUserAction;

    protected static string $resource = UserResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->getRecord();

        return [...$data, 'role' => $user->getRoleNames()->first(), 'password' => null];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $action = app(ManageStaffUsersAction::class);

        return $this->runStaffUserAction(fn () => DB::transaction(function () use ($action, $record, $data) {
            $changes = array_intersect_key($data, array_flip(['name', 'email', 'role']));
            $user = $action->update(Auth::user(), $record, $changes);
            if (filled($data['password'] ?? null)) {
                $action->resetPassword(Auth::user(), $user, $data['password']);
            }

            return $user;
        }));
    }
}
