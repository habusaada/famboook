<?php

namespace App\Console\Commands;

use App\Actions\ManageStaffUsersAction;
use App\Models\User;
use App\Support\StaffRoles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Bootstraps a SUPER_ADMIN on a fresh installation (AUTH-ADR-057), where
 * no user exists yet to use Filament. Credentials are typed interactively
 * (the password is hidden) — never stored in source, seeders or history.
 * Further Staff users are managed in Filament.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'famboook:create-super-admin';

    protected $description = 'Create an active SUPER_ADMIN Staff user (interactive).';

    public function handle(): int
    {
        $data = [
            'name' => $this->ask('Name'),
            'email' => mb_strtolower(trim((string) $this->ask('Email'))),
            'password' => $this->secret('Password (min '.ManageStaffUsersAction::PASSWORD_MIN.' characters)'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(ManageStaffUsersAction::PASSWORD_MIN)],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($data) {
            $user = User::create([...$data, 'is_active' => true]);
            $user->syncRoles([StaffRoles::SUPER_ADMIN]);
        });
        $this->info("SUPER_ADMIN {$data['email']} created.");

        return self::SUCCESS;
    }
}
