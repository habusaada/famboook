<?php

namespace App\Http\Requests\Api\V1\Notifications;

use App\Enums\NotificationLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new announcement draft (FB-NOTIFY-01B.2b.6c): the allow-listed keys and
 * their shape. CreateAnnouncementDraftAction is the authoritative validation
 * (trimmed lengths, future expiry, the audience grammar, eligibility and
 * scope); nothing else — kind, side, sender, scope, status — is ever read
 * from the request.
 */
class StoreAnnouncementRequest extends FormRequest
{
    use ChecksGlobalAudience;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:5000'],
            'level' => ['sometimes', Rule::enum(NotificationLevel::class)],
            // The whole object goes to the domain (NotificationAudience parses it
            // strictly): no nested rule here, or validated() would drop its keys.
            'audience' => ['required', 'array'],
            'expires_at' => ['nullable', 'date'],
            'client_reference' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, mixed> the allow-listed input for the Domain Action */
    public function draftInput(): array
    {
        return $this->safe()->only(['title', 'body', 'level', 'audience', 'expires_at', 'client_reference']);
    }
}
