<?php

namespace App\Http\Resources;

use App\Models\FamilyNeed;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Family Activity Log entry for the Staff timeline. The actor is the
 * display name only (no email); the subject is limited to the person's
 * code and name; metadata is the allow-listed stored keys. No National ID,
 * phone numbers or health details can appear here.
 */
class FamilyActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'event_type' => $this->event_type,
            'occurred_at' => $this->created_at?->toIso8601String(),
            'actor' => $this->actor ? ['name' => $this->actor->name] : null,
            'subject' => [
                'type' => $this->subject_type,
                'person' => $this->subjectPerson(),
                // Need title, resolved at read time (never stored in the log).
                'title' => $this->subject instanceof FamilyNeed ? $this->subject->title : null,
            ],
            'metadata' => $this->metadata ?? (object) [],
        ];
    }

    /** The Person the event concerns, if any, resolved at read time. */
    private function subjectPerson(): ?array
    {
        $person = match (true) {
            $this->subject instanceof Person => $this->subject,
            $this->subject instanceof PersonHealthRecord => $this->subject->person,
            $this->subject instanceof FamilyNeed => $this->subject->person,
            default => null,
        };

        return $person ? [
            'person_code' => $person->person_code,
            'full_name' => $person->full_name,
        ] : null;
    }
}
