<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Enums\NeedStatus;
use App\Models\FamilyNeed;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OPEN → FULFILLED (permission need.close). Records who resolved it and
 * when. Creates no Assistance record: Assistance is a separate, future
 * domain.
 */
class FulfillNeedAction
{
    public function handle(FamilyNeed $need, ?int $actingUserId): FamilyNeed
    {
        return DB::transaction(function () use ($need, $actingUserId) {
            $need = UpdateNeedAction::lockOpen($need);

            $need->status = NeedStatus::FULFILLED;
            $need->resolved_at = Carbon::now();
            $need->resolved_by = $actingUserId;
            $need->closure_reason = null;
            $need->updated_by = $actingUserId;
            $need->save();

            FamilyActivityLog::record($need->family_id, FamilyActivityType::NEED_FULFILLED, $need, $actingUserId);

            return $need;
        });
    }
}
