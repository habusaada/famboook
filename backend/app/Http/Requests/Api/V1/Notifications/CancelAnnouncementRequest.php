<?php

namespace App\Http\Requests\Api\V1\Notifications;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Withdrawing an announcement (FB-NOTIFY-01B.2b.6c): an optional reason
 * CODE only — CancelPublicationAction validates its format.
 */
class CancelAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason_code' => ['nullable', 'string', 'max:50'],
        ];
    }
}
