<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Enums\ReceiptMode;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\AssistanceDelivery;
use App\Support\DeliveryVerification;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records an INTERNAL, identity-verified receipt of the FULL package
 * (permission assistance.deliver). Identity is re-verified here, inside
 * the transaction, even if the Staff App verified moments before. No
 * National ID is stored. The Need that led to the nomination (if any) is
 * never changed.
 */
class RecordDeliveryAction
{
    public function handle(
        Assistance $assistance,
        AssistanceBeneficiary $beneficiary,
        ReceiptMode $mode,
        string $beneficiaryNationalId,
        ?string $delegateNationalId,
        ?string $notes,
        ?int $actingUserId,
    ): AssistanceDelivery {
        return DB::transaction(function () use ($assistance, $beneficiary, $mode, $beneficiaryNationalId, $delegateNationalId, $notes, $actingUserId) {
            $assistance = Assistance::whereKey($assistance->getKey())->lockForUpdate()->firstOrFail();
            $beneficiary = AssistanceBeneficiary::whereKey($beneficiary->getKey())
                ->where('assistance_id', $assistance->id)
                ->lockForUpdate()
                ->firstOrFail();

            $verified = DeliveryVerification::verify($assistance, $beneficiary, $mode, $beneficiaryNationalId, $delegateNationalId);

            $delivery = AssistanceDelivery::create([
                'assistance_beneficiary_id' => $beneficiary->id,
                'receipt_mode' => $mode,
                'original_beneficiary_person_id' => $verified['original']->id,
                'recipient_person_id' => $verified['recipient']->id,
                'delivered_at' => Carbon::now(),
                'delivered_by' => $actingUserId,
                'notes' => $notes,
            ]);

            FamilyActivityLog::record($beneficiary->family_id, FamilyActivityType::ASSISTANCE_DELIVERED, $beneficiary, $actingUserId);

            return $delivery;
        });
    }
}
