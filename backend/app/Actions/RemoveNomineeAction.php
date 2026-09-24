<?php

namespace App\Actions;

use App\Enums\BeneficiaryStatus;
use App\Enums\FamilyActivityType;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Support\AssistanceNomination;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws a NOMINATED candidate from an OPEN Assistance (permission
 * assistance.nominate). History-preserving: the row becomes REMOVED with
 * removed_at/removed_by and stays readable; the target may be nominated
 * again later as a new row. Only NOMINATED rows can be removed, so once
 * V1-B adds approval/delivery states they are protected automatically.
 */
class RemoveNomineeAction
{
    public function handle(Assistance $assistance, AssistanceBeneficiary $nominee, ?int $actingUserId): AssistanceBeneficiary
    {
        return DB::transaction(function () use ($assistance, $nominee, $actingUserId) {
            $assistance = AssistanceNomination::lockOpen($assistance);

            $nominee = AssistanceBeneficiary::whereKey($nominee->getKey())
                ->where('assistance_id', $assistance->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($nominee->status === BeneficiaryStatus::NOMINATED, 409, 'لا يمكن إزالة هذا المرشح.');

            $nominee->status = BeneficiaryStatus::REMOVED;
            $nominee->removed_at = Carbon::now();
            $nominee->removed_by = $actingUserId;
            $nominee->save();

            FamilyActivityLog::record($nominee->family_id, FamilyActivityType::ASSISTANCE_NOMINEE_REMOVED, $nominee, $actingUserId);

            return $nominee;
        });
    }
}
