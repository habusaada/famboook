<?php

namespace App\Http\Resources;

use App\Models\NotificationFollowup;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One follow-up in a list (FB-NOTIFY-01E.3-F3.2; ADR FB-NOTIFY-01 §2f), read
 * through FollowupInbox. Public fields only: the follow-up uuid, its state, the
 * message's notice code / level / title / expiry, the family code and the
 * household head's display name. Never an internal id, a user, an
 * assignment, a phone number, a National ID or a note. On the coordinator
 * side `can_manage` says whether the reader is the family's CURRENT primary
 * coordinator (set-based, FollowupInbox).
 *
 * @mixin NotificationFollowup
 */
class FollowupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $followup = $this->resource;
        $attributes = $followup->getAttributes();

        return [
            'id' => $followup->uuid,
            'stage' => $followup->stage->value,
            'trigger_reason' => $followup->trigger_reason->value,
            'status' => $followup->status->value,
            'outcome' => $followup->outcome?->value,
            'close_reason' => $followup->close_reason_code,
            'due_at' => $followup->due_at?->toIso8601String(),
            'opened_at' => $followup->created_at?->toIso8601String(),
            'closed_at' => $followup->closed_at?->toIso8601String(),
            'message' => [
                'notice_code' => $attributes['message_notice_code'] ?? null,
                'level' => $attributes['message_level'] ?? null,
                'title' => $attributes['message_title'] ?? null,
                'expires_at' => self::iso($attributes['message_expires_at'] ?? null),
            ],
            'family' => [
                'family_code' => $attributes['family_code'] ?? null,
                'head_name' => $attributes['head_name'] ?? null,
            ],
            ...(array_key_exists('is_primary', $attributes) ? ['can_manage' => (bool) $attributes['is_primary']] : []),
        ];
    }

    protected static function iso(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->toIso8601String();
    }
}
