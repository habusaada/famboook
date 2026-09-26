<?php

namespace App\Support\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Report 6 — Data Quality (docs/03 §55b). Actionable checks derived from
 * the canonical schema only: each issue is one SQL condition, counted in
 * one grouped query per entity and listed by the drill-down with safe
 * identifying and navigation fields. The drill-down never returns the
 * value in question (e.g. a National ID) — only which record needs
 * correction in the existing Family / Person screens.
 *
 * Deliberately not checked: Family branch/Clan mismatch (made impossible
 * by the composite foreign key), duplicates, and anything heuristic
 * (e.g. marital status from age).
 */
final class DataQualityReport
{
    public const COMPLETENESS = 'COMPLETENESS';

    public const CONSISTENCY = 'CONSISTENCY';

    /**
     * code => [entity, group, SQL condition]. Aliases: f = families (both
     * entities); p = persons, m = memberships (PERSON). Conditions contain
     * only canonical constants — no user input.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function issues(): array
    {
        $currentResidence = 'SELECT 1 FROM family_residences r WHERE r.family_id = f.id AND r.is_current = '.self::true();
        $activeHead = 'SELECT 1 FROM family_memberships hm WHERE hm.family_id = f.id AND hm.is_active = '.self::true().' AND hm.is_household_head = '.self::true();

        return [
            // -- completeness: families
            'FAMILY_WITHOUT_BRANCH' => ['FAMILY', self::COMPLETENESS, 'f.branch_id IS NULL'],
            'FAMILY_WITHOUT_CURRENT_RESIDENCE' => ['FAMILY', self::COMPLETENESS, "NOT EXISTS ({$currentResidence})"],
            // Allowed at registration, but the location is still missing.
            'DISPLACED_WITHOUT_LOCATION' => ['FAMILY', self::COMPLETENESS, "EXISTS ({$currentResidence} AND r.displacement_status = 'DISPLACED' AND (r.displacement_location_text IS NULL OR r.displacement_location_text = ''))"],
            // -- completeness: current people
            'PERSON_MISSING_NATIONAL_ID' => ['PERSON', self::COMPLETENESS, "(p.national_id IS NULL OR p.national_id = '')"],
            'PERSON_MISSING_BIRTH_DATE' => ['PERSON', self::COMPLETENESS, 'p.birth_date IS NULL'],
            'PERSON_MISSING_MOBILE' => ['PERSON', self::COMPLETENESS, "(p.mobile IS NULL OR p.mobile = '')"],
            'PERSON_UNKNOWN_GENDER' => ['PERSON', self::COMPLETENESS, 'p.gender IS NULL'],
            'PERSON_MARITAL_STATUS_UNKNOWN' => ['PERSON', self::COMPLETENESS, "p.marital_status = 'UNKNOWN'"],
            // -- consistency (provable states)
            // Registration always creates a head (at most one per family).
            'ACTIVE_FAMILY_WITHOUT_HEAD' => ['FAMILY', self::CONSISTENCY, "NOT EXISTS ({$activeHead})"],
            // docs/03 §16: a deceased head means "Family requires Household Head review".
            'HOUSEHOLD_HEAD_DECEASED' => ['FAMILY', self::CONSISTENCY, "EXISTS ({$activeHead} AND EXISTS (SELECT 1 FROM persons hx WHERE hx.id = hm.person_id AND hx.life_status = 'DECEASED'))"],
        ];
    }

    /** @return list<string> */
    public static function issueCodes(): array
    {
        return array_keys(self::issues());
    }

    public static function entityOf(string $issue): string
    {
        return self::issues()[$issue][0];
    }

    private static function true(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'true' : '1';
    }

    public function __construct(private readonly OrganizationalScope $scope) {}

    private function families(): Builder
    {
        return DB::table('families as f')->whereIn('f.id', $this->scope->familyIds());
    }

    private function people(): Builder
    {
        return $this->scope->currentMembers()->join('families as f', 'f.id', '=', 'm.family_id');
    }

    /** @return list<array<string, mixed>> */
    public function overview(): array
    {
        $counts = [];
        foreach (['FAMILY' => $this->families(), 'PERSON' => $this->people()] as $entity => $base) {
            $sums = [];
            foreach (self::issues() as $code => [$issueEntity, , $condition]) {
                if ($issueEntity === $entity) {
                    $sums[] = "SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END) as ".strtolower($code);
                }
            }
            $row = (array) $base->selectRaw(implode(', ', $sums))->first();
            foreach ($row as $key => $value) {
                $counts[strtoupper($key)] = (int) $value;
            }
        }

        return array_map(fn (string $code) => [
            'code' => $code,
            'entity' => self::issues()[$code][0],
            'group' => self::issues()[$code][1],
            'count' => $counts[$code] ?? 0,
        ], self::issueCodes());
    }

    /** Affected records of one issue, safe fields only. */
    public function recordsQuery(string $issue): Builder
    {
        [$entity, , $condition] = self::issues()[$issue];

        if ($entity === 'FAMILY') {
            return OrganizationalScope::joinHouseholdHead($this->families()->whereRaw($condition))
                ->join('clans as cl', 'cl.id', '=', 'f.clan_id')
                ->leftJoin('branches as b', 'b.id', '=', 'f.branch_id')
                ->select(['f.family_code', 'hp.full_name as head_name', 'cl.name as clan_name', 'b.name as branch_name'])
                ->orderBy('f.family_code');
        }

        return $this->people()
            ->whereRaw($condition)
            ->leftJoin('branches as b', 'b.id', '=', 'f.branch_id')
            ->select(['p.person_code', 'p.full_name', 'f.family_code', 'b.name as branch_name'])
            ->orderBy('p.person_code');
    }

    /** @return array<string, mixed> */
    public static function row(string $entity, object $r): array
    {
        return $entity === 'FAMILY'
            ? [
                'family_code' => $r->family_code,
                'household_head' => $r->head_name,
                'clan' => $r->clan_name,
                'branch' => $r->branch_name,
            ]
            : [
                'person_code' => $r->person_code,
                'full_name' => $r->full_name,
                'family_code' => $r->family_code,
                'branch' => $r->branch_name,
            ];
    }
}
