<?php

namespace App\Support;

use App\Enums\BeneficiaryStatus;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiaryListEntry;
use App\Models\AssistanceDelivery;
use App\Models\AssistanceItem;

/**
 * Execution statistics, always derived on read (docs/03-BUSINESS-RULES.md
 * §47i). Nothing here is stored. INTERNAL and EXTERNAL report different
 * figures: an EXTERNAL Assistance never reports deliveries, only what was
 * issued in lists ("تم إصدارهم في كشوف").
 */
class AssistanceStatistics
{
    /** @return array<string, mixed> */
    public static function for(Assistance $assistance): array
    {
        $counts = $assistance->beneficiaries()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $count = fn (BeneficiaryStatus $s) => (int) ($counts[$s->value] ?? 0);

        $common = [
            'target' => $assistance->target_beneficiaries,
            // Current, non-removed nominations in any state.
            'total_nominees' => (int) $counts->except(BeneficiaryStatus::REMOVED->value)->sum(),
            'pending_approval' => $count(BeneficiaryStatus::NOMINATED),
            'rejected' => $count(BeneficiaryStatus::REJECTED),
            'removed' => $count(BeneficiaryStatus::REMOVED),
        ];

        return $assistance->isInternal()
            ? [...$common, ...self::internal($assistance, $count)]
            : [...$common, ...self::external($assistance, $count)];
    }

    /** @return array<string, mixed> */
    private static function internal(Assistance $assistance, callable $count): array
    {
        $deliveries = AssistanceDelivery::query()
            ->whereHas('beneficiary', fn ($q) => $q->where('assistance_id', $assistance->id));
        $delivered = (clone $deliveries)->whereNull('reversed_at')->count();
        $approved = $count(BeneficiaryStatus::APPROVED);
        $target = $assistance->target_beneficiaries;

        $items = AssistanceItem::where('assistance_id', $assistance->id)->orderBy('sort_order')->get();
        $monetary = [];
        foreach ($items as $item) {
            if ($item->unit_value !== null && $item->currency !== null) {
                $currency = $item->currency->value;
                $monetary[$currency] = ($monetary[$currency] ?? 0)
                    + (float) $item->unit_value * (float) ($item->quantity_per_beneficiary ?? 1) * $delivered;
            }
        }

        return [
            // Ever approved and not rejected: awaiting + delivered + not delivered.
            'approved' => $approved + $count(BeneficiaryStatus::NOT_DELIVERED),
            'awaiting_delivery' => $approved - $delivered,
            'delivered' => $delivered,
            'not_delivered' => $count(BeneficiaryStatus::NOT_DELIVERED),
            'reversed_deliveries' => (clone $deliveries)->whereNotNull('reversed_at')->count(),
            'execution_percentage' => $target ? round($delivered / $target * 100, 1) : null,
            // Full package × active deliveries. Never combined across currencies.
            'package_totals' => $items->map(fn (AssistanceItem $item) => [
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'quantity' => $item->quantity_per_beneficiary === null
                    ? null
                    : self::number((float) $item->quantity_per_beneficiary * $delivered),
            ])->values()->all(),
            'monetary_totals' => collect($monetary)->map(fn ($total, $currency) => [
                'currency' => $currency,
                'total' => self::number($total),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function external(Assistance $assistance, callable $count): array
    {
        $entries = AssistanceBeneficiaryListEntry::query()
            ->whereHas('list', fn ($q) => $q->where('assistance_id', $assistance->id));
        $listed = (clone $entries)->distinct()->pluck('assistance_beneficiary_id');
        $approvedIds = $assistance->beneficiaries()->where('status', BeneficiaryStatus::APPROVED)->pluck('id');

        return [
            'approved' => $approvedIds->count(),
            'approved_not_listed' => $approvedIds->diff($listed)->count(),
            // Unique beneficiaries: appearing in a corrected list again
            // does not count twice.
            'listed_unique' => $listed->count(),
            'issued_lists' => $assistance->beneficiaryLists()->count(),
            // Physical delivery happens outside Famboook; not known in V1-B.
            'external_execution_result' => 'UNKNOWN',
        ];
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
