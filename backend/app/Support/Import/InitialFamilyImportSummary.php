<?php

namespace App\Support\Import;

use App\Enums\FamilyKeyDecision;
use App\Enums\ImportReconciliationStatus;
use App\Enums\ImportRowStatus;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\ImportFamilyKeyResolution;
use App\Support\NationalIdMask;
use Illuminate\Support\Facades\DB;

/**
 * Read-only views of a staged initial-family batch (docs/03 §96a):
 * counts per status/issue and the family-key DISCOVERY summary.
 *
 * Discovery only: each distinct source key is compared EXACTLY (after the
 * same whitespace normalization as the key itself) with Branch names of the
 * batch's target Clan. No fuzzy matching, no other Clan, no mapping, no
 * Branch creation. Branch Groups play no role. Never reads or returns
 * national IDs, names or other personal values.
 */
final class InitialFamilyImportSummary
{
    public const EXAMPLE_ROWS = 5;

    /** @return array<string, mixed> */
    public static function summary(ImportBatch $batch): array
    {
        $rows = DB::table('import_rows')->where('import_batch_id', $batch->id);

        $byStatus = (clone $rows)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $statuses = [];
        foreach (ImportRowStatus::cases() as $status) {
            $statuses[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }

        $issueCounts = [];
        // Rows per issue code (a row with two cell errors counts once).
        foreach ((clone $rows)->whereNotNull('issues')->pluck('issues') as $issues) {
            foreach (array_unique(array_column(json_decode($issues, true) ?? [], 'code')) as $code) {
                $issueCounts[$code] = ($issueCounts[$code] ?? 0) + 1;
            }
        }
        ksort($issueCounts);

        // TOTAL = READY + NEEDS_REVIEW + REJECTED (every staged row is exactly one).
        // READY = PENDING (staged without issues), NEEDS_REVIEW = FLAGGED (staged
        // with review flags — NOT rejected), REJECTED = kept but structurally blocked.
        $counts = [
            'total' => array_sum($statuses),
            'ready' => $statuses[ImportRowStatus::PENDING->value] + $statuses[ImportRowStatus::VALID->value],
            'needs_review' => $statuses[ImportRowStatus::FLAGGED->value],
            'rejected' => $statuses[ImportRowStatus::REJECTED->value],
        ];

        return [
            'counts' => $counts,
            'key_resolution' => self::keyResolution($batch),
            'reconciliation' => self::reconciliation($batch),
            'staged_rows' => array_sum($statuses),
            'statuses' => $statuses,
            // Rows refused for structure (cell errors, values beyond the header).
            'structural_errors' => $statuses[ImportRowStatus::REJECTED->value],
            'missing_family_key' => (clone $rows)->whereNull('source_family_key')->count(),
            'formula_family_key' => $issueCounts[InitialFamilyRow::FAMILY_KEY_FROM_FORMULA] ?? 0,
            'distinct_family_keys' => (clone $rows)->whereNotNull('source_family_key')->distinct()->count('source_family_key'),
            'issues' => $issueCounts,
        ];
    }

    /**
     * Rows needing attention, minimal and safe: source row number, status,
     * issue codes and the family key only — never payloads or personal data.
     *
     * @return array{data: list<array{row_number: int, status: string, issues: list<string>, source_family_key: ?string}>, meta: array{current_page: int, last_page: int, total: int}}
     */
    public static function problemRows(ImportBatch $batch, string $filter, int $page): array
    {
        $statuses = match ($filter) {
            'needs_review' => [ImportRowStatus::FLAGGED->value],
            'rejected' => [ImportRowStatus::REJECTED->value],
            default => [ImportRowStatus::FLAGGED->value, ImportRowStatus::REJECTED->value],
        };
        $paginator = DB::table('import_rows')
            ->where('import_batch_id', $batch->id)
            ->whereIn('status', $statuses)
            ->orderBy('row_number')
            ->paginate(25, ['row_number', 'status', 'issues', 'source_family_key'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn ($row) => [
                'row_number' => (int) $row->row_number,
                'status' => $row->status,
                'issues' => array_values(array_unique(array_column(json_decode($row->issues ?? '[]', true) ?? [], 'code'))),
                'source_family_key' => $row->source_family_key,
            ])->all(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ];
    }

    /** @return list<array{key: string, row_count: int, formula_rows: int, example_rows: list<int>, existing_branch: ?array{code: string, name: string, is_active: bool}}> */
    public static function familyKeys(ImportBatch $batch): array
    {
        // Exact names of the TARGET Clan's Branches only.
        $branches = [];
        foreach (Branch::where('clan_id', $batch->clan_id)->orderBy('id')->get(['code', 'name', 'is_active']) as $branch) {
            $branches[InitialFamilyRow::normalizeKey($branch->name)] ??= [
                'code' => $branch->code,
                'name' => $branch->name,
                'is_active' => $branch->is_active,
            ];
        }

        $keys = [];
        $rows = DB::table('import_rows')
            ->where('import_batch_id', $batch->id)
            ->whereNotNull('source_family_key')
            ->orderBy('row_number')
            ->get(['source_family_key', 'row_number', 'issues']);
        foreach ($rows as $row) {
            $entry = &$keys[$row->source_family_key];
            $entry ??= ['key' => $row->source_family_key, 'row_count' => 0, 'formula_rows' => 0, 'example_rows' => []];
            $entry['row_count']++;
            if ($row->issues !== null && str_contains($row->issues, InitialFamilyRow::FAMILY_KEY_FROM_FORMULA)) {
                $entry['formula_rows']++;
            }
            if (count($entry['example_rows']) < self::EXAMPLE_ROWS) {
                $entry['example_rows'][] = (int) $row->row_number;
            }
            unset($entry);
        }

        // Saved decisions (none = unresolved). The exact-name match above is a
        // SUGGESTION only; it never becomes a decision by itself.
        $resolutions = ImportFamilyKeyResolution::where('import_batch_id', $batch->id)
            ->with(['branch.group', 'resolver'])->get()->keyBy('source_family_key');

        $result = array_map(function (array $k) use ($branches, $resolutions) {
            $r = $resolutions[$k['key']] ?? null;

            return [...$k, 'existing_branch' => $branches[$k['key']] ?? null, 'resolution' => $r ? [
                'decision' => $r->decision,
                'branch' => $r->branch ? self::branchRef($r->branch) : null,
                'reference_source_key' => $r->reference_source_key,
                'resolved_by' => $r->resolver ? ['name' => $r->resolver->name] : null,
                'resolved_at' => $r->resolved_at->toIso8601String(),
            ] : null];
        }, array_values($keys));
        usort($result, fn ($a, $b) => [$b['row_count'], $a['key']] <=> [$a['row_count'], $b['key']]);

        return $result;
    }

    /**
     * Authoritative resolution progress over the keys actually staged (blank
     * keys never need or get a resolution). Resolved = any explicit decision,
     * including NO_BRANCH.
     *
     * @return array{distinct_keys: int, resolved_keys: int, unresolved_keys: int, match_existing: int, created_branch: int, same_branch_as_key: int, no_branch: int, complete: bool}
     */
    public static function keyResolution(ImportBatch $batch): array
    {
        $keys = DB::table('import_rows')->where('import_batch_id', $batch->id)->whereNotNull('source_family_key')->select('source_family_key');
        $distinct = (clone $keys)->distinct()->count('source_family_key');
        $byDecision = DB::table('import_family_key_resolutions')
            ->where('import_batch_id', $batch->id)
            ->whereIn('source_family_key', $keys)
            ->selectRaw('decision, count(*) as total')->groupBy('decision')->pluck('total', 'decision');
        $count = fn (FamilyKeyDecision $d) => (int) ($byDecision[$d->value] ?? 0);
        $resolved = (int) $byDecision->sum();

        return [
            'distinct_keys' => $distinct,
            'resolved_keys' => $resolved,
            'unresolved_keys' => $distinct - $resolved,
            'match_existing' => $count(FamilyKeyDecision::MATCH_EXISTING_BRANCH),
            'created_branch' => $count(FamilyKeyDecision::CREATE_NEW_BRANCH),
            'same_branch_as_key' => $count(FamilyKeyDecision::SAME_BRANCH_AS_KEY),
            'no_branch' => $count(FamilyKeyDecision::NO_BRANCH),
            'complete' => $batch->isStaged() && $distinct === $resolved,
        ];
    }

    /**
     * Reconciliation state and backend-authoritative counts (docs/03 §96a).
     * state: NOT_RUN | CURRENT | STALE (inputs changed since the last run —
     * staging, key decisions or registry markers; re-run before relying on it).
     * Preliminary readiness: NEW / UNCHANGED are non-blocking; CHANGED,
     * DUPLICATE_IN_FILE, CONFLICT and REVIEW_REQUIRED need review before any
     * future Apply.
     *
     * @return array<string, mixed>
     */
    public static function reconciliation(ImportBatch $batch): array
    {
        if ($batch->reconciled_at === null) {
            return ['state' => 'NOT_RUN', 'reconciled_at' => null, 'counts' => null, 'requires_review' => null, 'stats' => null];
        }

        $byStatus = DB::table('import_rows')->where('import_batch_id', $batch->id)
            ->selectRaw('reconciliation_status as s, count(*) as n')->groupBy('reconciliation_status')->pluck('n', 's');
        $counts = [];
        foreach (ImportReconciliationStatus::cases() as $case) {
            $counts[$case->value] = (int) ($byStatus[$case->value] ?? 0);
        }

        $evidence = DB::table('import_row_reconciliations')->where('import_batch_id', $batch->id);
        $head = (clone $evidence)->selectRaw('head_match as m, count(*) as n')->groupBy('head_match')->pluck('n', 'm')->map(fn ($n) => (int) $n)->all();
        $family = (clone $evidence)->selectRaw('family_match as m, count(*) as n')->groupBy('family_match')->pluck('n', 'm')->map(fn ($n) => (int) $n)->all();
        $issueRows = [];
        $spouseCandidates = 0;
        foreach ((clone $evidence)->get(['issues', 'spouse_matches']) as $e) {
            foreach (array_unique(array_column(json_decode($e->issues ?? '[]', true) ?? [], 'code')) as $code) {
                $issueRows[$code] = ($issueRows[$code] ?? 0) + 1;
            }
            foreach (json_decode($e->spouse_matches ?? '[]', true) ?? [] as $s) {
                $spouseCandidates += $s['status'] === 'EXISTING_PERSON' ? 1 : 0;
            }
        }
        ksort($issueRows);

        return [
            'state' => $batch->reconciliation_fingerprint === ImportReconciler::fingerprint($batch) ? 'CURRENT' : 'STALE',
            'reconciled_at' => $batch->reconciled_at->toIso8601String(),
            'counts' => $counts,
            'requires_review' => $counts['CHANGED'] + $counts['DUPLICATE_IN_FILE'] + $counts['CONFLICT'] + $counts['REVIEW_REQUIRED'],
            'stats' => [
                'head_existing_person' => $head['EXISTING_PERSON'] ?? 0,
                'head_no_existing_person' => $head['NO_EXISTING_PERSON'] ?? 0,
                'head_no_national_id' => $head['NO_NATIONAL_ID'] ?? 0,
                'head_existing_person_no_family' => $issueRows['EXISTING_PERSON_NO_FAMILY'] ?? 0,
                'family_matches' => $family['EXISTING_FAMILY'] ?? 0,
                'spouse_existing_person_candidates' => $spouseCandidates,
                'cross_role_collision_rows' => $issueRows['HEAD_ALSO_SPOUSE_IN_FILE'] ?? 0,
                'head_match' => $head,
                'family_match' => $family,
                'issues' => $issueRows,
            ],
        ];
    }

    /**
     * Reconciliation rows for review: row number, key, head name, MASKED
     * National ID, status, matches (public codes), issues and differences.
     * Never payloads, full IDs or forbidden columns.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int}}
     */
    public static function reconciliationRows(ImportBatch $batch, ?string $status, int $page): array
    {
        $paginator = DB::table('import_rows as r')
            ->join('import_row_reconciliations as e', 'e.import_row_id', '=', 'r.id')
            ->leftJoin('persons as p', 'p.id', '=', 'e.head_person_id')
            ->leftJoin('families as f', 'f.id', '=', 'e.family_id')
            ->where('r.import_batch_id', $batch->id)
            ->when($status, fn ($q) => $q->where('r.reconciliation_status', $status))
            ->orderBy('r.row_number')
            ->paginate(25, ['r.row_number', 'r.source_family_key', 'r.reconciliation_status', 'r.normalized_payload',
                'e.head_match', 'e.family_match', 'e.spouse_matches', 'e.issues', 'e.differences', 'p.person_code', 'f.family_code'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(function ($row) {
                $n = json_decode($row->normalized_payload, true) ?? [];

                return [
                    'row_number' => (int) $row->row_number,
                    'source_family_key' => $row->source_family_key,
                    'head_name' => $n['full_name'] ?? null,
                    'national_id_masked' => NationalIdMask::mask($n['national_id'] ?? null),
                    'status' => $row->reconciliation_status,
                    'head_match' => $row->head_match,
                    'family_match' => $row->family_match,
                    'person_code' => $row->person_code,
                    'family_code' => $row->family_code,
                    'spouse_matches' => array_map(fn ($s) => ['slot' => $s['slot'], 'status' => $s['status']], json_decode($row->spouse_matches ?? '[]', true) ?? []),
                    'issues' => json_decode($row->issues ?? '[]', true) ?? [],
                    'differences' => json_decode($row->differences ?? '[]', true) ?? [],
                ];
            })->all(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ];
    }

    /**
     * Branches of the batch's Clan that may be chosen now (Branch::isSelectable).
     *
     * @return list<array<string, mixed>>
     */
    public static function selectableBranches(ImportBatch $batch): array
    {
        return Branch::where('clan_id', $batch->clan_id)->with(['group.branches', 'clan'])->orderBy('name')->get()
            ->filter(fn (Branch $b) => $b->isSelectable())
            ->map(fn (Branch $b) => self::branchRef($b))->values()->all();
    }

    /** @return array{id: string, code: string, name: string, is_active: bool, group: ?string} */
    private static function branchRef(Branch $branch): array
    {
        return [
            'id' => $branch->uuid,
            'code' => $branch->code,
            'name' => $branch->name,
            'is_active' => $branch->is_active,
            // Distinguishes similarly named Branches; NULL = ungrouped.
            'group' => $branch->group?->name ?? $branch->group?->displayName(),
        ];
    }
}
