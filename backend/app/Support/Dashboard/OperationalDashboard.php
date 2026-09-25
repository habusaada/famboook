<?php

namespace App\Support\Dashboard;

use App\Enums\AssessmentRating;
use App\Enums\AssistanceStatus;
use App\Enums\BeneficiaryStatus;
use App\Enums\DisplacementStatus;
use App\Enums\ExecutionMode;
use App\Enums\HealthRecordType;
use App\Enums\NeedPriority;
use App\Enums\NeedStatus;
use App\Http\Resources\DashboardActivityResource;
use App\Models\AssessmentDomain;
use App\Models\AssistanceBeneficiary;
use App\Models\FamilyActivity;
use App\Models\FamilyNeed;
use App\Models\PersonHealthRecord;
use App\Models\User;
use App\Support\FamilyActivityVisibility;
use App\Support\FamilyTargeting;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Operational Dashboard V1 (docs/03 §55a). Every figure is an aggregate
 * derived on read from canonical data — nothing is stored or cached. All
 * sections count from the same DashboardScope population, in grouped SQL
 * (no per-family / per-domain queries).
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
    /** Approved Dashboard/Reports V1 age bands, youngest first. */
    public const AGE_BANDS = ['UNDER_2', 'AGE_2_5', 'AGE_6_17', 'AGE_18_59', 'AGE_60_PLUS', 'UNKNOWN'];

    public const RECENT_ACTIVITY_LIMIT = 10;

    public function __construct(
        private readonly DashboardScope $scope,
        private readonly User $user,
        private readonly CarbonInterface $today,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $can = fn (string $permission) => $this->user->can($permission);
        $families = $can('family.view');
        $people = $families && $can('person.view');

        return [
            'scope' => $this->scope->toArray(),
            'generated_at' => now()->toIso8601String(),
            'as_of_date' => $this->today->toDateString(),
            'kpis' => [
                'active_families' => $families ? $this->scope->familyIds()->count() : null,
                'current_people' => $people ? $this->scope->currentMembers()->count() : null,
                'displaced_families' => $families ? $this->displacedFamilies() : null,
                'open_needs' => $can('need.view') ? $this->openNeeds()->count() : null,
            ],
            'demographics' => $people ? $this->demographics() : null,
            'displacement' => $families ? $this->displacement() : null,
            'health' => $people && $can('health-record.view') ? $this->health() : null,
            'needs' => $can('need.view') ? $this->needs() : null,
            'assessments' => $families && $can('assessment.view') ? $this->assessments() : null,
            'assistance' => $can('assistance.view') ? $this->assistance() : null,
            'recent_activity' => $can('activity-log.view') ? $this->recentActivity($request) : null,
        ];
    }

    // ------------------------------------------------------------ population

    /** @return array<string, mixed> */
    private function demographics(): array
    {
        $dob = DB::getDriverName() === 'sqlite' ? 'date(p.birth_date)' : 'p.birth_date';
        $cutoff = fn (int $years) => $this->today->copy()->subYears($years)->toDateString();

        // Age from date of birth relative to today (never stored). "Age >= N"
        // means born on or before today minus N years, so a person turns
        // 2 / 6 / 18 / 60 exactly on their birthday. A missing or future
        // date of birth is UNKNOWN.
        $band = "CASE WHEN {$dob} IS NULL OR {$dob} > ? THEN 'UNKNOWN'"
            ." WHEN {$dob} > ? THEN 'UNDER_2'"
            ." WHEN {$dob} > ? THEN 'AGE_2_5'"
            ." WHEN {$dob} > ? THEN 'AGE_6_17'"
            ." WHEN {$dob} > ? THEN 'AGE_18_59'"
            ." ELSE 'AGE_60_PLUS' END";

        $rows = DB::query()
            ->fromSub(
                $this->scope->currentMembers()->selectRaw(
                    "p.gender as gender, {$band} as band",
                    [$this->today->toDateString(), $cutoff(2), $cutoff(6), $cutoff(18), $cutoff(60)],
                ),
                'people',
            )
            ->selectRaw('gender, band, count(*) as total')
            ->groupBy('gender', 'band')
            ->get();

        $gender = ['MALE' => 0, 'FEMALE' => 0, 'UNKNOWN' => 0];
        $bands = array_fill_keys(self::AGE_BANDS, 0);
        foreach ($rows as $row) {
            $key = in_array($row->gender, ['MALE', 'FEMALE'], true) ? $row->gender : 'UNKNOWN';
            $gender[$key] += (int) $row->total;
            $bands[$row->band] += (int) $row->total;
        }

        return [
            'total' => array_sum($gender),
            'gender' => [
                'male' => $gender['MALE'],
                'female' => $gender['FEMALE'],
                'unknown' => $gender['UNKNOWN'],
            ],
            'age_bands' => array_map(fn (string $code) => ['code' => $code, 'count' => $bands[$code]], self::AGE_BANDS),
        ];
    }

    private function currentResidences(): Builder
    {
        return DB::table('family_residences as r')
            ->where('r.is_current', true)
            ->whereIn('r.family_id', $this->scope->familyIds());
    }

    private function displacedFamilies(): int
    {
        return $this->currentResidences()->where('r.displacement_status', DisplacementStatus::DISPLACED->value)->count();
    }

    /** @return array<string, mixed> */
    private function displacement(): array
    {
        $families = $this->scope->familyIds()->count();
        $byStatus = $this->currentResidences()
            ->selectRaw('r.displacement_status as status, count(*) as total')
            ->groupBy('r.displacement_status')
            ->pluck('total', 'status');
        $displaced = (int) ($byStatus[DisplacementStatus::DISPLACED->value] ?? 0);
        $notDisplaced = (int) ($byStatus[DisplacementStatus::NOT_DISPLACED->value] ?? 0);

        // Exact stored free text only — no normalization or fuzzy merging.
        $locations = $this->currentResidences()
            ->where('r.displacement_status', DisplacementStatus::DISPLACED->value)
            ->whereNotNull('r.displacement_location_text')
            ->where('r.displacement_location_text', '!=', '')
            ->selectRaw('r.displacement_location_text as location, count(*) as total')
            ->groupBy('r.displacement_location_text')
            ->orderByDesc('total')
            ->orderBy('location')
            ->limit(5)
            ->get();

        return [
            'total_families' => $families,
            'displaced' => $displaced,
            'not_displaced' => $notDisplaced,
            // No current residence, or displacement never recorded.
            'unknown' => $families - $displaced - $notDisplaced,
            'top_locations' => $locations->map(fn ($l) => ['location' => $l->location, 'families' => (int) $l->total])->all(),
        ];
    }

    // ---------------------------------------------------------------- health

    /** @return array<string, mixed> */
    private function health(): array
    {
        $active = fn () => DB::table('person_health_records as h')
            ->whereNull('h.ended_at')
            ->whereIn('h.person_id', $this->scope->currentPersonIds());

        // Distinct people per type, not number of records.
        $byType = $active()
            ->selectRaw('h.type as type, count(distinct h.person_id) as total')
            ->groupBy('h.type')
            ->pluck('total', 'type');
        $count = fn (HealthRecordType $t) => (int) ($byType[$t->value] ?? 0);

        // Reference disability type only — never condition names or details.
        $disabilities = $active()
            ->where('h.type', HealthRecordType::DISABILITY->value)
            ->leftJoin('disability_types as t', 't.id', '=', 'h.disability_type_id')
            ->selectRaw('t.code as code, t.name as name, count(distinct h.person_id) as total')
            ->groupBy('t.code', 't.name')
            ->orderByDesc('total')
            ->get();

        return [
            'people_with_disability' => $count(HealthRecordType::DISABILITY),
            'people_with_chronic_disease' => $count(HealthRecordType::CHRONIC_DISEASE),
            'active_pregnancy' => $count(HealthRecordType::PREGNANCY),
            'active_breastfeeding' => $count(HealthRecordType::BREASTFEEDING),
            'disability_types' => $disabilities->map(fn ($d) => [
                'code' => $d->code,
                'name' => $d->name,
                'people' => (int) $d->total,
            ])->all(),
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

    // ----------------------------------------------------------- assessments

    /** @return array<string, mixed> */
    private function assessments(): array
    {
        // Per family + domain: its latest COMPLETED assessment that rated
        // the domain (same rule as targeting). Drafts never count; a domain
        // without a rating is "not assessed", never NONE.
        $rows = FamilyTargeting::latestCompletedResults(DB::query())
            ->whereIn('a.family_id', $this->scope->familyIds())
            ->selectRaw('r.assessment_domain_id as domain_id, r.rating as rating, count(*) as total')
            ->groupBy('r.assessment_domain_id', 'r.rating')
            ->get()
            ->groupBy('domain_id');

        $families = $this->scope->familyIds()->count();
        $domains = AssessmentDomain::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $rows->keys()))
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name', 'is_active']);

        return [
            'total_families' => $families,
            'domains' => $domains->map(function (AssessmentDomain $domain) use ($rows, $families) {
                $ratings = array_fill_keys(array_map(fn (AssessmentRating $r) => $r->value, AssessmentRating::cases()), 0);
                foreach ($rows->get($domain->id, collect()) as $row) {
                    $ratings[$row->rating] = (int) $row->total;
                }
                $assessed = array_sum($ratings);

                return [
                    'code' => $domain->code,
                    'name' => $domain->name,
                    'is_active' => $domain->is_active,
                    'ratings' => $ratings,
                    'assessed_families' => $assessed,
                    'not_assessed_families' => max($families - $assessed, 0),
                ];
            })->all(),
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
