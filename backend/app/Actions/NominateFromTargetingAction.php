<?php

namespace App\Actions;

use App\Enums\NominationSource;
use App\Models\Assistance;
use App\Support\AssistanceNomination;
use App\Support\FamilyTargeting;
use App\Support\TargetingCriteria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "إضافة المحددين كمرشحين" (permission assistance.nominate). Nominates
 * exactly the families a person selected from the targeting preview, as
 * family-level nominees (source TARGETING). The server re-checks that each
 * selected family matches the submitted criteria; nothing else is ever
 * nominated. The validated criteria become the Assistance's targeting
 * snapshot and are stored on each new nomination.
 */
class NominateFromTargetingAction
{
    /**
     * @param  list<string>  $familyCodes
     * @param  array<string, mixed>|null  $criteria  Request-validated criteria.
     * @return array{created: int, skipped_duplicates: int}
     */
    public function handle(Assistance $assistance, array $familyCodes, ?array $criteria, ?int $actingUserId): array
    {
        return DB::transaction(function () use ($assistance, $familyCodes, $criteria, $actingUserId) {
            $assistance = AssistanceNomination::lockOpen($assistance);
            $criteria = TargetingCriteria::normalize($criteria, 'criteria');

            $matching = FamilyTargeting::query($criteria, Carbon::today())
                ->whereIn('family_code', $familyCodes)
                ->pluck('id', 'family_code');

            foreach (array_values($familyCodes) as $index => $code) {
                if (! $matching->has($code)) {
                    throw ValidationException::withMessages([
                        "family_codes.{$index}" => "الأسرة {$code} لا تطابق معايير الاستهداف.",
                    ]);
                }
            }

            $created = 0;
            foreach ($familyCodes as $code) {
                $nominee = AssistanceNomination::add(
                    $assistance, $matching[$code], null, NominationSource::TARGETING, null, $criteria, $actingUserId
                );
                $created += $nominee ? 1 : 0;
            }

            // The criteria actually used for targeting (audit snapshot).
            $assistance->targeting_criteria = $criteria === [] ? null : $criteria;
            $assistance->save();

            return ['created' => $created, 'skipped_duplicates' => count($familyCodes) - $created];
        });
    }
}
