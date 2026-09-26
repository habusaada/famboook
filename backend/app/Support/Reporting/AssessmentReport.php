<?php

namespace App\Support\Reporting;

use App\Enums\AssessmentStatus;
use App\Support\FamilyTargeting;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Report 4 — Assessments (docs/03 §55b). The analytical table is the
 * latest family/domain state (latest COMPLETED assessment that rated the
 * domain — the rule shared with targeting and the Dashboard); the
 * lifecycle counts are assessment records. Never assessment notes.
 */
final class AssessmentReport
{
    public const NOT_ASSESSED = 'NOT_ASSESSED';

    public function __construct(
        private readonly OrganizationalScope $scope,
        private readonly CarbonInterface $today,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $lifecycle = DB::table('assessments')
            ->whereIn('family_id', $this->scope->familyIds())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            ...(new PopulationAggregates($this->scope, $this->today))->assessmentDomains(),
            // Assessment records (not families) by lifecycle status.
            'lifecycle' => [
                'draft' => (int) ($lifecycle[AssessmentStatus::DRAFT->value] ?? 0),
                'completed' => (int) ($lifecycle[AssessmentStatus::COMPLETED->value] ?? 0),
            ],
        ];
    }

    /**
     * Families of the scope currently in a domain/rating bucket: their
     * latest completed result for the domain has $rating, or — for
     * NOT_ASSESSED — no completed assessment ever rated the domain.
     */
    public function familiesQuery(int $domainId, string $rating): Builder
    {
        if ($rating === self::NOT_ASSESSED) {
            $query = DB::table('families as f')
                ->whereIn('f.id', $this->scope->familyIds())
                ->whereNotExists(fn (Builder $q) => $q->from('assessment_results as r0')
                    ->join('assessments as a0', 'a0.id', '=', 'r0.assessment_id')
                    ->whereColumn('a0.family_id', 'f.id')
                    ->where('a0.status', AssessmentStatus::COMPLETED->value)
                    ->where('r0.assessment_domain_id', $domainId))
                ->selectRaw('NULL as assessment_uuid, NULL as assessment_date, NULL as rating');
        } else {
            $query = FamilyTargeting::latestCompletedResults(DB::query())
                ->where('r.assessment_domain_id', $domainId)
                ->where('r.rating', $rating)
                ->whereIn('a.family_id', $this->scope->familyIds())
                ->join('families as f', 'f.id', '=', 'a.family_id')
                ->select(['a.uuid as assessment_uuid', 'a.assessment_date', 'r.rating']);
        }

        return OrganizationalScope::joinHouseholdHead($query)
            ->leftJoin('branches as b', 'b.id', '=', 'f.branch_id')
            ->addSelect(['f.family_code', 'hp.full_name as head_name', 'b.name as branch_name'])
            ->orderBy('f.family_code');
    }

    /** @return array<string, mixed> */
    public static function row(object $r): array
    {
        return [
            'family_code' => $r->family_code,
            'household_head' => $r->head_name,
            'branch' => $r->branch_name,
            'assessment_id' => $r->assessment_uuid,
            'assessment_date' => ReportPage::date($r->assessment_date),
            'rating' => $r->rating ?? self::NOT_ASSESSED,
        ];
    }
}
