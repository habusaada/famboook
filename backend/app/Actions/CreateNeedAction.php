<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Enums\NeedPriority;
use App\Enums\NeedStatus;
use App\Models\Family;
use App\Models\FamilyNeed;
use App\Support\FamilyActivityLog;
use App\Support\NeedFields;
use Illuminate\Support\Facades\DB;

/**
 * Records a new OPEN Need for a Family or one of its active members
 * (docs/03-BUSINESS-RULES.md §46a, permission need.create). Never created
 * automatically from Assessment results.
 */
class CreateNeedAction
{
    /**
     * @param  array<string, mixed>  $data  Already-validated payload (see StoreNeedRequest).
     */
    public function handle(Family $family, array $data, ?int $actingUserId): FamilyNeed
    {
        return DB::transaction(function () use ($family, $data, $actingUserId) {
            $need = new FamilyNeed([
                'family_id' => $family->id,
                'status' => NeedStatus::OPEN,
                'priority' => NeedPriority::MEDIUM,
                'created_by' => $actingUserId,
                'updated_by' => $actingUserId,
            ]);

            NeedFields::apply($need, $data);
            $need->save();

            FamilyActivityLog::record($family->id, FamilyActivityType::NEED_CREATED, $need, $actingUserId);

            return $need;
        });
    }
}
