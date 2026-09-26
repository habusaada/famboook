<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Actions\ManageStaffUsersAction;
use App\Models\User;
use App\Support\StaffRoles;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * Staff user form. The password is write-only: never filled from the
 * record, required on create, and on edit a blank value keeps the current
 * password. The role list only offers roles the current admin may assign
 * (FAMILY_USER never). ManageStaffUsersAction re-validates everything.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('الاسم')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('البريد الإلكتروني')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('role')
                    ->label('الدور')
                    ->required()
                    ->native(false)
                    ->options(fn (?User $record) => self::roleOptions($record))
                    // Own role, or a role the admin may not assign: read-only.
                    ->disabled(fn (?User $record) => $record !== null && (
                        Auth::user()->is($record)
                        || ! in_array($record->getRoleNames()->first(), ManageStaffUsersAction::assignableRoles(Auth::user()), true)
                    )),
                TextInput::make('password')
                    ->label(fn (string $operation) => $operation === 'create' ? 'كلمة المرور المؤقتة' : 'كلمة مرور مؤقتة جديدة')
                    ->helperText(fn (string $operation) => $operation === 'create' ? null : 'اتركه فارغًا للإبقاء على كلمة المرور الحالية.')
                    ->password()
                    ->autocomplete('new-password')
                    ->minLength(ManageStaffUsersAction::PASSWORD_MIN)
                    ->required(fn (string $operation) => $operation === 'create')
                    ->visible(fn (string $operation, ?User $record) => $operation === 'create'
                        || ($record !== null && Auth::user()->can('resetPassword', $record)))
                    ->formatStateUsing(fn () => null)
                    ->dehydrated(fn (?string $state) => filled($state)),
                Toggle::make('is_active')
                    ->label('الحساب نشط')
                    ->default(true)
                    ->visibleOn('create'),
            ]);
    }

    /** @return array<string, string> */
    private static function roleOptions(?User $record): array
    {
        $roles = ManageStaffUsersAction::assignableRoles(Auth::user());
        // Keep the current (non-assignable) role displayable when read-only.
        if ($record !== null && ($current = $record->getRoleNames()->first()) !== null) {
            $roles = array_unique([...$roles, $current]);
        }

        return collect($roles)
            ->filter(fn (string $role) => StaffRoles::isStaff($role))
            ->mapWithKeys(fn (string $role) => [$role => StaffRoles::LABELS[$role]])
            ->all();
    }
}
