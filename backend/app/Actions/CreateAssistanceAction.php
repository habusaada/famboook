<?php

namespace App\Actions;

use App\Enums\AssistanceStatus;
use App\Models\Assistance;
use App\Support\AssistanceFields;
use Illuminate\Support\Facades\DB;

/**
 * Defines a new DRAFT assistance program/campaign with its planned items
 * (docs/03-BUSINESS-RULES.md §47a, permission assistance.create).
 *
 * No Family Activity entry: the Family Activity Log is family-scoped and
 * a program definition concerns no family yet.
 */
class CreateAssistanceAction
{
    /**
     * @param  array<string, mixed>  $data  Already-validated payload (see StoreAssistanceRequest).
     */
    public function handle(array $data, ?int $actingUserId): Assistance
    {
        return DB::transaction(function () use ($data, $actingUserId) {
            $assistance = new Assistance([
                'status' => AssistanceStatus::DRAFT,
                'created_by' => $actingUserId,
                'updated_by' => $actingUserId,
            ]);

            AssistanceFields::apply($assistance, $data);

            return $assistance;
        });
    }
}
