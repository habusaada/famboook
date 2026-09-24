<?php

namespace App\Actions;

use App\Models\Assistance;
use App\Support\AssistanceFields;
use Illuminate\Support\Facades\DB;

/**
 * Edits an Assistance definition (permission assistance.update): fully
 * while DRAFT; only description, target count and dates once OPEN.
 */
class UpdateAssistanceAction
{
    /**
     * @param  array<string, mixed>  $data  Already-validated partial payload (see UpdateAssistanceRequest).
     */
    public function handle(Assistance $assistance, array $data, ?int $actingUserId): Assistance
    {
        return DB::transaction(function () use ($assistance, $data, $actingUserId) {
            $assistance = Assistance::whereKey($assistance->getKey())->lockForUpdate()->firstOrFail();

            // A save that changes nothing is not an edit.
            if (AssistanceFields::apply($assistance, $data)) {
                $assistance->updated_by = $actingUserId;
                $assistance->save();
            }

            return $assistance;
        });
    }
}
