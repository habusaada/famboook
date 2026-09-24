<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A nominee/beneficiary row. Safe identity only: family code and
 * household-head name, the person's code and name, and for NEED
 * nominations the need's title, category, priority and status — never its
 * description. Deliveries show who received (code/name) and when; issued
 * lists show number and date only. No National ID, phone numbers, health
 * data, list snapshot values, internal ids or emails. The targeting
 * criteria snapshot is not exposed here.
 */
class AssistanceNomineeResource extends JsonResource
{
    public const RELATIONS = [
        'family.householdHeadMembership.person',
        'person',
        'sourceNeed.category',
        'nominator:id,name',
        'remover:id,name',
        'approver:id,name',
        'rejecter:id,name',
        'notDeliveredBy:id,name',
        'deliveries.recipient',
        'deliveries.originalBeneficiary',
        'deliveries.deliverer:id,name',
        'deliveries.reverser:id,name',
        'listEntries.list:id,uuid,list_number,issued_at',
    ];

    public function toArray(Request $request): array
    {
        $need = $this->sourceNeed;
        $person = fn ($p) => $p ? ['person_code' => $p->person_code, 'full_name' => $p->full_name] : null;
        $delivery = fn ($d) => [
            'id' => $d->uuid,
            'receipt_mode' => $d->receipt_mode,
            'original_beneficiary' => $person($d->originalBeneficiary),
            'recipient' => $person($d->recipient),
            'delivered_at' => $d->delivered_at?->toIso8601String(),
            'delivered_by' => $d->deliverer ? ['name' => $d->deliverer->name] : null,
            'notes' => $d->notes,
            'reversed_at' => $d->reversed_at?->toIso8601String(),
            'reversed_by' => $d->reverser ? ['name' => $d->reverser->name] : null,
            'reversal_reason' => $d->reversal_reason,
        ];

        return [
            'id' => $this->uuid,
            'family' => [
                'family_code' => $this->family->family_code,
                'household_head_name' => $this->family->householdHeadMembership?->person?->full_name,
            ],
            // null = family-level nominee.
            'person' => $person($this->person),
            'nomination_source' => $this->nomination_source,
            'source_need' => $need ? [
                'id' => $need->uuid,
                'title' => $need->title,
                'category' => ['code' => $need->category->code, 'name' => $need->category->name],
                'priority' => $need->priority,
                'status' => $need->status,
            ] : null,
            'status' => $this->status,
            'nominated_at' => $this->nominated_at?->toIso8601String(),
            'nominated_by' => $this->nominator ? ['name' => $this->nominator->name] : null,
            'removed_at' => $this->removed_at?->toIso8601String(),
            'removed_by' => $this->remover ? ['name' => $this->remover->name] : null,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->approver ? ['name' => $this->approver->name] : null,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejected_by' => $this->rejecter ? ['name' => $this->rejecter->name] : null,
            'rejection_reason' => $this->rejection_reason,
            'not_delivered_at' => $this->not_delivered_at?->toIso8601String(),
            'not_delivered_by' => $this->notDeliveredBy ? ['name' => $this->notDeliveredBy->name] : null,
            'not_delivered_reason' => $this->not_delivered_reason,
            // INTERNAL: the current delivery and any reversed ones (history).
            'active_delivery' => $this->whenLoaded('deliveries', fn () => ($d = $this->deliveries->firstWhere('reversed_at', null)) ? $delivery($d) : null),
            'reversed_deliveries' => $this->whenLoaded('deliveries', fn () => $this->deliveries->whereNotNull('reversed_at')->values()->map($delivery)),
            // EXTERNAL: issued lists this beneficiary appears in (not a delivery).
            'listed_in' => $this->whenLoaded('listEntries', fn () => $this->listEntries
                ->map(fn ($e) => ['list_id' => $e->list->uuid, 'list_number' => $e->list->list_number, 'issued_at' => $e->list->issued_at?->toIso8601String()])
                ->sortBy('issued_at')->values()),
        ];
    }
}
