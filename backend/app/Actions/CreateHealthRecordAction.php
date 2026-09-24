<?php

namespace App\Actions;

use App\Enums\HealthRecordType;
use App\Models\Family;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use App\Support\HealthRecordRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a health record for an ACTIVE member of the given Family
 * (docs/03-BUSINESS-RULES.md §35-36, permission health-record.create).
 */
class CreateHealthRecordAction
{
    /**
     * @param  array{
     *     person_code: string,
     *     type: string,
     *     disability_type_id?: int|null,
     *     condition_name?: string|null,
     *     details?: string|null,
     *     started_at?: string|null,
     * }  $data  Already-validated payload (see StoreHealthRecordRequest).
     */
    public function handle(Family $family, array $data, ?int $actingUserId): PersonHealthRecord
    {
        return DB::transaction(function () use ($family, $data, $actingUserId) {
            // Lock the Person so concurrent requests cannot both pass the
            // duplicate check.
            $person = Person::query()
                ->where('person_code', $data['person_code'])
                ->whereHas('activeMembership', fn ($q) => $q->where('family_id', $family->id))
                ->lockForUpdate()
                ->first();

            if ($person === null) {
                throw ValidationException::withMessages([
                    'person_code' => 'الشخص المحدد ليس فردًا نشطًا في هذه الأسرة.',
                ]);
            }

            $type = HealthRecordType::from($data['type']);

            // Only the fields that belong to this type are ever stored.
            $record = new PersonHealthRecord([
                'person_id' => $person->id,
                'type' => $type,
                'disability_type_id' => $type === HealthRecordType::DISABILITY
                    ? $data['disability_type_id'] : null,
                'condition_name' => $type === HealthRecordType::CHRONIC_DISEASE
                    ? HealthRecordRules::cleanConditionName($data['condition_name']) : null,
                'details' => $data['details'] ?? null,
                'started_at' => $data['started_at'] ?? null,
                'created_by' => $actingUserId,
                'updated_by' => $actingUserId,
            ]);

            HealthRecordRules::assertEligiblePerson($person, $type);
            HealthRecordRules::assertNoActiveDuplicate($record);

            $record->save();

            return $record->load(['person', 'disabilityType']);
        });
    }
}
