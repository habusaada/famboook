<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AssistanceBeneficiary;
use App\Models\Family;
use Illuminate\Http\JsonResponse;

/**
 * A family's Assistance history for the Family Profile "المساعدات" tab
 * (permission assistance.view). INTERNAL rows show the delivery state;
 * EXTERNAL rows only show issued lists — never a delivery. No National ID,
 * no issued-list values, no reasons.
 */
class FamilyAssistanceController extends Controller
{
    public function index(Family $family): JsonResponse
    {
        $rows = AssistanceBeneficiary::query()
            ->where('family_id', $family->id)
            ->with([
                'assistance.category',
                'person',
                'deliveries.recipient',
                'listEntries.list:id,list_number,issued_at',
            ])
            ->latest('nominated_at')
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $rows->map(function (AssistanceBeneficiary $b) {
                $assistance = $b->assistance;
                $active = $b->deliveries->firstWhere('reversed_at', null);

                return [
                    'id' => $b->uuid,
                    'assistance' => [
                        'id' => $assistance->uuid,
                        'title' => $assistance->title,
                        'category' => ['code' => $assistance->category->code, 'name' => $assistance->category->name],
                        'provider_name' => $assistance->provider_name,
                        'execution_mode' => $assistance->execution_mode,
                        'status' => $assistance->status,
                    ],
                    // null = the whole family.
                    'person' => $b->person ? ['person_code' => $b->person->person_code, 'full_name' => $b->person->full_name] : null,
                    'status' => $b->status,
                    'nominated_at' => $b->nominated_at?->toIso8601String(),
                    'approved_at' => $b->approved_at?->toIso8601String(),
                    'delivery' => $assistance->isInternal() && $active ? [
                        'delivered_at' => $active->delivered_at?->toIso8601String(),
                        'receipt_mode' => $active->receipt_mode,
                        'recipient_name' => $active->recipient?->full_name,
                    ] : null,
                    'lists' => $assistance->isExternal()
                        ? $b->listEntries->map(fn ($e) => [
                            'list_number' => $e->list->list_number,
                            'issued_at' => $e->list->issued_at?->toIso8601String(),
                        ])->sortBy('issued_at')->values()
                        : [],
                ];
            })->values(),
        ]);
    }
}
