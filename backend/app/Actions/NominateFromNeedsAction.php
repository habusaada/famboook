<?php

namespace App\Actions;

use App\Enums\NeedStatus;
use App\Enums\NominationSource;
use App\Models\Assistance;
use App\Models\FamilyNeed;
use App\Support\AssistanceNomination;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "ترشيح من الاحتياجات" (permission assistance.nominate). Each selected
 * OPEN Need nominates its family (family-level need) or its person
 * (person-specific need), with source_need_id recorded. The Need itself is
 * never changed: not fulfilled, not closed, no delivery created.
 */
class NominateFromNeedsAction
{
    /**
     * @param  list<string>  $needUuids
     * @return array{created: int, skipped_duplicates: int}
     */
    public function handle(Assistance $assistance, array $needUuids, ?int $actingUserId): array
    {
        return DB::transaction(function () use ($assistance, $needUuids, $actingUserId) {
            $assistance = AssistanceNomination::lockOpen($assistance);

            $needs = FamilyNeed::whereIn('uuid', $needUuids)->get()->keyBy('uuid');

            // All-or-nothing: an unknown or resolved Need rejects the request.
            foreach (array_values($needUuids) as $index => $uuid) {
                $need = $needs->get($uuid);
                if ($need === null || $need->status !== NeedStatus::OPEN) {
                    throw ValidationException::withMessages([
                        "need_ids.{$index}" => 'يمكن الترشيح من الاحتياجات المفتوحة فقط.',
                    ]);
                }
            }

            $created = 0;
            foreach ($needUuids as $uuid) {
                $need = $needs[$uuid];
                $nominee = AssistanceNomination::add(
                    $assistance, $need->family_id, $need->person_id, NominationSource::NEED, $need, null, $actingUserId
                );
                $created += $nominee ? 1 : 0;
            }

            return ['created' => $created, 'skipped_duplicates' => count($needUuids) - $created];
        });
    }
}
