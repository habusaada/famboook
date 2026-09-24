<?php

namespace App\Actions;

use App\Enums\BeneficiaryStatus;
use App\Enums\FamilyActivityType;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * APPROVED (awaiting delivery) → NOT_DELIVERED: an explicit, final
 * non-delivery decision with a mandatory reason (INTERNAL only, permission
 * assistance.deliver). Pending beneficiaries are never marked
 * automatically.
 */
class MarkNotDeliveredAction
{
    public function handle(Assistance $assistance, AssistanceBeneficiary $beneficiary, string $reason, ?int $actingUserId): AssistanceBeneficiary
    {
        return DB::transaction(function () use ($assistance, $beneficiary, $reason, $actingUserId) {
            $assistance = Assistance::whereKey($assistance->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($assistance->isInternal(), 409, 'عدم التسليم غير متاح لمساعدة ذات تنفيذ خارجي.');
            abort_unless($assistance->isOpen(), 409, 'يمكن تسجيل عدم التسليم فقط عندما تكون المساعدة مفتوحة.');

            $beneficiary = AssistanceBeneficiary::whereKey($beneficiary->getKey())
                ->where('assistance_id', $assistance->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($beneficiary->status === BeneficiaryStatus::APPROVED, 409, 'يمكن تسجيل عدم التسليم للمستفيدين المعتمدين فقط.');
            abort_if($beneficiary->activeDelivery()->exists(), 409, 'يوجد تسليم فعّال لهذا المستفيد؛ اعكس التسليم أولًا.');

            $beneficiary->status = BeneficiaryStatus::NOT_DELIVERED;
            $beneficiary->not_delivered_at = Carbon::now();
            $beneficiary->not_delivered_by = $actingUserId;
            $beneficiary->not_delivered_reason = $reason;
            $beneficiary->save();

            FamilyActivityLog::record($beneficiary->family_id, FamilyActivityType::ASSISTANCE_NOT_DELIVERED, $beneficiary, $actingUserId);

            return $beneficiary;
        });
    }
}
