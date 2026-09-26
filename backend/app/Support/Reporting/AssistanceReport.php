<?php

namespace App\Support\Reporting;

use App\Enums\BeneficiaryStatus;
use App\Enums\ExecutionMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Report 5 — Assistance (docs/03 §55b). Programs in the selected statuses
 * (default OPEN + COMPLETED) that have a current beneficiary whose target
 * Family is in scope. Every figure counts only those scoped beneficiaries
 * — never the program's global counters. INTERNAL and EXTERNAL figures are
 * kept apart: an issued list is never a delivery. No National IDs,
 * verification values or issued-list snapshot data.
 */
final class AssistanceReport
{
    /** @param  list<string>  $statuses */
    public function __construct(
        private readonly OrganizationalScope $scope,
        private readonly array $statuses,
    ) {}

    /** Scoped beneficiaries of the selected programs. Aliases: b, s. */
    private function beneficiaries(): Builder
    {
        return DB::table('assistance_beneficiaries as b')
            ->join('assistances as s', 's.id', '=', 'b.assistance_id')
            ->whereIn('s.status', $this->statuses)
            ->whereIn('b.family_id', $this->scope->familyIds());
    }

    /**
     * All relevant programs with their scoped statistics, newest first.
     * A fixed number of grouped queries, whatever the number of programs.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function programs(): Collection
    {
        $relevant = $this->beneficiaries()
            ->where('b.status', '!=', BeneficiaryStatus::REMOVED->value)
            ->select('b.assistance_id');

        $programs = DB::table('assistances as s')
            ->join('assistance_categories as c', 'c.id', '=', 's.assistance_category_id')
            ->whereIn('s.id', $relevant)
            ->orderByRaw('COALESCE(s.opened_at, s.created_at) DESC')
            ->orderByDesc('s.id')
            ->get(['s.id', 's.uuid', 's.title', 's.provider_name', 's.execution_mode', 's.status', 's.target_beneficiaries', 'c.code as category_code', 'c.name as category_name']);

        $ids = $programs->pluck('id');
        $grouped = fn (Builder $q) => $q->whereIn('b.assistance_id', $ids)
            ->selectRaw('b.assistance_id as id, count(*) as total')
            ->groupBy('b.assistance_id')
            ->pluck('total', 'id');
        $activeDelivery = fn (Builder $q) => $q->from('assistance_deliveries as d')
            ->whereColumn('d.assistance_beneficiary_id', 'b.id')
            ->whereNull('d.reversed_at');
        $listed = fn (Builder $q) => $q->from('assistance_beneficiary_list_entries as e')
            ->whereColumn('e.assistance_beneficiary_id', 'b.id');

        $statusCounts = $this->beneficiaries()
            ->whereIn('b.assistance_id', $ids)
            ->selectRaw('b.assistance_id as id, b.status as status, count(*) as total')
            ->groupBy('b.assistance_id', 'b.status')
            ->get()
            ->groupBy('id');
        $delivered = $grouped($this->beneficiaries()->whereExists($activeDelivery));
        $awaiting = $grouped($this->beneficiaries()->where('b.status', BeneficiaryStatus::APPROVED->value)->whereNotExists($activeDelivery));
        $reversed = $this->beneficiaries()
            ->join('assistance_deliveries as d', 'd.assistance_beneficiary_id', '=', 'b.id')
            ->whereNotNull('d.reversed_at')
            ->whereIn('b.assistance_id', $ids)
            ->selectRaw('b.assistance_id as id, count(*) as total')
            ->groupBy('b.assistance_id')
            ->pluck('total', 'id');
        $listedUnique = $grouped($this->beneficiaries()->whereExists($listed));
        $approvedNotListed = $grouped($this->beneficiaries()->where('b.status', BeneficiaryStatus::APPROVED->value)->whereNotExists($listed));
        // Issued lists that include at least one scoped beneficiary.
        $lists = $this->beneficiaries()
            ->join('assistance_beneficiary_list_entries as e', 'e.assistance_beneficiary_id', '=', 'b.id')
            ->whereIn('b.assistance_id', $ids)
            ->selectRaw('b.assistance_id as id, count(distinct e.assistance_beneficiary_list_id) as total')
            ->groupBy('b.assistance_id')
            ->pluck('total', 'id');

        return $programs->map(function ($p) use ($statusCounts, $delivered, $awaiting, $reversed, $listedUnique, $approvedNotListed, $lists) {
            $counts = collect($statusCounts->get($p->id, []))->pluck('total', 'status');
            $status = fn (BeneficiaryStatus $s) => (int) ($counts[$s->value] ?? 0);
            $n = fn ($map) => (int) ($map[$p->id] ?? 0);

            $row = [
                'id' => $p->uuid,
                'title' => $p->title,
                'category' => ['code' => $p->category_code, 'name' => $p->category_name],
                'provider' => $p->provider_name,
                'execution_mode' => $p->execution_mode,
                'status' => $p->status,
                // The program's overall target (not scoped).
                'target' => $p->target_beneficiaries === null ? null : (int) $p->target_beneficiaries,
                'nominated' => $status(BeneficiaryStatus::NOMINATED),
                'rejected' => $status(BeneficiaryStatus::REJECTED),
            ];

            if ($p->execution_mode === ExecutionMode::INTERNAL->value) {
                // Ever approved and not rejected (as AssistanceStatistics).
                $approved = $status(BeneficiaryStatus::APPROVED) + $status(BeneficiaryStatus::NOT_DELIVERED);

                return [...$row,
                    'approved' => $approved,
                    'internal' => [
                        'awaiting_delivery' => $n($awaiting),
                        'delivered' => $n($delivered),
                        'not_delivered' => $status(BeneficiaryStatus::NOT_DELIVERED),
                        'reversed_deliveries' => $n($reversed),
                        // Scoped delivered ÷ scoped approved (the program
                        // target is not scoped, so it is not the base).
                        'delivery_percentage' => $approved > 0 ? round($n($delivered) / $approved * 100, 1) : null,
                    ],
                    'external' => null,
                ];
            }

            return [...$row,
                'approved' => $status(BeneficiaryStatus::APPROVED),
                'internal' => null,
                'external' => [
                    'approved_not_listed' => $n($approvedNotListed),
                    // Unique beneficiaries — a corrected list does not
                    // count anyone twice. Never a delivery.
                    'listed_unique' => $n($listedUnique),
                    'issued_lists' => $n($lists),
                ],
            ];
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $programs
     * @return array<string, mixed>
     */
    public static function totals(Collection $programs): array
    {
        $internal = $programs->where('execution_mode', ExecutionMode::INTERNAL->value);
        $external = $programs->where('execution_mode', ExecutionMode::EXTERNAL->value);
        $sum = fn (Collection $c, string $key) => (int) $c->sum(fn ($p) => data_get($p, $key));

        return [
            'internal' => [
                'programs' => $internal->count(),
                'nominated' => $sum($internal, 'nominated'),
                'approved' => $sum($internal, 'approved'),
                'rejected' => $sum($internal, 'rejected'),
                'awaiting_delivery' => $sum($internal, 'internal.awaiting_delivery'),
                'delivered' => $sum($internal, 'internal.delivered'),
                'not_delivered' => $sum($internal, 'internal.not_delivered'),
                'reversed_deliveries' => $sum($internal, 'internal.reversed_deliveries'),
            ],
            'external' => [
                'programs' => $external->count(),
                'nominated' => $sum($external, 'nominated'),
                'approved' => $sum($external, 'approved'),
                'rejected' => $sum($external, 'rejected'),
                'approved_not_listed' => $sum($external, 'external.approved_not_listed'),
                'listed_unique' => $sum($external, 'external.listed_unique'),
                'issued_lists' => $sum($external, 'external.issued_lists'),
            ],
        ];
    }
}
