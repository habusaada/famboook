<?php

namespace App\Support\Dashboard;

use App\Enums\AssistanceStatus;
use App\Enums\BeneficiaryStatus;
use App\Enums\ExecutionMode;
use App\Enums\NeedPriority;
use App\Enums\NeedStatus;
use App\Http\Resources\DashboardActivityResource;
use App\Models\AssistanceBeneficiary;
use App\Models\FamilyActivity;
use App\Models\FamilyNeed;
use App\Models\PersonHealthRecord;
use App\Models\User;
use App\Support\FamilyActivityVisibility;
use App\Support\Reporting\OrganizationalScope;
use App\Support\Reporting\PopulationAggregates;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Operational Dashboard V1 (docs/03 §55a). Every figure is an aggregate
 * derived on read from canonical data — nothing is stored or cached. All
 * sections count from the shared OrganizationalScope population, with the
 * PopulationAggregates shared with Reports, in grouped SQL (no per-family /
 * per-domain queries).
 *
 * A section the user may not read is returned as null (never computed),
 * so the dashboard cannot bypass domain permissions:
 *   population figures   family.view (people and demographics: person.view)
 *   health               health-record.view
 *   needs / Open Needs   need.view
 *   assessments          assessment.view
 *   assistance           assistance.view
 *   recent activity      activity-log.view + event-level visibility
 *
 * Aggregates only: no names, codes of persons, National IDs, phone
 * numbers, disease names, health notes, Need descriptions or issued-list
 * snapshot values.
 */
final class OperationalDashboard
{
    public const RECENT_ACTIVITY_LIMIT = 10;

    private readonly PopulationAggregates $aggregates;

    public function __construct(
        private readonly OrganizationalScope $scope,
        private readonly User $user,
        CarbonInterface $today,
    ) {
        $this->aggregates = new PopulationAggregates($scope, $today);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $can = fn (string $permission) => $this->user->can($permission);
        $families = $can('family.view');
        $people = $families && $can('person.view');

        return [
            'scope' => $this->scope->toArray(),
            'generated_at' => now()->toIso8601String(),
            'as_of_date' => today()->toDateString(),
            'kpis' => [
                'active_families' => $families ? $this->scope->familyIds()->count() : null,
                'current_people' => $people ? $this->scope->currentMembers()->count() : null,
                'displaced_families' => $families ? $this->aggregates->displacedFamilies() : null,
                'open_needs' => $can('need.view') ? $this->openNeeds()->count() : null,
            ],
            'demographics' => $people ? $this->aggregates->demographics() : null,
            'displacement' => $families ? $this->aggregates->displacement() : null,
            'health' => $people && $can('health-record.view') ? $this->aggregates->health() : null,
            'needs' => $can('need.view') ? $this->needs() : null,
            'assessments' => $families && $can('assessment.view') ? $this->aggregates->assessmentDomains() : null,
            'assistance' => $can('assistance.view') ? $this->assistance() : null,
            'recent_activity' => $can('activity-log.view') ? $this->recentActivity($request) : null,
        ];
    }

    // ----------------------------------------------------------------- needs

    private function openNeeds(): Builder
    {
        return DB::table('family_needs as n')
            ->where('n.status', NeedStatus::OPEN->value)
            ->whereIn('n.family_id', $this->scope->familyIds());
    }

    /** @return array<string, mixed> */
    private function needs(): array
    {
        $byPriority = $this->openNeeds()
            ->selectRaw('n.priority as priority, count(*) as total')
            ->groupBy('n.priority')
            ->pluck('total', 'priority');

        $categories = $this->openNeeds()
            ->join('need_categories as c', 'c.id', '=', 'n.need_category_id')
            ->selectRaw('c.code as code, c.name as name, count(*) as total')
            ->groupBy('c.code', 'c.name')
            ->orderByDesc('total')
            ->orderBy('c.name')
            ->limit(8)
            ->get();

        return [
            'open' => (int) $byPriority->sum(),
            'families_with_open_needs' => $this->openNeeds()->distinct()->count('n.family_id'),
            // Most urgent first.
            'by_priority' => array_map(fn (NeedPriority $p) => [
                'priority' => $p->value,
                'count' => (int) ($byPriority[$p->value] ?? 0),
            ], [NeedPriority::URGENT, NeedPriority::HIGH, NeedPriority::MEDIUM, NeedPriority::LOW]),
            'top_categories' => $categories->map(fn ($c) => [
                'code' => $c->code,
                'name' => $c->name,
                'count' => (int) $c->total,
            ])->all(),
        ];
    }

