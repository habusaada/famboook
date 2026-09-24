<?php

namespace App\Actions;

use App\Models\PersonHealthRecord;
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

            return $record->load(['person', 'disabilityType']);
        });
    }
}
