<?php

namespace App\Http\Resources;

use App\Models\AssistanceDelivery;
use App\Models\AssistanceItem;
use App\Support\FamilyPortal\HistoricalPerson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Received assistance for the Family Portal (GET
 * /api/v1/family/household/assistance, PWA-3B.7, docs/11 §23a, docs/06
 * §125): an explicit allow-list built from HouseholdReadModel::assistance()
 * — non-reversed INTERNAL deliveries only. Per delivery: delivered_at (the
 * date), the Assistance (title, category code and name, type,
 * provider_name and its package — item_name, quantity, unit, unit_value,
 * currency; docs/03 §47e: one delivery = the full package), the beneficiary
 * (null = the whole family), receipt_mode and the recipient's name. NULL
 * stays NULL.
 *
 * Never an id or uuid, person_id / person_code, original_beneficiary or
 * recipient ids, program status, execution_mode, targets, planned dates,
 * description, targeting criteria, export fields, nomination source or
 * status, any workflow date, actor or reason, delivery notes, delivered_by,
 * any reversal field or anything of an issued list. The Staff assistance
 * resources are deliberately not reused.
 *
 * @property array{family_id: int, deliveries: Collection<int, AssistanceDelivery>, packages: Collection<int, Collection<int, AssistanceItem>>} $resource
 */
class FamilyHouseholdAssistanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $familyId = $this->resource['family_id'];
        $packages = $this->resource['packages'];

        return [
            'deliveries' => $this->resource['deliveries']->map(fn (AssistanceDelivery $delivery) => [
                'delivered_at' => Carbon::parse($delivery->getRawOriginal('delivered_at'))->toDateString(),
                'assistance' => [
                    'title' => $delivery->assistance_title,
                    'category' => ['code' => $delivery->category_code, 'name' => $delivery->category_name],
                    'type' => $delivery->assistance_type,
                    'provider_name' => $delivery->provider_name,
                    'items' => ($packages->get($delivery->assistance_id) ?? collect())
                        ->map(fn (AssistanceItem $item) => [
                            'item_name' => $item->item_name,
                            'quantity' => HistoricalPerson::decimal($item->getRawOriginal('quantity_per_beneficiary')),
                            'unit' => $item->unit,
                            'unit_value' => HistoricalPerson::decimal($item->getRawOriginal('unit_value')),
                            'currency' => $item->getRawOriginal('currency'),
                        ])->values()->all(),
                ],
                'beneficiary' => $delivery->beneficiary_person_id === null ? null : HistoricalPerson::member(
                    $familyId,
                    $delivery->beneficiary_full_name,
                    $delivery->beneficiary_deleted_at,
                    $delivery->current_membership_id === null ? null : (int) $delivery->current_membership_id,
                ),
                'receipt_mode' => $delivery->getRawOriginal('receipt_mode'),
                'recipient' => HistoricalPerson::name($delivery->recipient_full_name, $delivery->recipient_deleted_at),
            ])->values()->all(),
        ];
    }
}
