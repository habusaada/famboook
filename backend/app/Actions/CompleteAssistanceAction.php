<?php

namespace App\Actions;

use App\Enums\AssistanceStatus;
use App\Enums\BeneficiaryStatus;
use App\Models\Assistance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * OPEN → COMPLETED (permission assistance.complete), once every
 * beneficiary is resolved:
 *
 * - INTERNAL: no NOMINATED, and no APPROVED without an active delivery.
 * - EXTERNAL: no NOMINATED, and every APPROVED included in at least one
 *   issued list. Completion does NOT mean anyone received anything.
 *
 * REJECTED, REMOVED and NOT_DELIVERED never block.
 */
class CompleteAssistanceAction
{
    public function handle(Assistance $assistance, ?int $actingUserId): Assistance
    {
        return DB::transaction(function () use ($assistance, $actingUserId) {
            $assistance = Assistance::whereKey($assistance->getKey())->lockForUpdate()->firstOrFail();

            abort_unless($assistance->isOpen(), 409, 'يمكن إكمال المساعدات المفتوحة فقط.');

            $blocking = [];
            $pending = $assistance->beneficiaries()->where('status', BeneficiaryStatus::NOMINATED)->count();
            if ($pending > 0) {
                $blocking[] = "{$pending} مرشح بانتظار الاعتماد أو الرفض";
            }

            $approved = $assistance->beneficiaries()->where('status', BeneficiaryStatus::APPROVED);
            if ($assistance->isInternal()) {
                $awaiting = (clone $approved)->whereDoesntHave('activeDelivery')->count();
                if ($awaiting > 0) {
                    $blocking[] = "{$awaiting} مستفيد معتمد بانتظار التسليم";
                }
            } else {
                $unlisted = (clone $approved)->whereDoesntHave('listEntries')->count();
                if ($unlisted > 0) {
                    $blocking[] = "{$unlisted} مستفيد معتمد لم يُدرج في أي كشف صادر";
                }
            }

            if ($blocking !== []) {
                throw ValidationException::withMessages([
                    'status' => 'لا يمكن إكمال المساعدة: '.implode('، ', $blocking).'.',
                ]);
            }

            $assistance->status = AssistanceStatus::COMPLETED;
            $assistance->completed_at = Carbon::now();
            $assistance->completed_by = $actingUserId;
            $assistance->updated_by = $actingUserId;
            $assistance->save();

            return $assistance;
        });
    }
}
