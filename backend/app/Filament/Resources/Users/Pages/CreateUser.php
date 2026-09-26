<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\ManageStaffUsersAction;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    use RunsStaffUserAction;

    protected static string $resource = UserResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        return $this->runStaffUserAction(fn () => app(ManageStaffUsersAction::class)->create(Auth::user(), $data));
    }
}
