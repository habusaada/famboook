<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Models\AssistanceDelivery;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reverses a mistaken delivery (permission assistance.reverse). The
 * delivery row is kept and only gains reversed_at/by/reason; the
 * beneficiary is awaiting delivery again and a fresh, freshly verified
 * delivery may follow while the Assistance is OPEN. Allowed after
 * completion for correction; it never reopens the Assistance.
 */
class ReverseDeliveryAction
{
    public function handle(AssistanceDelivery $delivery, string $reason, ?int $actingUserId): AssistanceDelivery
    {
        return DB::transaction(function () use ($delivery, $reason, $actingUserId) {
            $delivery = AssistanceDelivery::whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();

            abort_unless($delivery->isActive(), 409, 'تم عكس هذا التسليم مسبقًا.');

            $delivery->reversed_at = Carbon::now();
            $delivery->reversed_by = $actingUserId;
            $delivery->reversal_reason = $reason;
            $delivery->save();

            $beneficiary = $delivery->beneficiary;
            FamilyActivityLog::record($beneficiary->family_id, FamilyActivityType::ASSISTANCE_DELIVERY_REVERSED, $beneficiary, $actingUserId);

            return $delivery;
        });
    }
}
