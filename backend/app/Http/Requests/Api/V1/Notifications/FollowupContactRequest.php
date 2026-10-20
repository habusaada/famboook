<?php

namespace App\Http\Requests\Api\V1\Notifications;

use App\Enums\NotificationFollowupContactOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a contact (FB-NOTIFY-01E.3-F3.2): a contact-outcome code, an
 * optional internal note (≤ 1000 characters) and a REQUIRED client_reference
 * uuid (idempotency). RecordFollowupContactAction validates again and is the
 * authority.
 */
class FollowupContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', Rule::enum(NotificationFollowupContactOutcome::class)],
            'note' => ['nullable', 'string', 'max:1000'],
            'client_reference' => ['required', 'uuid'],
        ];
    }
}
