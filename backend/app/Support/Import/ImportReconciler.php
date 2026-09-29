<?php

namespace App\Support\Import;

use App\Enums\FamilyKeyDecision;
use App\Enums\ImportReconciliationStatus as S;
use App\Models\ImportBatch;
use App\Support\NationalId;
use App\Support\NationalIdMask;
use Illuminate\Support\Facades\DB;

/**
 * Record reconciliation (docs/03 §96a): what does each staged row correspond
 * to in the PERMANENT registry? A pure, deterministic computation that only
 * READS the registry — it never writes Persons, Families, Memberships,
 * Residences or declarations.
 *
 * Person matching and Family matching are separate:
 *
 * - HEAD Person: an EXACT National ID match on the stored value (the same
 *   rule NationalIdGuard applies; PDD-001 normalization stays open). A match
 *   only after normalizing digits/formatting is surfaced, never used. Never
 *   by name: a missing ID is never deduplicated by name.
 * - Family: only through the matched Person's ACTIVE HOUSEHOLD-HEAD membership
 *   in a Family of the batch's Clan. Never by name, family key, Branch, phone,
 *   city, spouse or declared size.
 * - Spouses: matched separately (exact ID) as candidates only.
 *
 * Row status (one per row), strongest first:
 *   CONFLICT > DUPLICATE_IN_FILE > REVIEW_REQUIRED > CHANGED > UNCHANGED > NEW
 * CHANGED/UNCHANGED exist only with a deterministic Family match; otherwise a
 * clean row is NEW (possibly with an EXISTING Person candidate). Absence of a
 * registry record from the file is never examined — nothing is proposed for
 * deletion or deactivation.
 */
final class ImportReconciler
{
    // Issue code => row status it forces (null = informational only).
    public const ISSUES = [
        'HEAD_ID_EQUALS_SPOUSE_ID' => S::CONFLICT,
        'REGISTRY_MULTIPLE_PERSONS' => S::CONFLICT,
        'EXISTING_PERSON_NOT_HEAD' => S::CONFLICT,
        'FAMILY_IN_OTHER_CLAN' => S::CONFLICT,
        'DUPLICATE_HEAD_ID_IN_FILE' => S::DUPLICATE_IN_FILE,
        'HEAD_ALSO_SPOUSE_IN_FILE' => S::REVIEW_REQUIRED,
        // The same wife ID in several households (see spouseGroups()).
        'SPOUSE_SHARED_BY_LIVING_HEADS' => S::REVIEW_REQUIRED,
        'SPOUSE_REPEATED_HEAD_STATUS_UNKNOWN' => S::REVIEW_REQUIRED,
        'DUPLICATE_SPOUSE_IN_ROW' => S::REVIEW_REQUIRED,
        'SPOUSE_IN_OTHER_FAMILY' => S::REVIEW_REQUIRED,
        'SPOUSE_MULTIPLE_PERSONS' => S::REVIEW_REQUIRED,
        'SPOUSE_NATIONAL_ID_FORMAT_VARIANT' => S::REVIEW_REQUIRED,
        'DELETED_PERSON_MATCH' => S::REVIEW_REQUIRED,
        'NATIONAL_ID_FORMAT_VARIANT' => S::REVIEW_REQUIRED,
        'EXISTING_PERSON_IDENTITY_MISMATCH' => S::REVIEW_REQUIRED,
        'NO_ID_NAME_AMBIGUITY' => S::REVIEW_REQUIRED,
        'LIFE_STATUS_INCONSISTENT' => S::REVIEW_REQUIRED,
        'UNMAPPED_SOURCE_VALUE' => S::REVIEW_REQUIRED,
        'STAGING_REJECTED' => S::REVIEW_REQUIRED,
        'EXISTING_PERSON_NO_FAMILY' => null,
        'SPOUSE_EXISTING_PERSON' => null,
        // Widow remarriage: every other head of the group is DECEASED — allowed.
        'SPOUSE_REPEATED_AFTER_HEAD_DEATH' => null,
        // Accepted polygamous pair: she heads her own household (polygamyLinks()).
        'POLYGAMY_INDEPENDENT_WIFE_HOUSEHOLD' => null,
    ];

