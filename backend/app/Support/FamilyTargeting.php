<?php

namespace App\Support;

use App\Enums\AssessmentStatus;
use App\Enums\FamilyStatus;
use App\Enums\HealthRecordType;
use App\Enums\LifeStatus;
use App\Enums\NeedPriority;
use App\Enums\NeedStatus;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\Family;
use App\Models\FamilyNeed;
use App\Models\Person;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Evaluates normalized TargetingCriteria against current registry data
 * (docs/03-BUSINESS-RULES.md §47b). Everything is derived on read; no
 * match result is ever stored.
 *
 * Population: ACTIVE, non-deleted Families. "Members" are persons with an
 * ACTIVE membership who are not DECEASED — the same population as the
 * family health indicators (App\Support\FamilyHealthSummary).
 *
 * The query may use sensitive data internally (health records, needs,
 * assessments); summarize() returns only minimal yes/no indicators.
 */
class FamilyTargeting
{
    /**
     * @param  array<string, mixed>  $c  Normalized criteria.
     */
    public static function query(array $c, CarbonInterface $today): Builder
    {
        $query = Family::query()->where('status', FamilyStatus::ACTIVE);

        // -- family size (active living members)
        if (isset($c['min_family_members'])) {
            $query->whereHas('memberships', self::livingMember(), '>=', $c['min_family_members']);
        }
        if (isset($c['max_family_members'])) {
            $query->whereHas('memberships', self::livingMember(), '<=', $c['max_family_members']);
        }

        // -- displacement (current residence)
        if (isset($c['displacement_status']) || isset($c['displacement_location_text'])) {
            $query->whereHas('currentResidence', function (Builder $r) use ($c) {
                if (isset($c['displacement_status'])) {
                    $r->where('displacement_status', $c['displacement_status']);
                }
                if (isset($c['displacement_location_text'])) {
                    $r->whereRaw('LOWER(displacement_location_text) LIKE ?', ['%'.mb_strtolower($c['displacement_location_text']).'%']);
                }
            });
        }

        // -- children under two (same DOB rule as FamilyHealthSummary::isUnderTwo)
        $underTwo = self::livingMember(fn (Builder $p) => self::underTwo($p, $today));
        if (($c['has_child_under_two'] ?? null) === false) {
            $query->whereDoesntHave('memberships', $underTwo);
        } elseif (($c['has_child_under_two'] ?? false) === true || isset($c['min_children_under_two'])) {
            $query->whereHas('memberships', $underTwo, '>=', $c['min_children_under_two'] ?? 1);
        }

        // -- active health records of living active members
        foreach (self::healthCriteria() as $key => $type) {
            if (! array_key_exists($key, $c)) {
                continue;
            }
            $withRecord = self::livingMember(
                fn (Builder $p) => $p->whereHas('healthRecords', fn (Builder $h) => $h->active()->where('type', $type))
            );
            $c[$key]
                ? $query->whereHas('memberships', $withRecord)
                : $query->whereDoesntHave('memberships', $withRecord);
        }

        // -- open needs
        if (isset($c['need_category_code']) || isset($c['need_priorities'])) {
            $query->whereHas('needs', fn (Builder $n) => self::matchingNeed($n, $c));
        }

        // -- latest completed assessment result for the domain
        if (isset($c['assessment_domain_code'])) {
            $query->whereExists(fn (QueryBuilder $q) => self::latestResult($q, $c['assessment_domain_code'])
                ->whereColumn('a.family_id', 'families.id')
                ->when(isset($c['assessment_ratings']), fn ($q) => $q->whereIn('r.rating', $c['assessment_ratings'])));
        }

        return $query;
    }

    /**
     * Minimal eligibility indicators for the given page of matching
     * families — only for the criteria actually used. Never disease names,
     * disability details, notes or descriptions.
     *
     * @param  Collection<int, Family>  $families
     * @param  array<string, mixed>  $c
     * @return array<int, array<string, mixed>> keyed by family id
     */
    public static function summarize(Collection $families, array $c, CarbonInterface $today, Assistance $assistance): array
    {
        $ids = $families->pluck('id');
        $persons = Person::query()
            ->where('life_status', '!=', LifeStatus::DECEASED->value)
            ->whereHas('activeMembership', fn ($q) => $q->whereIn('family_id', $ids))
            ->with(['activeMembership:id,person_id,family_id', 'healthRecords' => fn ($q) => $q->active()->select('id', 'person_id', 'type')])
            ->get(['id', 'birth_date'])
            ->groupBy(fn (Person $p) => $p->activeMembership->family_id);

        $nominated = AssistanceBeneficiary::query()
            ->where('assistance_id', $assistance->id)
            ->whereNull('person_id')
            ->where('status', '!=', 'REMOVED')
            ->whereIn('family_id', $ids)
            ->pluck('family_id')
            ->flip();

        $needs = collect();
        if (isset($c['need_category_code']) || isset($c['need_priorities'])) {
            $needs = FamilyNeed::query()
                ->whereIn('family_id', $ids)
                ->where(fn (Builder $n) => self::matchingNeed($n, $c))
                ->with('category:id,code,name')
                ->orderByRaw(NeedPriority::orderSql())
                ->get(['id', 'family_id', 'need_category_id', 'priority'])
                ->groupBy('family_id');
        }

        $results = collect();
        if (isset($c['assessment_domain_code'])) {
            $results = self::latestResult(DB::query(), $c['assessment_domain_code'])
                ->whereIn('a.family_id', $ids)
                ->join('assessment_domains as d', 'd.id', '=', 'r.assessment_domain_id')
                ->get(['a.family_id', 'r.rating', 'a.assessment_date', 'd.code', 'd.name'])
                ->keyBy('family_id');
        }

        $summary = [];
        foreach ($families as $family) {
            $members = $persons->get($family->id, collect());
            $hasType = fn (HealthRecordType $t) => $members->contains(fn (Person $p) => $p->healthRecords->contains('type', $t));

            $indicators = [];
            if (($c['has_child_under_two'] ?? false) === true || isset($c['min_children_under_two'])) {
                $indicators['children_under_two'] = $members
                    ->filter(fn (Person $p) => FamilyHealthSummary::isUnderTwo($p, $today))
                    ->count();
            }
            foreach (self::healthCriteria() as $key => $type) {
                // Only positive criteria get an indicator ("يوجد ...").
                if (($c[$key] ?? null) === true) {
                    $indicators[$key] = $hasType($type);
                }
            }

            $familyNeeds = $needs->get($family->id);
            $result = $results->get($family->id);

            $summary[$family->id] = [
                'member_count' => $members->count(),
                'indicators' => (object) $indicators,
                'matching_need' => $familyNeeds ? [
                    'category' => ['code' => $familyNeeds->first()->category->code, 'name' => $familyNeeds->first()->category->name],
                    'priority' => $familyNeeds->first()->priority,
                    'count' => $familyNeeds->count(),
                ] : null,
                'matching_assessment' => $result ? [
                    'domain' => ['code' => $result->code, 'name' => $result->name],
                    'rating' => $result->rating,
                    'assessment_date' => substr((string) $result->assessment_date, 0, 10),
                ] : null,
                'already_nominated' => $nominated->has($family->id),
            ];
        }

        return $summary;
    }

