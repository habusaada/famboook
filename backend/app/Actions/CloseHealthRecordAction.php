<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Models\PersonHealthRecord;
use App\Support\FamilyActivityLog;
use App\Support\HealthRecordRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes an active health record by setting ended_at (permission
 * health-record.close). V1 has no hard delete: a closed record stays in
 * the history and simply stops counting as current.
 */
class CloseHealthRecordAction
{
    public function handle(PersonHealthRecord $record, ?string $endedAt, ?int $actingUserId): PersonHealthRecord
    {
        return DB::transaction(function () use ($record, $endedAt, $actingUserId) {
            $record = PersonHealthRecord::whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if (! $record->isActive()) {
                throw ValidationException::withMessages([
                    'ended_at' => 'هذا السجل مغلق مسبقًا.',
                ]);
            }

            $record->ended_at = $endedAt ?? Carbon::today();
            HealthRecordRules::assertDates($record);

            $record->updated_by = $actingUserId;
            $record->save();

            $familyId = $record->person->activeMembership?->family_id;
            if ($familyId !== null) {
                FamilyActivityLog::record($familyId, FamilyActivityType::HEALTH_RECORD_CLOSED, $record, $actingUserId, [
                    'health_record_type' => $record->type->value,
                ]);
            }

            return $record->load(['person', 'disabilityType']);
        });
    }
}
