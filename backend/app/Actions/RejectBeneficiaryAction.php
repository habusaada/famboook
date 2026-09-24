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
 * NOMINATED → REJECTED with a mandatory free-text reason (permission
 * assistance.approve). REJECTED is terminal in V1-B. The reason is stored
 * on the beneficiary only, never in the Activity Log.
 */
class RejectBeneficiaryAction
{
    public function handle(Assistance $assistance, AssistanceBeneficiary $beneficiary, string $reason, ?int $actingUserId): AssistanceBeneficiary
    {
        return DB::transaction(function () use ($assistance, $beneficiary, $reason, $actingUserId) {
            $assistance = AssistanceNomination::lockOpen($assistance);
            $beneficiary = AssistanceBeneficiary::whereKey($beneficiary->getKey())
                ->where('assistance_id', $assistance->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($beneficiary->status === BeneficiaryStatus::NOMINATED, 409, 'يمكن رفض المرشحين بحالة "مرشح" فقط.');

            $beneficiary->status = BeneficiaryStatus::REJECTED;
            $beneficiary->rejected_at = Carbon::now();
            $beneficiary->rejected_by = $actingUserId;
            $beneficiary->rejection_reason = $reason;
            $beneficiary->save();

            FamilyActivityLog::record($beneficiary->family_id, FamilyActivityType::ASSISTANCE_BENEFICIARY_REJECTED, $beneficiary, $actingUserId);

            return $beneficiary;
        });
    }
}
