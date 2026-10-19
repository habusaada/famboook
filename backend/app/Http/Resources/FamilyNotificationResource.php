<?php

namespace App\Http\Resources;

use App\Enums\NotificationLevel;
use App\Enums\NotificationStatus;
use App\Models\NotificationRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One notification of the family's inbox or archive (FB-NOTIFY-01B.2b.6b),
 * built from the family's OWN recipient row. The summary has no body; the
 * detail (withBody) adds it.
 *
 * Exposed: the message's public uuid and notice code, kind, the family-visible
 * source label («إدارة Famboook» / «منسق العائلة» — never the sending user),
 * level, title, the family-relative action path, publication and expiry
 * times, this family's view / acknowledgement state and the actions it may
 * take. NEVER: an internal id, the sender, the sender's scope, the audience,
 * other recipients or counts, the head person, the family id, publication
 * internals (tokens, attempts, failures) or who viewed.
 *
 * @property NotificationRecipient $resource
 */
class FamilyNotificationResource extends JsonResource
{
    private bool $withBody = false;

    public function withBody(): self
    {
        $this->withBody = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $message = $this->resource->message;
        $expired = $message->status === NotificationStatus::ARCHIVED
            || ($message->expires_at !== null && $message->expires_at->getTimestamp() <= now()->getTimestamp());
        $important = $message->level === NotificationLevel::IMPORTANT;

        return [
            'id' => $message->uuid,
            'notice_code' => $message->notice_code,
            'kind' => $message->kind->value,
            'source' => $message->sender_side->familyLabel(),
            'level' => $message->level->value,
            'title' => $message->title,
            ...($this->withBody ? ['body' => $message->body] : []),
            'action_path' => $message->action_path,
            'published_at' => $message->published_at?->toIso8601String(),
            'expires_at' => $message->expires_at?->toIso8601String(),
            'expired' => $expired,
            'viewed_at' => $this->resource->viewed_at?->toIso8601String(),
            'requires_acknowledgement' => $important,
            'acknowledged_at' => $this->resource->acknowledged_at?->toIso8601String(),
            'acknowledged_late' => (bool) $this->resource->acknowledged_late,
            'available_actions' => array_values(array_filter([
                $this->resource->viewed_at === null ? 'VIEW' : null,
                $important && $this->resource->acknowledged_at === null ? 'ACKNOWLEDGE' : null,
            ])),
        ];
    }
}
