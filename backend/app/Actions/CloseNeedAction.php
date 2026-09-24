<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Enums\NeedStatus;
use App\Models\FamilyNeed;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OPEN → CLOSED for a reason other than fulfilment (permission
 * need.close). The free-text reason is stored on the Need only, never in
 * the Activity Log.
 */
class CloseNeedAction
{
    public function handle(FamilyNeed $need, string $closureReason, ?int $actingUserId): FamilyNeed
    {
        return DB::transaction(function () use ($need, $closureReason, $actingUserId) {
            $need = UpdateNeedAction::lockOpen($need);

            $need->status = NeedStatus::CLOSED;
            $need->resolved_at = Carbon::now();
            $need->resolved_by = $actingUserId;
            $need->closure_reason = $closureReason;
            $need->updated_by = $actingUserId;
            $need->save();

            FamilyActivityLog::record($need->family_id, FamilyActivityType::NEED_CLOSED, $need, $actingUserId);

            return $need;
        });
    }
}
