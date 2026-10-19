<?php

namespace App\Http\Requests\Api\V1\Notifications;

use App\Enums\NotificationAudienceType;
use App\Support\Notifications\NotificationAudienceResolver;

/**
 * The HTTP-layer half of the send-all rule (FB-NOTIFY-01B.2b.6c): a request
 * for every ACTIVE family needs announcement.send-all before anything else.
 * The domain (NotificationAudienceResolver) enforces it again — at the
 * draft, the publication request and in the worker's T2.
 */
trait ChecksGlobalAudience
{
    public function authorize(): bool
    {
        $type = $this->input('audience.type');

        return $type !== NotificationAudienceType::STAFF_ALL_ACTIVE->value
            || $this->user()?->can(NotificationAudienceResolver::SEND_ALL_PERMISSION) === true;
    }
}
