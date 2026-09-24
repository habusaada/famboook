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
use Illuminate\Validation\ValidationException;

/**
 * NOMINATED → APPROVED for one or many beneficiaries (permission
 * assistance.approve). All-or-nothing: if any selected beneficiary is not
 * currently NOMINATED in this OPEN Assistance, nothing is approved.
 */
class ApproveBeneficiariesAction
{
    /**
     * @param  list<string>  $uuids
     */
    public function handle(Assistance $assistance, array $uuids, ?int $actingUserId): int
    {
        return DB::transaction(function () use ($assistance, $uuids, $actingUserId) {
            $assistance = AssistanceNomination::lockOpen($assistance);

            $beneficiaries = AssistanceBeneficiary::query()
                ->where('assistance_id', $assistance->id)
                ->whereIn('uuid', $uuids)
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            foreach (array_values($uuids) as $index => $uuid) {
                $beneficiary = $beneficiaries->get($uuid);
                if ($beneficiary === null || $beneficiary->status !== BeneficiaryStatus::NOMINATED) {
                    throw ValidationException::withMessages([
                        "nominee_ids.{$index}" => 'يمكن اعتماد المرشحين بحالة "مرشح" فقط.',
                    ]);
                }
            }

            $now = Carbon::now();
            foreach ($uuids as $uuid) {
                $beneficiary = $beneficiaries[$uuid];
                $beneficiary->status = BeneficiaryStatus::APPROVED;
                $beneficiary->approved_at = $now;
                $beneficiary->approved_by = $actingUserId;
                $beneficiary->save();

                FamilyActivityLog::record($beneficiary->family_id, FamilyActivityType::ASSISTANCE_BENEFICIARY_APPROVED, $beneficiary, $actingUserId);
            }

            return count($uuids);
        });
    }
}
