<?php

namespace App\Filament\Resources\Users\Pages;

use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Runs a ManageStaffUsersAction operation from a Filament page: rule
 * violations appear under the matching form field, authorization
 * failures as a notification; either way nothing is saved.
 */
trait RunsStaffUserAction
{
    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    protected function runStaffUserAction(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($messages, $field) => ["data.{$field}" => $messages])->all()
            );
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }
}
