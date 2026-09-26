<?php

namespace App\Filament\Resources\Users\Tables;

use App\Actions\ManageStaffUsersAction;
use App\Models\User;
use App\Support\StaffRoles;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Staff users: name, email, role, active state. Row actions follow
 * UserPolicy and run through ManageStaffUsersAction. No delete or bulk
 * actions.
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable()->sortable(),
                TextColumn::make('email')->label('البريد الإلكتروني')->searchable(),
                TextColumn::make('role')
                    ->label('الدور')
                    ->badge()
                    ->state(fn (User $record) => $record->getRoleNames()->first())
                    ->formatStateUsing(fn (?string $state) => StaffRoles::LABELS[$state] ?? 'بلا دور'),
                IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('حالة الحساب')
                    ->trueLabel('نشط')->falseLabel('موقوف'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('deactivate')
                    ->label('إيقاف')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('سيُمنع المستخدم من الدخول وتنتهي جلسته الحالية عند طلبه التالي.')
                    ->visible(fn (User $record) => Auth::user()->can('deactivate', $record))
                    ->action(fn (User $record) => self::run(
                        fn () => app(ManageStaffUsersAction::class)->setActive(Auth::user(), $record, false),
                        'تم إيقاف الحساب.',
                    )),
                Action::make('activate')
                    ->label('تفعيل')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => Auth::user()->can('activate', $record))
                    ->action(fn (User $record) => self::run(
                        fn () => app(ManageStaffUsersAction::class)->setActive(Auth::user(), $record, true),
                        'تم تفعيل الحساب.',
                    )),
                Action::make('resetPassword')
                    ->label('كلمة مرور مؤقتة')
                    ->icon('heroicon-o-key')
                    ->visible(fn (User $record) => Auth::user()->can('resetPassword', $record))
                    ->modalDescription('تُستبدل كلمة المرور الحالية وتنتهي جلسات المستخدم. أبلغه بكلمة المرور الجديدة بطريقة آمنة.')
                    ->schema([
                        TextInput::make('password')
                            ->label('كلمة المرور المؤقتة الجديدة')
                            ->password()
                            ->autocomplete('new-password')
                            ->required()
                            ->minLength(ManageStaffUsersAction::PASSWORD_MIN),
                    ])
                    ->action(fn (User $record, array $data) => self::run(
                        fn () => app(ManageStaffUsersAction::class)->resetPassword(Auth::user(), $record, $data['password']),
                        'تم تعيين كلمة المرور المؤقتة.',
                    )),
            ]);
    }

    private static function run(callable $operation, string $success): void
    {
        try {
            $operation();
            Notification::make()->success()->title($success)->send();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
