<?php

namespace App\Support\Reporting;

use App\Enums\AssessmentRating;
use App\Enums\DisplacementStatus;
use App\Enums\HealthRecordType;
use App\Models\AssessmentDomain;
use App\Support\FamilyTargeting;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate figures over an OrganizationalScope, shared by the Operational
 * Dashboard and Reports so both always agree (docs/03 §55a–§55b). All
 * grouped SQL; nothing is stored. Aggregates only — no person-level data.
 */
final class PopulationAggregates
{
    /** Approved Dashboard/Reports V1 age bands, youngest first (docs/02 §75). */
    public const AGE_BANDS = ['UNDER_2', 'AGE_2_5', 'AGE_6_17', 'AGE_18_59', 'AGE_60_PLUS', 'UNKNOWN'];

    public function __construct(
        private readonly OrganizationalScope $scope,
        private readonly CarbonInterface $today,
    ) {}

    // ------------------------------------------------------------ population

    /** @return array<string, mixed> */
    public function demographics(): array
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

    public function displacedFamilies(): int
    {
        return $this->currentResidences()->where('r.displacement_status', DisplacementStatus::DISPLACED->value)->count();
    }

    /** @return array<string, mixed> */
    public function displacement(int $topLocations = 5): array
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
            ->limit($topLocations)
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
    public function health(): array
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
            ->orderBy('t.name')
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

    // ----------------------------------------------------------- assessments

    /**
     * Per domain: the rating distribution of each scoped family's latest
     * COMPLETED assessment that rated the domain (the targeting rule).
     * Drafts never count; a domain without a rating is "not assessed",
     * never NONE.
     *
     * @return array<string, mixed>
     */
    public function assessmentDomains(): array
    {
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
}
