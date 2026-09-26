<?php

namespace App\Support\Reporting;

use App\Enums\NeedPriority;
use App\Enums\NeedStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Report 3 — Needs (docs/03 §55b): the full lifecycle (OPEN, FULFILLED,
 * CLOSED — CLOSED is never counted as fulfilled) of Needs belonging to
 * scoped families, with optional status / priority / category / target
 * filters applied to every figure and the detail rows. Never the
 * description or closure reason.
 */
final class NeedsReport
{
    /**
     * @param  array{status?: ?string, priority?: ?string, category?: ?string, target?: ?string}  $filters
     */
    public function __construct(
        private readonly OrganizationalScope $scope,
        private readonly array $filters,
    ) {}

    /** Filtered Needs of scoped families. Aliases: n = family_needs. */
    private function needs(): Builder
    {
        $f = $this->filters;

        return DB::table('family_needs as n')
            ->whereIn('n.family_id', $this->scope->familyIds())
            ->when($f['status'] ?? null, fn (Builder $q, $v) => $q->where('n.status', $v))
            ->when($f['priority'] ?? null, fn (Builder $q, $v) => $q->where('n.priority', $v))
            ->when($f['category'] ?? null, fn (Builder $q, $v) => $q->whereIn(
                'n.need_category_id',
                DB::table('need_categories')->select('id')->where('code', $v),
            ))
            ->when(($f['target'] ?? null) === 'FAMILY', fn (Builder $q) => $q->whereNull('n.person_id'))
            ->when(($f['target'] ?? null) === 'PERSON', fn (Builder $q) => $q->whereNotNull('n.person_id'));
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $byStatus = $this->needs()->selectRaw('n.status as status, count(*) as total')->groupBy('n.status')->pluck('total', 'status');
        $byPriority = $this->needs()->selectRaw('n.priority as priority, count(*) as total')->groupBy('n.priority')->pluck('total', 'priority');
        $byTarget = $this->needs()
            ->selectRaw("CASE WHEN n.person_id IS NULL THEN 'FAMILY' ELSE 'PERSON' END as target, count(*) as total")
            ->groupByRaw("CASE WHEN n.person_id IS NULL THEN 'FAMILY' ELSE 'PERSON' END")
            ->pluck('total', 'target');
        $byCategory = $this->needs()
            ->join('need_categories as c', 'c.id', '=', 'n.need_category_id')
            ->selectRaw('c.code as code, c.name as name, count(*) as total')
            ->groupBy('c.code', 'c.name')
            ->orderByDesc('total')
            ->orderBy('c.name')
            ->get();

        $status = fn (NeedStatus $s) => (int) ($byStatus[$s->value] ?? 0);

        return [
            'total' => (int) $byStatus->sum(),
            'open' => $status(NeedStatus::OPEN),
            'fulfilled' => $status(NeedStatus::FULFILLED),
            'closed' => $status(NeedStatus::CLOSED),
            'by_priority' => array_map(fn (NeedPriority $p) => [
                'priority' => $p->value,
                'count' => (int) ($byPriority[$p->value] ?? 0),
            ], [NeedPriority::URGENT, NeedPriority::HIGH, NeedPriority::MEDIUM, NeedPriority::LOW]),
            'by_target' => [
                'family' => (int) ($byTarget['FAMILY'] ?? 0),
                'person' => (int) ($byTarget['PERSON'] ?? 0),
            ],
            'by_category' => $byCategory->map(fn ($c) => ['code' => $c->code, 'name' => $c->name, 'count' => (int) $c->total])->all(),
        ];
    }

    /** Detail rows: safe identifying and navigation fields only. */
    public function rowsQuery(): Builder
    {
        return OrganizationalScope::joinHouseholdHead(
            $this->needs()
                ->join('families as f', 'f.id', '=', 'n.family_id')
                ->join('need_categories as c', 'c.id', '=', 'n.need_category_id')
                ->leftJoin('persons as tp', 'tp.id', '=', 'n.person_id'),
        )
            ->select([
                'n.id', 'n.uuid', 'n.title', 'n.priority', 'n.status', 'n.created_at', 'n.resolved_at',
                'c.code as category_code', 'c.name as category_name',
                'f.family_code', 'hp.full_name as head_name',
                'tp.person_code as person_code', 'tp.full_name as person_name',
            ])
            ->orderByDesc('n.created_at')
            ->orderByDesc('n.id');
    }

    /** @return array<string, mixed> */
    public static function row(object $n): array
    {
        return [
            'id' => $n->uuid,
            'title' => $n->title,
            'category' => ['code' => $n->category_code, 'name' => $n->category_name],
            'priority' => $n->priority,
            'status' => $n->status,
            'target_type' => $n->person_code === null ? 'FAMILY' : 'PERSON',
            'target' => $n->person_code === null
                ? ['code' => $n->family_code, 'name' => $n->head_name]
                : ['code' => $n->person_code, 'name' => $n->person_name],
            'family_code' => $n->family_code,
            'created_date' => ReportPage::date($n->created_at),
            'resolved_date' => ReportPage::date($n->resolved_at),
        ];
    }
}
