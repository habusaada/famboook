<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Models\FamilyNeed;
use App\Support\FamilyActivityLog;
use App\Support\NeedFields;
use Illuminate\Support\Facades\DB;

/**
 * Edits an OPEN Need (permission need.update). A FULFILLED or CLOSED Need
 * is historical and is refused with 409.
 */
class UpdateNeedAction
{
    /**
     * @param  array<string, mixed>  $data  Already-validated partial payload (see UpdateNeedRequest).
     */
    public function handle(FamilyNeed $need, array $data, ?int $actingUserId): FamilyNeed
    {
        return DB::transaction(function () use ($need, $data, $actingUserId) {
            $need = self::lockOpen($need);

            NeedFields::apply($need, $data);

            // A save that changes nothing is not an activity.
            if ($need->isDirty()) {
                $need->updated_by = $actingUserId;
                $need->save();

                FamilyActivityLog::record($need->family_id, FamilyActivityType::NEED_UPDATED, $need, $actingUserId);
            }

            return $need;
        });
    }

    /**
     * Re-reads the Need under a row lock, so concurrent requests cannot both
     * act on a Need that one of them is resolving.
     */
    public static function lockOpen(FamilyNeed $need): FamilyNeed
    {
        $need = FamilyNeed::whereKey($need->getKey())->lockForUpdate()->firstOrFail();

        abort_unless($need->isOpen(), 409, 'هذا الاحتياج مُغلق ولا يمكن تعديله.');

        return $need;
    }
}