    // ------------------------------------------------------------ assistance

    /**
     * Beneficiaries of OPEN Assistances whose target Family is in scope
     * (a person-level nominee counts through its stored family context).
     * Aliases: b = assistance_beneficiaries, s = assistances.
     */
    private function beneficiaries(ExecutionMode $mode): Builder
    {
        return DB::table('assistance_beneficiaries as b')
            ->join('assistances as s', 's.id', '=', 'b.assistance_id')
            ->where('s.status', AssistanceStatus::OPEN->value)
            ->where('s.execution_mode', $mode->value)
            ->whereIn('b.family_id', $this->scope->familyIds());
    }

    /** @return array<string, mixed> */
    private function assistance(): array
    {
        $activeDelivery = fn (Builder $q) => $q->from('assistance_deliveries as d')
            ->whereColumn('d.assistance_beneficiary_id', 'b.id')
            ->whereNull('d.reversed_at');
        $listed = fn (Builder $q) => $q->from('assistance_beneficiary_list_entries as e')
            ->whereColumn('e.assistance_beneficiary_id', 'b.id');

        $statusCounts = function (ExecutionMode $mode) {
            return $this->beneficiaries($mode)
                ->selectRaw('b.status as status, count(*) as total')
                ->groupBy('b.status')
                ->pluck('total', 'status');
        };
        $internal = $statusCounts(ExecutionMode::INTERNAL);
        $external = $statusCounts(ExecutionMode::EXTERNAL);
        $sum = fn ($counts, BeneficiaryStatus ...$statuses) => array_sum(array_map(fn ($s) => (int) ($counts[$s->value] ?? 0), $statuses));

        // A program is relevant only if it has a current (non-removed)
        // beneficiary in scope.
        $programs = fn (ExecutionMode $mode) => $this->beneficiaries($mode)
            ->where('b.status', '!=', BeneficiaryStatus::REMOVED->value)
            ->distinct()
            ->count('s.id');

        return [
            'open_programs' => [
                'internal' => $programs(ExecutionMode::INTERNAL),
                'external' => $programs(ExecutionMode::EXTERNAL),
            ],
            'internal' => [
                'nominated' => $sum($internal, BeneficiaryStatus::NOMINATED),
                // Ever approved and not rejected (as in AssistanceStatistics).
                'approved' => $sum($internal, BeneficiaryStatus::APPROVED, BeneficiaryStatus::NOT_DELIVERED),
                'awaiting_delivery' => $this->beneficiaries(ExecutionMode::INTERNAL)
                    ->where('b.status', BeneficiaryStatus::APPROVED->value)
                    ->whereNotExists($activeDelivery)
                    ->count(),
                // An active, non-reversed delivery exists.
                'delivered' => $this->beneficiaries(ExecutionMode::INTERNAL)->whereExists($activeDelivery)->count(),
                'not_delivered' => $sum($internal, BeneficiaryStatus::NOT_DELIVERED),
            ],
            // EXTERNAL: inclusion in an issued list is never a delivery.
            'external' => [
                'nominated' => $sum($external, BeneficiaryStatus::NOMINATED),
                'approved' => $sum($external, BeneficiaryStatus::APPROVED),
                'approved_not_listed' => $this->beneficiaries(ExecutionMode::EXTERNAL)
                    ->where('b.status', BeneficiaryStatus::APPROVED->value)
                    ->whereNotExists($listed)
                    ->count(),
                // Unique beneficiaries: appearing in several (e.g. corrected)
                // lists counts once.
                'listed_unique' => $this->beneficiaries(ExecutionMode::EXTERNAL)->whereExists($listed)->count(),
            ],
        ];
    }

    // --------------------------------------------------------------- activity

    /** @return array<int, mixed> */
    private function recentActivity(Request $request): array
    {
        $activities = FamilyActivityVisibility::apply(FamilyActivity::query(), $this->user)
            ->whereIn('family_id', $this->scope->familyIds())
            ->with([
                'family:id,family_code',
                'actor:id,name',
                'subject' => fn (MorphTo $morph) => $morph->morphWith([
                    PersonHealthRecord::class => ['person'],
                    FamilyNeed::class => ['person'],
                    AssistanceBeneficiary::class => ['person', 'assistance:id,title'],
                ]),
            ])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::RECENT_ACTIVITY_LIMIT)
            ->get();

        return DashboardActivityResource::collection($activities)->toArray($request);
    }
}
