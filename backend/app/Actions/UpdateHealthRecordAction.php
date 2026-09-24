<?php

namespace App\Actions;

use App\Enums\HealthRecordType;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use App\Support\HealthRecordRules;
use Illuminate\Support\Facades\DB;

/**
 * Corrects an existing health record in place (docs/03 §56 "Data
 * Correction", permission health-record.update). The Person, the type and
 * ended_at never change here — closing is its own operation.
 */
class UpdateHealthRecordAction
{
    /**
     * @param  array<string, mixed>  $data  Already-validated partial payload
     *                                      (see UpdateHealthRecordRequest).
     */
    public function handle(PersonHealthRecord $record, array $data, ?int $actingUserId): PersonHealthRecord
    {
        return DB::transaction(function () use ($record, $data, $actingUserId) {
            Person::whereKey($record->person_id)->lockForUpdate()->first();
            $record->refresh();

            $editable = match ($record->type) {
                HealthRecordType::DISABILITY => ['disability_type_id', 'details', 'started_at'],
                HealthRecordType::CHRONIC_DISEASE => ['condition_name', 'details', 'started_at'],
                default => ['details', 'started_at'],
            };

            $record->fill(array_intersect_key($data, array_flip($editable)));

            if (array_key_exists('condition_name', $data) && $record->type === HealthRecordType::CHRONIC_DISEASE) {
                $record->condition_name = HealthRecordRules::cleanConditionName($data['condition_name']);
            }

            HealthRecordRules::assertDates($record);
            HealthRecordRules::assertNoActiveDuplicate($record);

            $record->updated_by = $actingUserId;
            $record->save();

            return $record->load(['person', 'disabilityType']);
        });
    }
}
