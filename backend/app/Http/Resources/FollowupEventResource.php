<?php

namespace App\Http\Resources;

use App\Enums\WorkflowActorSide;
use App\Models\NotificationRecipientEvent;
use App\Support\Notifications\FollowupInbox;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One event of a follow-up's history (FB-NOTIFY-01E.3-F3.2): type, codes,
 * stage / outcome and time. The actor:
 *   Staff reader        the actor's side and display name;
 *   coordinator reader  a label only — SYSTEM, STAFF, YOU (the reader) or
 *                       COORDINATOR (another coordinator, never who).
 * The internal note only when the controller allows it for this reader, only
 * on a contact or closure, and never once a NOTE_CORRECTION corrected it.
 * Never an internal id, a user id or a client reference.
 *
 * @mixin NotificationRecipientEvent
 */
class FollowupEventResource extends JsonResource
{
    public function __construct(
        NotificationRecipientEvent $resource,
        private readonly bool $staffReader,
        private readonly ?int $readerId,
        private readonly bool $withNotes,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $event = $this->resource;
        $attributes = $event->getAttributes();
        $metadata = $event->metadata ?? [];
        $noteVisible = $this->withNotes && in_array($event->event_type, FollowupInbox::noteTypes(), true)
            && ! (bool) ($attributes['note_corrected'] ?? false);

        return [
            'type' => $event->event_type->value,
            'actor' => $this->staffReader
                ? ['side' => $event->actor_side->value, 'name' => $attributes['actor_name'] ?? null]
                : ['label' => $this->label($event)],
            'reason_code' => $event->reason_code,
            'stage' => $metadata['stage'] ?? null,
            'outcome' => $metadata['outcome'] ?? null,
            'note' => $noteVisible ? $attributes['internal_note'] : null,
            'occurred_at' => $event->created_at === null ? null : CarbonImmutable::instance($event->created_at)->utc()->toIso8601String(),
        ];
    }

    private function label(NotificationRecipientEvent $event): string
    {
        return match ($event->actor_side) {
            WorkflowActorSide::STAFF => 'STAFF',
            WorkflowActorSide::COORDINATOR => (int) $event->getAttributes()['actor_user_id'] === $this->readerId ? 'YOU' : 'COORDINATOR',
            default => 'SYSTEM',
        };
    }
}