    private const PRECEDENCE = [S::CONFLICT, S::DUPLICATE_IN_FILE, S::REVIEW_REQUIRED];

    /**
     * @return list<array{import_row_id: int, status: S, head_match: string, head_person_id: ?int, family_match: string, family_id: ?int, spouse_matches: ?array, differences: ?array, issues: ?array}>
     */
    public static function reconcile(ImportBatch $batch): array
    {
        $rows = DB::table('import_rows')->where('import_batch_id', $batch->id)->orderBy('row_number')
            ->get(['id', 'row_number', 'status', 'source_family_key', 'normalized_payload'])
            ->map(function ($r) {
                $r->n = json_decode($r->normalized_payload, true) ?? [];

                return $r;
            });

        $resolutions = DB::table('import_family_key_resolutions')->where('import_batch_id', $batch->id)
            ->get(['source_family_key', 'decision', 'branch_id'])->keyBy('source_family_key');

        // ---- in-file indexes (exact staged values) -------------------------
        $headRows = [];
        $spouseRows = [];
        $names = [];
        foreach ($rows as $r) {
            if ($id = self::id($r->n['national_id'] ?? null)) {
                $headRows[$id][] = $r->row_number;
            }
            foreach (self::spouseIds($r->n) as $id) {
                $spouseRows[$id][] = $r->row_number;
            }
            if ($name = InitialFamilyRow::normalizeKey($r->n['full_name'] ?? null)) {
                $names[$name][] = $r->row_number;
            }
        }
        foreach ($spouseRows as $id => $list) {
            $spouseRows[$id] = array_values(array_unique($list));
        }
        $links = self::polygamyLinks($rows, $headRows);
        $spouseGroups = self::spouseGroups($rows, $spouseRows, $links);

        // ---- registry lookups (read only) ----------------------------------
        $allIds = array_values(array_unique([...array_keys($headRows), ...array_keys($spouseRows)]));
        $registry = self::registry($allIds);
        // Same-name registry Persons: weak review context for no-ID heads only.
        $registryNames = [];
        foreach (array_chunk(array_keys($names), 1000) as $chunk) {
            foreach (DB::table('persons')->whereNull('deleted_at')->whereIn('full_name', $chunk)->pluck('full_name') as $name) {
                $registryNames[$name] = ($registryNames[$name] ?? 0) + 1;
            }
        }

        $results = [];
        foreach ($rows as $r) {
            $results[$r->row_number] = self::row($r, $batch, $resolutions, $headRows, $spouseRows, $spouseGroups, $links, $names, $registry, $registryNames);
        }

        return array_values(self::enforceIndependentConflicts($results));
    }

