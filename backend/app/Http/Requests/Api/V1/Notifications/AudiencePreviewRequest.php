<?php

namespace App\Http\Requests\Api\V1\Notifications;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An audience to count before sending (FB-NOTIFY-01B.2b.6c; docs/11 §22).
 * The grammar, side, scope and eligibility are the resolver's.
 */
class AudiencePreviewRequest extends FormRequest
{
    use ChecksGlobalAudience;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The whole object goes to the domain (NotificationAudience parses it
            // strictly): no nested rule here, or validated() would drop its keys.
            'audience' => ['required', 'array'],
        ];
    }
}
