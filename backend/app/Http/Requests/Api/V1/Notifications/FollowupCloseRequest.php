<?php

namespace App\Http\Requests\Api\V1\Notifications;

use App\Enums\NotificationFollowupCloseReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Close a follow-up manually (FB-NOTIFY-01E.3-F3.2): a closure-reason code
 * and an optional internal note (≤ 1000 characters). CloseFollowupManually-
 * Action validates again and is the authority; a second closure is
 * FOLLOWUP_NOT_OPEN (closing is idempotent by state).
 */
class FollowupCloseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::enum(NotificationFollowupCloseReason::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