    /**
     * Polygamous-household links (docs/03 §96a). A pair is accepted as two
     * independent source households when ALL hold:
     *
     * - the husband's row: MALE, ALIVE, exact marital status "متعدد الزوجات";
     * - the woman's exact National ID is in one of his wife slots;
     * - she heads her own source row, and that row names HIM back by his exact
     *   National ID (reciprocal).
     *
     * Any wife slot qualifies. Slot position (and his last non-empty slot) is
     * kept as source evidence only — it never decides who belongs to which
     * Family. Never by name, never by Excel row order, and polygamy is never
     * inferred from several women naming the same man. A deceased or
     * unknown-status husband is left to the ordinary rules (review / widow).
     * Condition "no other conflict" is enforced by enforceIndependentConflicts().
     *
     * @param  array<string, list<int>>  $headRows
     * @return array<string, array{husband_row: int, husband_id: string, wife_id: string, wife_rows: list<int>, slot: int, last_slot: int}> keyed "husbandRow|wifeId"
     */
    private static function polygamyLinks($rows, array $headRows): array
    {
        $spousesOf = [];
        foreach ($rows as $r) {
            $spousesOf[$r->row_number] = self::spouseIds($r->n);
        }

        $links = [];
        foreach ($rows as $r) {
            $n = $r->n;
            $husband = self::id($n['national_id'] ?? null);
            if ($husband === null
                || SourceValues::map(SourceValues::GENDER, $n['gender'] ?? null) !== 'MALE'
                || SourceValues::map(SourceValues::LIFE_STATUS, $n['life_status_source'] ?? null) !== 'ALIVE'
                || trim((string) ($n['marital_status'] ?? '')) !== SourceValues::POLYGAMOUS) {
                continue;
            }
            $slots = [];
            foreach (range(1, InitialFamilyWorkbook::MAX_WIVES) as $k) {
                if ($id = self::id($n["wife_{$k}_national_id"] ?? null)) {
                    $slots[$k] ??= $id;
                }
            }
            if ($slots === []) {
                continue;
            }
            $last = max(array_keys($slots));
            foreach ($slots as $k => $id) {
                // Her own head rows that name this exact husband back.
                $wifeRows = array_values(array_filter(
                    array_diff($headRows[$id] ?? [], [$r->row_number]),
                    fn (int $w) => in_array($husband, $spousesOf[$w], true),
                ));
                if ($wifeRows === [] || isset($links[$r->row_number.'|'.$id])) {
                    continue; // no reciprocal head row / same wife twice (DUPLICATE_SPOUSE_IN_ROW)
                }
                $links[$r->row_number.'|'.$id] = [
                    'husband_row' => $r->row_number,
                    'husband_id' => $husband,
                    'wife_id' => $id,
                    'wife_rows' => $wifeRows,
                    'slot' => $k,
                    'last_slot' => $last,
                ];
            }
        }

        return $links;
    }

    /**
     * The polygamous link a HEAD ↔ SPOUSE occurrence belongs to, if any.
     * $headRow is where $id is HEAD, $spouseRow where $id is listed as spouse:
     * the woman (head of her row, listed in his) or the husband (head of his
     * row, listed back in hers).
     */
    private static function linkFor(array $links, string $id, int $headRow, int $spouseRow): ?array
    {
        $link = $links[$spouseRow.'|'.$id] ?? null;
        if ($link !== null && in_array($headRow, $link['wife_rows'], true)) {
            return $link;
        }
        foreach ($links as $l) {
            if ($l['husband_row'] === $headRow && $l['husband_id'] === $id && in_array($spouseRow, $l['wife_rows'], true)) {
                return $l;
            }
        }

        return null;
    }