    /** @return array<string, HealthRecordType> */
    private static function healthCriteria(): array
    {
        return [
            'has_pregnant_member' => HealthRecordType::PREGNANCY,
            'has_breastfeeding_member' => HealthRecordType::BREASTFEEDING,
            'has_member_with_disability' => HealthRecordType::DISABILITY,
            'has_member_with_chronic_disease' => HealthRecordType::CHRONIC_DISEASE,
        ];
    }

    /** Active membership of a living person, optionally constrained further. */
    private static function livingMember(?callable $person = null): callable
    {
        return fn (Builder $m) => $m->where('is_active', true)->whereHas('person', function (Builder $p) use ($person) {
            $p->where('life_status', '!=', LifeStatus::DECEASED->value);
            if ($person) {
                $person($p);
            }
        });
    }

    /** Has not yet reached their second birthday today (FamilyHealthSummary::isUnderTwo). */
    private static function underTwo(Builder $person, CarbonInterface $today): void
    {
        $person->whereNotNull('birth_date')
            ->whereDate('birth_date', '<=', $today->toDateString())
            ->whereDate('birth_date', '>', $today->copy()->subYears(2)->toDateString());
    }

    /** OPEN need of the category (if given) with one of the priorities (if given). */
    private static function matchingNeed(Builder $need, array $c): void
    {
        $need->where('status', NeedStatus::OPEN)
            ->when(isset($c['need_category_code']), fn ($q) => $q->whereHas('category', fn ($cat) => $cat->where('code', $c['need_category_code'])))
            ->when(isset($c['need_priorities']), fn ($q) => $q->whereIn('priority', $c['need_priorities']));
    }

    /**
     * For each family: the result for $domainCode in its most recent
     * COMPLETED assessment that rated that domain (latest assessment_date,
     * then latest completed_at, then highest id). DRAFT assessments never
     * count. Aliases: a = assessments, r = assessment_results.
     */
    private static function latestResult(QueryBuilder $query, string $domainCode): QueryBuilder
    {
        $domainId = DB::table('assessment_domains')->where('code', $domainCode)->value('id');

        return self::latestCompletedResults($query)->where('r.assessment_domain_id', $domainId);
    }

    /**
     * Every family's latest COMPLETED result per domain (one row per
     * family + domain it has ever had rated), using the same ordering as
     * targeting. A domain never rated has no row — it is not NONE. Shared
     * by the Operational Dashboard. Aliases: a = assessments,
     * r = assessment_results.
     */
    public static function latestCompletedResults(QueryBuilder $query): QueryBuilder
    {
        return $query->from('assessment_results as r')
            ->join('assessments as a', 'a.id', '=', 'r.assessment_id')
            ->where('a.status', AssessmentStatus::COMPLETED->value)
            ->whereNotExists(fn (QueryBuilder $newer) => $newer
                ->from('assessment_results as r2')
                ->join('assessments as a2', 'a2.id', '=', 'r2.assessment_id')
                ->whereColumn('a2.family_id', 'a.family_id')
                ->where('a2.status', AssessmentStatus::COMPLETED->value)
                ->whereColumn('r2.assessment_domain_id', 'r.assessment_domain_id')
                ->where(fn ($q) => $q
                    ->whereColumn('a2.assessment_date', '>', 'a.assessment_date')
                    ->orWhere(fn ($q) => $q->whereColumn('a2.assessment_date', 'a.assessment_date')->whereColumn('a2.completed_at', '>', 'a.completed_at'))
                    ->orWhere(fn ($q) => $q->whereColumn('a2.assessment_date', 'a.assessment_date')->whereColumn('a2.completed_at', 'a.completed_at')->whereColumn('a2.id', '>', 'a.id'))));
    }
}
