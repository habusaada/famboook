<?php

namespace App\Http\Resources;

use App\Models\NotificationFollowup;
use Illuminate\Http\Request;

/**
 * One follow-up in detail (FB-NOTIFY-01E.3-F3.2): the list fields, the
 * recipient's state (viewed / acknowledged timestamps, late flag, the
 * publication-time account flag), the number of recorded contacts, and the
 * side's management information — Staff: the ownership KIND only
 * (PRIMARY_COORDINATOR or CENTRAL, never who); a coordinator: `can_manage`.
 * Still no internal id, user, assignment, contact data or note.
 *
 * @mixin NotificationFollowup
 */
class FollowupDetailResource extends FollowupResource
{
    /** @param  array<string, mixed>  $extra  computed by the controller */
    public function __construct(NotificationFollowup $resource, private readonly array $extra = [])
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();

        return [
            ...parent::toArray($request),
            'recipient' => [
                'viewed_at' => self::iso($attributes['recipient_viewed_at'] ?? null),
                'acknowledged_at' => self::iso($attributes['recipient_acknowledged_at'] ?? null),
                'acknowledged_late' => (bool) ($attributes['recipient_acknowledged_late'] ?? false),
                'had_family_account' => (bool) ($attributes['recipient_had_family_account'] ?? false),
            ],
            ...$this->extra,
        ];
    }
}