    /**
     * Condition 6: a polygamous link is accepted only while no row of the
     * link (husband's row and the wife's head row) is blocked by anything else.
     * Otherwise its occurrences fall back to HEAD_ALSO_SPOUSE_IN_FILE. Repeated
     * until stable (a fallback may block another link sharing a row).
     *
     * @param  array<int, array>  $results  keyed by row number
     */
    private static function enforceIndependentConflicts(array $results): array
    {
        do {
            $changed = false;
            foreach ($results as $n => $result) {
                foreach ($result['issues'] ?? [] as $i => $issue) {
                    if ($issue['code'] !== 'POLYGAMY_INDEPENDENT_WIFE_HOUSEHOLD') {
                        continue;
                    }
                    $linkRows = [$issue['context']['husband_row'], ...$issue['context']['wife_rows']];
                    $blocked = collect($linkRows)->contains(fn ($row) => in_array($results[$row]['status'] ?? null, self::PRECEDENCE, true));
                    if ($blocked) {
                        $results[$n]['issues'][$i]['code'] = 'HEAD_ALSO_SPOUSE_IN_FILE';
                        $results[$n]['status'] = self::status($results[$n]['issues'], $result['family_match'], $result['differences'] ?? []);
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return $results;
    }

    /** Strongest forced status, else NEW / UNCHANGED / CHANGED. */
    private static function status(array $issues, string $familyMatch, array $differences): S
    {
        $forced = array_filter(array_map(fn ($i) => self::ISSUES[$i['code']], $issues));
        foreach (self::PRECEDENCE as $candidate) {
            if (in_array($candidate, $forced, true)) {
                return $candidate;
            }
        }

        return $familyMatch === 'EXISTING_FAMILY' ? ($differences === [] ? S::UNCHANGED : S::CHANGED) : S::NEW;
    }

    /**
     * Fingerprint of everything a reconciliation depends on: the staged rows
     * (mapping confirmation), the key decisions and cheap registry markers.
     * A different current fingerprint means the stored result is STALE.
     */
    public static function fingerprint(ImportBatch $batch): string
    {
        $marker = fn (string $table, bool $trashed = false) => DB::table($table)
            ->selectRaw('count(*) as n, max(updated_at) as u'.($trashed ? ', max(deleted_at) as d' : ''))->first();

        return hash('sha256', json_encode([
            'staging' => [$batch->mapping_confirmed_at?->toIso8601String(), $batch->row_count],
            'keys' => DB::table('import_family_key_resolutions')->where('import_batch_id', $batch->id)
                ->orderBy('source_family_key')->get(['source_family_key', 'decision', 'branch_id'])->all(),
            'registry' => [
                $marker('persons', true), $marker('families', true), $marker('family_memberships'),
                $marker('family_residences'), $marker('family_household_declarations'),
                DB::table('branches')->where('clan_id', $batch->clan_id)->selectRaw('count(*) as n, max(updated_at) as u')->first(),
            ],
        ]));
    }

    /**
     * Verdict for every wife ID that appears in two or more household rows,
     * from the canonical life status of ALL heads of the group (row order is
     * never marriage chronology; nothing is inferred from the repetition):
     *
     *   LIVING_HEADS    two or more heads are ALIVE → review (which marriage is
     *                   current cannot be decided)
     *   UNKNOWN_STATUS  otherwise, a head's status is blank/unmapped → review
     *                   (death is never assumed)
     *   AFTER_DEATH     every head except at most one is DECEASED → the repeated
     *                   wife is a legitimate widow remarriage; no review for it
     *
     * The wife stays ONE identity candidate (same exact National ID).
     *
     * Not a group: a polygamous husband's ID named as spouse only in the own
     * rows of wives with an accepted polygamy link (each row is judged by it).
     *
     * @param  array<string, list<int>>  $spouseRows
     * @return array<string, array{verdict: string, rows: list<int>, alive: int, deceased: int, unknown: int}>
     */
    private static function spouseGroups($rows, array $spouseRows, array $links): array
    {
        $linkedWifeRows = [];
        foreach ($links as $l) {
            $linkedWifeRows[$l['husband_id']] = [...($linkedWifeRows[$l['husband_id']] ?? []), ...$l['wife_rows']];
        }
        $life = [];
        foreach ($rows as $r) {
            $life[$r->row_number] = SourceValues::map(SourceValues::LIFE_STATUS, $r->n['life_status_source'] ?? null);
        }

        $groups = [];
        foreach ($spouseRows as $id => $list) {
            if (count($list) < 2 || array_diff($list, $linkedWifeRows[$id] ?? []) === []) {
                continue;
            }
            $statuses = array_map(fn (int $n) => $life[$n] ?? null, $list);
            $alive = count(array_filter($statuses, fn ($s) => $s === 'ALIVE'));
            $deceased = count(array_filter($statuses, fn ($s) => $s === 'DECEASED'));
            $unknown = count($statuses) - $alive - $deceased;
            $groups[$id] = [
                'verdict' => $alive >= 2 ? 'LIVING_HEADS' : ($unknown > 0 ? 'UNKNOWN_STATUS' : 'AFTER_DEATH'),
                'rows' => $list,
                'alive' => $alive,
                'deceased' => $deceased,
                'unknown' => $unknown,
            ];
        }

        return $groups;
    }

    /** Exact staged value (trimmed), or null. */
    private static function id(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return list<string> distinct spouse IDs of a row */
    private static function spouseIds(array $n): array
    {
        $ids = [];
        foreach (range(1, InitialFamilyWorkbook::MAX_WIVES) as $k) {
            if ($id = self::id($n["wife_{$k}_national_id"] ?? null)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Registry facts for the staged IDs: exact matches (incl. soft-deleted),
     * normalized-only variants, and each matched Person's active membership.
     *
     * @param  list<string>  $ids
     * @return array{exact: array<string, list<object>>, variant: array<string, list<object>>, membership: array<int, object>}
     */
    private static function registry(array $ids): array
    {
        $exact = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('persons')->whereIn('national_id', $chunk)->get() as $p) {
                $exact[$p->national_id][] = $p;
            }
        }

        // Normalized-only equality (digits / separators) is surfaced, never matched.
        $wanted = [];
        foreach ($ids as $id) {
            $wanted[NationalId::normalize($id)][] = $id;
        }
        $variant = [];
        foreach (DB::table('persons')->whereNotNull('national_id')->select('id', 'national_id', 'deleted_at')->cursor() as $p) {
            foreach ($wanted[NationalId::normalize($p->national_id)] ?? [] as $id) {
                if ($p->national_id !== $id) {
                    $variant[$id][] = $p;
                }
            }
        }

        $personIds = collect($exact)->flatten(1)->pluck('id')->all();
        $membership = DB::table('family_memberships as m')
            ->join('families as f', 'f.id', '=', 'm.family_id')
            ->whereIn('m.person_id', $personIds ?: [0])
            ->where('m.is_active', true)
            ->get(['m.person_id', 'm.is_household_head', 'm.family_id', 'f.family_code', 'f.clan_id', 'f.branch_id', 'f.deleted_at'])
            ->keyBy('person_id')->all();

        return ['exact' => $exact, 'variant' => $variant, 'membership' => $membership];
    }

    private static function row(object $r, ImportBatch $batch, $resolutions, array $headRows, array $spouseRows, array $spouseGroups, array $links, array $names, array $registry, array $registryNames): array
    {
        $n = $r->n;
        $issues = [];
        $issue = function (string $code, array $context = []) use (&$issues) {
            $issues[] = ['code' => $code, 'context' => $context === [] ? null : $context];
        };

        if ($r->status === 'REJECTED') {
            $issue('STAGING_REJECTED');
        }

        // ---- source value vocabulary ---------------------------------------
        foreach (['gender' => SourceValues::GENDER, 'marital_status' => SourceValues::MARITAL_STATUS, 'life_status_source' => SourceValues::LIFE_STATUS] as $field => $list) {
            if (($n[$field] ?? null) !== null && SourceValues::map($list, $n[$field]) === null) {
                $issue('UNMAPPED_SOURCE_VALUE', ['field' => $field]);
            }
        }
        $life = SourceValues::map(SourceValues::LIFE_STATUS, $n['life_status_source'] ?? null);
        if (($n['death_date'] ?? null) !== null && $life !== 'DECEASED') {
            $issue('LIFE_STATUS_INCONSISTENT', ['field' => 'death_date']);
        }

        // ---- in-file identity checks ---------------------------------------
        $headId = self::id($n['national_id'] ?? null);
        $spouses = self::spouseIds($n);
        $slotIds = array_filter(array_map(fn ($k) => self::id($n["wife_{$k}_national_id"] ?? null), range(1, InitialFamilyWorkbook::MAX_WIVES)));
        if (count($slotIds) !== count(array_unique($slotIds))) {
            $issue('DUPLICATE_SPOUSE_IN_ROW');
        }
        // HEAD ↔ SPOUSE cross-role, judged per other row: an occurrence that is
        // part of a polygamous-household link gets that link's code; every other
        // occurrence stays HEAD_ALSO_SPOUSE_IN_FILE (review).
        $crossRole = function (string $id, array $others, callable $pair, array $base) use ($issue, $links) {
            $plain = [];
            foreach ($others as $other) {
                [$headRow, $spouseRow] = $pair($other);
                $link = self::linkFor($links, $id, $headRow, $spouseRow);
                if ($link === null) {
                    $plain[] = $other;

                    continue;
                }
                $issue('POLYGAMY_INDEPENDENT_WIFE_HOUSEHOLD', [
                    'national_id' => NationalIdMask::mask($id), 'head_rows' => [$headRow], 'spouse_rows' => [$spouseRow],
                    'husband_row' => $link['husband_row'], 'wife_rows' => $link['wife_rows'],
                    'slot' => $link['slot'], 'last_slot' => $link['last_slot'],
                ]);
            }
            if ($plain !== []) {
                $issue('HEAD_ALSO_SPOUSE_IN_FILE', ['national_id' => NationalIdMask::mask($id), ...$base, ...(isset($base['head_rows']) ? ['spouse_rows' => $plain] : ['head_rows' => $plain])]);
            }
        };
        if ($headId !== null) {
            if (in_array($headId, $spouses, true)) {
                $issue('HEAD_ID_EQUALS_SPOUSE_ID', ['national_id' => NationalIdMask::mask($headId)]);
            }
            if (count($headRows[$headId]) > 1) {
                $issue('DUPLICATE_HEAD_ID_IN_FILE', ['national_id' => NationalIdMask::mask($headId), 'rows' => $headRows[$headId]]);
            }
            $asSpouse = array_values(array_diff($spouseRows[$headId] ?? [], [$r->row_number]));
            $crossRole($headId, $asSpouse, fn (int $other) => [$r->row_number, $other], ['head_rows' => $headRows[$headId]]);
        }
        foreach ($spouses as $id) {
            if (isset($spouseGroups[$id])) {
                $g = $spouseGroups[$id];
                $context = ['national_id' => NationalIdMask::mask($id), 'rows' => $g['rows'], 'alive' => $g['alive'], 'deceased' => $g['deceased'], 'unknown' => $g['unknown']];
                $issue(match ($g['verdict']) {
                    'LIVING_HEADS' => 'SPOUSE_SHARED_BY_LIVING_HEADS',
                    'UNKNOWN_STATUS' => 'SPOUSE_REPEATED_HEAD_STATUS_UNKNOWN',
                    default => 'SPOUSE_REPEATED_AFTER_HEAD_DEATH',
                }, $context);
            }
            $asHead = array_values(array_diff($headRows[$id] ?? [], [$r->row_number]));
            $crossRole($id, $asHead, fn (int $other) => [$other, $r->row_number], ['spouse_rows' => [$r->row_number]]);
        }

        // ---- HEAD Person evidence --------------------------------------------
        $headPerson = null;
        $familyMatch = 'NOT_DETERMINED';
        $familyId = null;
        $differences = [];
        if ($headId === null) {
            $headMatch = 'NO_NATIONAL_ID';
            // Never matched by name — a same name only raises review.
            $name = InitialFamilyRow::normalizeKey($n['full_name'] ?? null);
            $sameInFile = $name !== null ? array_values(array_diff($names[$name] ?? [], [$r->row_number])) : [];
            $sameInRegistry = $name !== null ? (int) ($registryNames[$name] ?? 0) : 0;
            if ($sameInFile !== [] || $sameInRegistry > 0) {
                $issue('NO_ID_NAME_AMBIGUITY', ['rows' => $sameInFile, 'registry_same_name' => $sameInRegistry]);
            }
        } else {
            $matches = $registry['exact'][$headId] ?? [];
            $live = array_values(array_filter($matches, fn ($p) => $p->deleted_at === null));
            if (count($live) > 1) {
                $headMatch = 'MULTIPLE_PERSONS';
                $issue('REGISTRY_MULTIPLE_PERSONS', ['national_id' => NationalIdMask::mask($headId), 'person_codes' => array_column($live, 'person_code')]);
            } elseif (count($live) === 1) {
                $headMatch = 'EXISTING_PERSON';
                $headPerson = $live[0];
            } elseif ($matches !== []) {
                $headMatch = 'DELETED_PERSON';
                $issue('DELETED_PERSON_MATCH', ['national_id' => NationalIdMask::mask($headId), 'person_codes' => array_column($matches, 'person_code')]);
            } elseif (isset($registry['variant'][$headId])) {
                $headMatch = 'FORMAT_VARIANT';
                $issue('NATIONAL_ID_FORMAT_VARIANT', ['national_id' => NationalIdMask::mask($headId)]);
            } else {
                $headMatch = 'NO_EXISTING_PERSON';
                $familyMatch = 'NO_EXISTING_FAMILY';
            }
        }

        // ---- Family evidence (only through the matched Person's membership) -
        if ($headPerson !== null) {
            $membership = $registry['membership'][$headPerson->id] ?? null;
            $differences = self::personDifferences($headPerson, $n, $life);
            if ($membership === null || $membership->deleted_at !== null) {
                $familyMatch = 'NO_EXISTING_FAMILY';
                $issue('EXISTING_PERSON_NO_FAMILY', ['person_code' => $headPerson->person_code]);
            } elseif (! $membership->is_household_head) {
                $familyMatch = 'PERSON_NOT_HEAD';
                $issue('EXISTING_PERSON_NOT_HEAD', ['person_code' => $headPerson->person_code, 'family_code' => $membership->family_code]);
            } elseif ((int) $membership->clan_id !== (int) $batch->clan_id) {
                $familyMatch = 'OTHER_CLAN_FAMILY';
                $issue('FAMILY_IN_OTHER_CLAN', ['person_code' => $headPerson->person_code, 'family_code' => $membership->family_code]);
            } else {
                $familyMatch = 'EXISTING_FAMILY';
                $familyId = (int) $membership->family_id;
                $differences = [...$differences, ...self::familyDifferences($membership, $n, $resolutions[$r->source_family_key] ?? null)];
            }
            // Material identity mismatch with an existing Person: review, never overwrite.
            if (collect($differences)->whereIn('field', ['birth_date', 'gender'])->isNotEmpty()) {
                $issue('EXISTING_PERSON_IDENTITY_MISMATCH', ['person_code' => $headPerson->person_code]);
            }
        }

        // ---- spouses: separate candidates --------------------------------------
        $spouseMatches = [];
        foreach (range(1, InitialFamilyWorkbook::MAX_WIVES) as $k) {
            $id = self::id($n["wife_{$k}_national_id"] ?? null);
            if ($id === null) {
                continue;
            }
            $live = array_values(array_filter($registry['exact'][$id] ?? [], fn ($p) => $p->deleted_at === null));
            $match = ['slot' => $k, 'status' => 'NO_EXISTING_PERSON', 'person_id' => null];
            if (count($live) > 1) {
                $match['status'] = 'MULTIPLE_PERSONS';
                $issue('SPOUSE_MULTIPLE_PERSONS', ['slot' => $k, 'national_id' => NationalIdMask::mask($id)]);
            } elseif (count($live) === 1) {
                $match = ['slot' => $k, 'status' => 'EXISTING_PERSON', 'person_id' => (int) $live[0]->id];
                $issue('SPOUSE_EXISTING_PERSON', ['slot' => $k, 'person_code' => $live[0]->person_code]);
                $m = $registry['membership'][$live[0]->id] ?? null;
                if ($m !== null && $m->deleted_at === null && (int) $m->family_id !== $familyId) {
                    $issue('SPOUSE_IN_OTHER_FAMILY', ['slot' => $k, 'person_code' => $live[0]->person_code, 'family_code' => $m->family_code]);
                }
            } elseif (isset($registry['variant'][$id])) {
                $match['status'] = 'FORMAT_VARIANT';
                $issue('SPOUSE_NATIONAL_ID_FORMAT_VARIANT', ['slot' => $k, 'national_id' => NationalIdMask::mask($id)]);
            }
            $spouseMatches[] = $match;
        }

        // ---- status ----------------------------------------------------------------
        return [
            'import_row_id' => (int) $r->id,
            'status' => self::status($issues, $familyMatch, $differences),
            'head_match' => $headMatch,
            'head_person_id' => $headPerson ? (int) $headPerson->id : null,
            'family_match' => $familyMatch,
            'family_id' => $familyId,
            'spouse_matches' => $spouseMatches === [] ? null : $spouseMatches,
            'differences' => $differences === [] ? null : $differences,
            'issues' => $issues === [] ? null : $issues,
        ];
    }

    /** Only fields the source provides; absent source values are never "changes". */
    private static function personDifferences(object $p, array $n, ?string $life): array
    {
        $source = [
            'full_name' => InitialFamilyRow::normalizeKey($n['full_name'] ?? null),
            'birth_date' => $n['birth_date'] ?? null,
            'gender' => SourceValues::map(SourceValues::GENDER, $n['gender'] ?? null),
            'marital_status' => SourceValues::map(SourceValues::MARITAL_STATUS, $n['marital_status'] ?? null),
            'mobile' => $n['mobile'] ?? null,
            'life_status' => $life,
            'death_date' => $n['death_date'] ?? null,
        ];
        $registry = [
            'full_name' => InitialFamilyRow::normalizeKey($p->full_name),
            'birth_date' => $p->birth_date ? substr((string) $p->birth_date, 0, 10) : null,
            'gender' => $p->gender,
            'marital_status' => $p->marital_status,
            'mobile' => $p->mobile !== null ? trim($p->mobile) : null,
            'life_status' => $p->life_status,
            'death_date' => $p->death_date ? substr((string) $p->death_date, 0, 10) : null,
        ];

        $diff = [];
        foreach ($source as $field => $value) {
            if ($value !== null && $value !== $registry[$field]) {
                $diff[] = ['field' => $field, 'scope' => 'person', 'registry' => $registry[$field], 'source' => $value];
            }
        }

        return $diff;
    }

    /** Family-level differences — only for a deterministic Family match. */
    private static function familyDifferences(object $m, array $n, ?object $resolution): array
    {
        $diff = [];
        // NO_BRANCH means "no branch information", never "remove the branch".
        if ($resolution !== null && $resolution->decision !== FamilyKeyDecision::NO_BRANCH->value && (int) $resolution->branch_id !== (int) $m->branch_id) {
            $diff[] = ['field' => 'branch', 'scope' => 'family',
                'registry' => $m->branch_id ? DB::table('branches')->where('id', $m->branch_id)->value('name') : null,
                'source' => DB::table('branches')->where('id', $resolution->branch_id)->value('name')];
        }
        $residence = DB::table('family_residences')->where('family_id', $m->family_id)->where('is_current', true)->value('original_residence_text');
        if (($n['original_residence_text'] ?? null) !== null && $n['original_residence_text'] !== $residence) {
            $diff[] = ['field' => 'original_residence_text', 'scope' => 'family', 'registry' => $residence, 'source' => $n['original_residence_text']];
        }
        $declaration = DB::table('family_household_declarations')->where('family_id', $m->family_id)->where('is_current', true)->first();
        foreach (['declared_household_size', 'declared_living_sons', 'declared_living_daughters'] as $field) {
            $registry = $declaration?->{$field} !== null ? (int) $declaration?->{$field} : null;
            if (($n[$field] ?? null) !== null && (int) $n[$field] !== $registry) {
                $diff[] = ['field' => $field, 'scope' => 'family', 'registry' => $registry, 'source' => (int) $n[$field]];
            }
        }

        return $diff;
    }
}
