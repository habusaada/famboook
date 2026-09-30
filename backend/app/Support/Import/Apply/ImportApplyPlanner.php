<?php

namespace App\Support\Import\Apply;

use App\Enums\FamilyKeyDecision;
use App\Enums\ImportApplyEffect as E;
use App\Enums\ImportApplyIntent as I;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportMode;
use App\Exceptions\MissingRelationshipTypeException;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Support\DeathDate;
use App\Support\Import\ImportReconciler;
use App\Support\Import\InitialFamilyImportSummary;
use App\Support\Import\InitialFamilyRow;
use App\Support\Import\InitialFamilyWorkbook;
use App\Support\Import\SourceValues;
use App\Support\NationalIdMask;
use App\Support\RelationshipTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The pure Apply planner (docs/03 §96b "Apply planner"): decides, for every
 * staged row of an INITIAL batch whose rows are all NEW, exactly what a
 * future Apply would do — WITHOUT writing anything. It only reads the batch,
 * its staging and the registry, then computes in memory: no save/create/
 * update/delete, no BusinessIdentifier, no activity, no provenance, no
 * status change. The Dry Run presents this plan; the future Apply must
 * execute the SAME plan (one source of business decisions).
 *
 * Person identity is coordinated across the whole batch: one exact National
 * ID is ONE Person with exactly one owning effect (see spouseOwner()).
 */
final class ImportApplyPlanner
{
    /** Placeholder: the date is taken when Apply executes (never predicted). */
    public const EXECUTION_DATE = 'EXECUTION_DATE';

    /**
     * The plan in a context: current() (default — Dry Run, Apply start) or
     * asOfApplyStart() (resume / completion: reconstructs the approved plan).
     */
    public function plan(ImportBatch $batch, ?ImportApplyPlanningContext $context = null): ImportBatchApplyPlan
    {
        $context ??= ImportApplyPlanningContext::current();
        $failures = $this->preconditions($batch, $context);

        return new ImportBatchApplyPlan($batch->id, $batch->reconciliation_fingerprint, $failures, $failures === [] ? $this->planRows($batch, $context) : []);
    }

    /**
     * Resume / completion primitive: does the plan reconstructed as of Apply
     * start still equal the approved plan stored when Apply started?
     */
    public function matchesApprovedPlan(ImportBatch $batch): bool
    {
        if ($batch->apply_plan_fingerprint === null) {
            return false;
        }
        $plan = $this->plan($batch, ImportApplyPlanningContext::asOfApplyStart($batch));

        return $plan->preconditionsMet() && hash_equals($batch->apply_plan_fingerprint, $plan->fingerprint());
    }

    /**
     * Batch-level conditions under which planning is meaningful. Any failure
     * stops planning (nothing is repaired automatically).
     *
     * @return list<array{code: string, count?: int}>
     */
    public function preconditions(ImportBatch $batch, ?ImportApplyPlanningContext $context = null): array
    {
        $asOfStart = ($context ?? ImportApplyPlanningContext::current())->asOfApplyStart;
        $failures = [];
        $fail = function (string $code, ?int $count = null) use (&$failures) {
            $failures[] = $count === null ? ['code' => $code] : ['code' => $code, 'count' => $count];
        };

        if ($batch->import_mode !== ImportMode::INITIAL) {
            $fail('BATCH_NOT_INITIAL');
        }
        if ($asOfStart) {
            // Reconstructing the approved plan requires a started Apply.
            if ($batch->apply_started_at === null || ! $batch->status->applyStarted()) {
                $fail('APPLY_NOT_STARTED');
            }
        } elseif ($batch->apply_started_at !== null || $batch->status->applyStarted()) {
            $fail('APPLY_ALREADY_STARTED');
        } elseif ($batch->status !== ImportBatchStatus::READY_FOR_REVIEW) {
            $fail('BATCH_NOT_READY_FOR_REVIEW');
        }
        if ($batch->mapping_confirmed_at === null) {
            $fail('MAPPING_NOT_CONFIRMED');
        }

        $rows = DB::table('import_rows')->where('import_batch_id', $batch->id);
        if (! (clone $rows)->exists()) {
            $fail('NO_STAGED_ROWS');
        }
        $unresolved = InitialFamilyImportSummary::keyResolution($batch)['unresolved_keys'];
        if ($unresolved > 0) {
            $fail('FAMILY_KEYS_UNRESOLVED', $unresolved);
        }

        if ($batch->reconciled_at === null) {
            $fail('RECONCILIATION_NOT_RUN');
        } elseif (! $asOfStart && $batch->reconciliation_fingerprint !== ImportReconciler::fingerprint($batch)) {
            // Before Apply only: Apply's own writes move the registry markers;
            // after it starts, plan equality is the freshness rule.
            $fail('RECONCILIATION_STALE');
        }
        // INITIAL Apply plans NEW rows only; every other state needs review first.
        foreach ((clone $rows)->selectRaw('reconciliation_status as s, count(*) as n')->groupBy('reconciliation_status')->orderBy('reconciliation_status')->pluck('n', 's') as $status => $n) {
            if ($status !== 'NEW') {
                $fail($status === '' || $status === null ? 'ROWS_NOT_RECONCILED' : "ROWS_{$status}", (int) $n);
            }
        }

        if (! $asOfStart && ($linked = (clone $rows)->whereNotNull('family_id')->count()) > 0) {
            $fail('ROWS_ALREADY_APPLIED', $linked);
        }
        if (! $asOfStart && ($records = DB::table('import_apply_records')->where('import_batch_id', $batch->id)->count()) > 0) {
            $fail('APPLY_RECORDS_EXIST', $records);
        }
        if (! DB::table('clans')->where('id', $batch->clan_id)->value('is_active')) {
            $fail('CLAN_INACTIVE');
        }
        foreach ([RelationshipTypes::HEAD, RelationshipTypes::SPOUSE] as $code) {
            try {
                RelationshipTypes::required($code);
            } catch (MissingRelationshipTypeException) {
                $fail("RELATIONSHIP_TYPE_{$code}_UNAVAILABLE");
            }
        }
        $branchIds = DB::table('import_family_key_resolutions')->where('import_batch_id', $batch->id)->whereNotNull('branch_id')->distinct()->pluck('branch_id')->all();
        $valid = $this->selectableBranches($batch, $branchIds);
        $invalid = count(array_diff($branchIds, array_keys($valid)));
        if ($invalid > 0) {
            $fail('BRANCH_NOT_SELECTABLE', $invalid);
        }

        return $failures;
    }

    /**
     * Plans every staged row (defensive row-level rules included, so the
     * future Apply can re-check a plan right before executing it). Pure.
     *
     * @return list<ImportRowApplyPlan>
     */
    public function planRows(ImportBatch $batch, ?ImportApplyPlanningContext $context = null): array
    {
        $context ??= ImportApplyPlanningContext::current();
        $rows = DB::table('import_rows')->where('import_batch_id', $batch->id)->orderBy('row_number')
            ->get(['id', 'row_number', 'status', 'source_family_key', 'normalized_payload'])
            ->map(function ($r) {
                $r->n = json_decode($r->normalized_payload, true) ?? [];

                return $r;
            })->keyBy('row_number');
        $resolutions = DB::table('import_family_key_resolutions')->where('import_batch_id', $batch->id)
            ->get(['source_family_key', 'decision', 'branch_id'])->keyBy('source_family_key');
        $branches = $this->selectableBranches($batch, $resolutions->pluck('branch_id')->filter()->unique()->values()->all());

        // ---- in-file identity indexes (exact staged values) -----------------
        $headRows = [];
        $spouseRefs = [];
        foreach ($rows as $n => $r) {
            if ($id = self::id($r->n['national_id'] ?? null)) {
                $headRows[$id][] = $n;
            }
            foreach (range(1, InitialFamilyWorkbook::MAX_WIVES) as $k) {
                if ($sid = self::id($r->n["wife_{$k}_national_id"] ?? null)) {
                    $spouseRefs[$sid][] = ['row' => $n, 'slot' => $k, 'name' => InitialFamilyRow::normalizeKey($r->n["wife_{$k}_name"] ?? null)];
                }
            }
        }

        // ---- registry facts (read only) ---------------------------------------
        $registry = [];
        foreach (array_chunk(array_values(array_unique([...array_keys($headRows), ...array_keys($spouseRefs)])), 1000) as $chunk) {
            foreach (DB::table('persons')->whereIn('national_id', $chunk)->orderBy('id')->get(['id', 'person_code', 'national_id', 'gender', 'birth_date', 'life_status', 'deleted_at']) as $p) {
                // As of Apply start, a Person this batch created did not exist yet.
                if (! $context->ownsPerson((int) $p->id)) {
                    $registry[$p->national_id][] = $p;
                }
            }
        }
        $liveIds = [];
        foreach ($registry as $list) {
            foreach ($list as $p) {
                if ($p->deleted_at === null) {
                    $liveIds[] = $p->id;
                }
            }
        }
        $active = [];
        foreach (array_chunk($liveIds, 1000) as $chunk) {
            foreach (DB::table('family_memberships')->whereIn('person_id', $chunk)->where('is_active', true)->get(['id', 'person_id']) as $m) {
                // …nor a membership this batch created (e.g. a reused head's HEAD).
                if (! $context->ownsMembership((int) $m->id)) {
                    $active[$m->person_id] = true;
                }
            }
        }

        // ---- per-row head + family (independent of spouse decisions) ----------
        $head = [];
        $family = [];
        $rowBlocks = [];
        $warnings = [];
        foreach ($rows as $n => $r) {
            $warnings[$n] = [];
            $rowBlocks[$n] = $r->status === 'REJECTED' ? ['ROW_REJECTED'] : [];
            $head[$n] = $this->headPerson($r, $headRows, $registry, $active, $warnings[$n]);
            $family[$n] = $this->family($batch, $r, $resolutions, $branches);
        }
        $rowBlocked = [];
        foreach ($rows as $n => $r) {
            $rowBlocked[$n] = $rowBlocks[$n] !== [] || $head[$n]->blocked() || $family[$n]->blocked();
        }

        // ---- spouses: one Person per exact ID, coordinated over the batch ------
        $spouse = [];
        foreach ($spouseRefs as $sid => $refs) {
            foreach ($this->spouseIdentity($sid, $refs, $rows, $headRows, $registry, $active, $head, $rowBlocked) as $key => $pair) {
                [$n, $k] = explode(':', $key);
                $spouse[(int) $n][(int) $k] = $pair;
            }
        }

        $plans = [];
        foreach ($rows as $n => $r) {
            $effects = [];
            $effects[E::HEAD_PERSON->value] = $head[$n];
            $effects[E::FAMILY->value] = $family[$n];
            $effects[E::HEAD_MEMBERSHIP->value] = $head[$n]->blocked()
                ? new ImportApplyEffectPlan(E::HEAD_MEMBERSHIP, I::BLOCK, $head[$n]->reason)
                : new ImportApplyEffectPlan(E::HEAD_MEMBERSHIP, I::CREATE, values: [
                    'relationship_type' => RelationshipTypes::HEAD, 'is_household_head' => true, 'started_at' => self::EXECUTION_DATE,
                ]);

            $spouseMasked = [];
            foreach (range(1, InitialFamilyWorkbook::MAX_WIVES) as $k) {
                $sid = self::id($r->n["wife_{$k}_national_id"] ?? null);
                $name = InitialFamilyRow::normalizeKey($r->n["wife_{$k}_name"] ?? null);
                if ($sid === null && $name === null) {
                    continue;
                }
                $spouseMasked[$k] = NationalIdMask::mask($sid);
                // A spouse without a National ID is never matched or created by name.
                [$person, $membership] = $sid === null
                    ? [new ImportApplyEffectPlan(E::spousePerson($k), I::OMIT, 'SPOUSE_WITHOUT_NATIONAL_ID'), new ImportApplyEffectPlan(E::spouseMembership($k), I::OMIT, 'SPOUSE_WITHOUT_NATIONAL_ID')]
                    : $spouse[$n][$k];
                $effects[$person->effect->value] = $person;
                $effects[$membership->effect->value] = $membership;
                if ($membership->intent === I::OMIT) {
                    $warnings[$n][] = $membership->reason;
                }
            }

            $effects[E::HOUSEHOLD_DECLARATION->value] = $this->declaration($r->n);
            $effects[E::RESIDENCE->value] = $this->residence($r->n);
            foreach ([E::HOUSEHOLD_DECLARATION, E::RESIDENCE] as $e) {
                if ($effects[$e->value]->intent === I::OMIT) {
                    $warnings[$n][] = $effects[$e->value]->reason;
                }
            }

            $plans[] = new ImportRowApplyPlan(
                (int) $r->id, (int) $n, $r->source_family_key, NationalIdMask::mask($r->n['national_id'] ?? null),
                $effects, array_values(array_unique($warnings[$n])), $rowBlocks[$n], $spouseMasked,
            );
        }

        return $plans;
    }

    // ==================================================================== head

    private function headPerson(object $r, array $headRows, array $registry, array $active, array &$warnings): ImportApplyEffectPlan
    {
        $n = $r->n;
        $block = fn (string $reason) => new ImportApplyEffectPlan(E::HEAD_PERSON, I::BLOCK, $reason);
        $id = self::id($n['national_id'] ?? null);
        $life = SourceValues::map(SourceValues::LIFE_STATUS, $n['life_status_source'] ?? null);

        if ($id !== null) {
            if (count($headRows[$id]) > 1) {
                return $block('HEAD_ID_DUPLICATED_IN_FILE');
            }
            $matches = $registry[$id] ?? [];
            $live = array_values(array_filter($matches, fn ($p) => $p->deleted_at === null));
            if (count($live) > 1) {
                return $block('HEAD_MULTIPLE_PERSONS');
            }
            if ($live === [] && $matches !== []) {
                return $block('HEAD_DELETED_PERSON');
            }
            if (count($live) === 1) {
                $p = $live[0];
                if (isset($active[$p->id])) {
                    return $block('HEAD_HAS_ACTIVE_MEMBERSHIP');
                }
                $gender = SourceValues::map(SourceValues::GENDER, $n['gender'] ?? null);
                $birth = $n['birth_date'] ?? null;
                if (($gender !== null && $p->gender !== null && $gender !== $p->gender)
                    || ($birth !== null && $p->birth_date !== null && $birth !== substr((string) $p->birth_date, 0, 10))) {
                    return $block('HEAD_IDENTITY_MISMATCH');
                }
                // INITIAL never updates an existing Person.
                $warnings[] = 'HEAD_PERSON_REUSED';
                if ($life === 'DECEASED' || $p->life_status === 'DECEASED') {
                    $warnings[] = 'HOUSEHOLD_HEAD_DECEASED';
                }

                return new ImportApplyEffectPlan(E::HEAD_PERSON, I::REUSE, existingId: (int) $p->id, existingCode: $p->person_code);
            }
        } else {
            // Allowed (docs/03 §19), but never deduplicated by name.
            $warnings[] = 'HEAD_WITHOUT_NATIONAL_ID';
        }

        // CREATE: only approved source values; a deceased head starts DECEASED.
        $fullName = InitialFamilyRow::normalizeKey($n['full_name'] ?? null);
        if ($fullName === null) {
            return $block('HEAD_WITHOUT_NAME');
        }
        $mapped = [];
        foreach (['gender' => SourceValues::GENDER, 'marital_status' => SourceValues::MARITAL_STATUS, 'life_status_source' => SourceValues::LIFE_STATUS] as $field => $list) {
            $raw = $n[$field] ?? null;
            $mapped[$field] = SourceValues::map($list, $raw);
            if ($raw !== null && $mapped[$field] === null) {
                return $block('HEAD_UNMAPPED_SOURCE_VALUE');
            }
        }
        $deathDate = $n['death_date'] ?? null;
        $birthDate = $n['birth_date'] ?? null;
        $lifeStatus = $mapped['life_status_source'] ?? 'UNKNOWN';
        if ($lifeStatus === 'DECEASED') {
            try {
                DeathDate::validate($deathDate, $birthDate);
            } catch (ValidationException) {
                return $block('HEAD_INVALID_DEATH_DATE');
            }
            $warnings[] = 'HOUSEHOLD_HEAD_DECEASED';
        } elseif ($deathDate !== null) {
            return $block('HEAD_DEATH_DATE_WITHOUT_DECEASED');
        }

        return new ImportApplyEffectPlan(E::HEAD_PERSON, I::CREATE, values: [
            'full_name' => $fullName,
            'national_id' => $id,
            'gender' => $mapped['gender'],
            'birth_date' => $birthDate,
            'marital_status' => $mapped['marital_status'] ?? 'UNKNOWN',
            'mobile' => $n['mobile'] ?? null,
            'life_status' => $lifeStatus,
            'death_date' => $lifeStatus === 'DECEASED' ? $deathDate : null,
        ]);
    }

    // ================================================================== family

    private function family(ImportBatch $batch, object $r, $resolutions, array $branches): ImportApplyEffectPlan
    {
        $block = fn (string $reason) => new ImportApplyEffectPlan(E::FAMILY, I::BLOCK, $reason);
        if ($r->source_family_key === null) {
            return $block('FAMILY_KEY_MISSING');
        }
        $resolution = $resolutions[$r->source_family_key] ?? null;
        if ($resolution === null) {
            return $block('FAMILY_KEY_UNRESOLVED');
        }
        $branchId = null;
        if ($resolution->decision !== FamilyKeyDecision::NO_BRANCH->value) {
            if ($resolution->branch_id === null || ! isset($branches[(int) $resolution->branch_id])) {
                return $block('BRANCH_NOT_SELECTABLE');
            }
            $branchId = (int) $resolution->branch_id;
        }

        // The family code is generated at execution — never reserved here.
        return new ImportApplyEffectPlan(E::FAMILY, I::CREATE, values: [
            'clan_id' => (int) $batch->clan_id,
            'branch_id' => $branchId,
            'status' => 'ACTIVE',
            'registration_source' => 'IMPORT',
            'registration_date' => self::EXECUTION_DATE,
        ]);
    }

    /** @return array<int, true> selectable Branch ids of the batch's Clan among $ids */
    private function selectableBranches(ImportBatch $batch, array $ids): array
    {
        $valid = [];
        foreach (Branch::whereIn('id', $ids ?: [0])->with(['group', 'clan'])->get() as $branch) {
            if ((int) $branch->clan_id === (int) $batch->clan_id && $branch->isSelectable()) {
                $valid[(int) $branch->id] = true;
            }
        }

        return $valid;
    }

    // ================================================================= spouses

    /**
     * Decisions for every occurrence of ONE exact spouse National ID.
     *
     * Membership (per occurrence):
     *   - the ID heads its own row in the batch    → OMIT INDEPENDENT_HOUSEHOLD_HEAD
     *   - existing Person with an active membership → OMIT PERSON_ALREADY_HAS_ACTIVE_MEMBERSHIP
     *   - referenced by several rows: exactly one ALIVE head (all others
     *     DECEASED) → CREATE there only; all heads DECEASED → none; the others
     *     OMIT HISTORICAL_RELATIONSHIP_NO_ACTIVE_HOUSEHOLD; anything else
     *     (unknown status, several living heads) → BLOCK
     *   - otherwise CREATE
     *
     * Person: one Person per ID. An existing registry Person is REUSED; a
     * batch head owns the identity (HEAD_PERSON); otherwise exactly one
     * occurrence CREATES it (spouseOwner()) and every other one REUSES it.
     *
     * @return array<string, array{0: ImportApplyEffectPlan, 1: ImportApplyEffectPlan}> keyed "row:slot"
     */
    private function spouseIdentity(string $sid, array $refs, $rows, array $headRows, array $registry, array $active, array $head, array $rowBlocked): array
    {
        $matches = $registry[$sid] ?? [];
        $live = array_values(array_filter($matches, fn ($p) => $p->deleted_at === null));
        $existing = count($live) === 1 ? $live[0] : null;
        $personBlock = count($live) > 1 ? 'SPOUSE_MULTIPLE_PERSONS' : ($live === [] && $matches !== [] ? 'SPOUSE_DELETED_PERSON' : null);
        $headRow = isset($headRows[$sid]) ? min($headRows[$sid]) : null;
        // A row whose head is its own spouse (reconciliation CONFLICT).
        if ($headRow !== null && array_intersect($headRows[$sid], array_column($refs, 'row')) !== []) {
            $personBlock = 'HEAD_ID_EQUALS_SPOUSE_ID';
        }

        // Source-specific gender: a MALE head's spouse slot is FEMALE and a
        // FEMALE head's spouse slot is MALE (this import format only).
        $derived = [];
        foreach ($refs as $ref) {
            $derived[$ref['row']] = match (SourceValues::map(SourceValues::GENDER, $rows[$ref['row']]->n['gender'] ?? null)) {
                'MALE' => 'FEMALE',
                'FEMALE' => 'MALE',
                default => null,
            };
        }
        $known = array_values(array_unique(array_filter($derived)));
        if ($personBlock === null && $existing === null) {
            $identityGender = $headRow !== null ? SourceValues::map(SourceValues::GENDER, $rows[$headRow]->n['gender'] ?? null) : null;
            if (count($known) > 1 || ($identityGender !== null && $known !== [] && $known !== [$identityGender])) {
                $personBlock = 'SPOUSE_GENDER_CONFLICT';
            }
        }

        // ---- membership per occurrence ------------------------------------------
        $rowsOf = array_values(array_unique(array_column($refs, 'row')));
        $slotsPerRow = array_count_values(array_column($refs, 'row'));
        $membership = [];
        $life = fn (int $n) => SourceValues::map(SourceValues::LIFE_STATUS, $rows[$n]->n['life_status_source'] ?? null);
        foreach ($refs as $ref) {
            $key = $ref['row'].':'.$ref['slot'];
            $membership[$key] = match (true) {
                $personBlock !== null => [I::BLOCK, $personBlock],
                $slotsPerRow[$ref['row']] > 1 => [I::BLOCK, 'SPOUSE_DUPLICATED_IN_ROW'],
                $headRow !== null => [I::OMIT, 'INDEPENDENT_HOUSEHOLD_HEAD'],
                $existing !== null && isset($active[$existing->id]) => [I::OMIT, 'PERSON_ALREADY_HAS_ACTIVE_MEMBERSHIP'],
                count($rowsOf) > 1 => $this->sharedSpouse($ref['row'], $rowsOf, $life),
                default => [I::CREATE, null],
            };
        }

        // ---- the Person: who owns it --------------------------------------------------
        $ownerKey = null;
        $ownerPlan = null;
        if ($personBlock === null && $existing === null && $headRow === null) {
            $ownerKey = $this->spouseOwner($refs, $membership, $rowBlocked);
            [$ownerRow, $ownerSlot] = array_map('intval', explode(':', $ownerKey));
            // The owner's own spelling first, then the first other occurrence's.
            $names = array_column($refs, 'name', null);
            $ownerName = collect($refs)->first(fn ($r) => $r['row'] === $ownerRow && $r['slot'] === $ownerSlot)['name'];
            $name = $ownerName ?? collect($names)->first(fn ($v) => $v !== null);
            $gender = $derived[$ownerRow] ?? ($known[0] ?? null);
            $ownerPlan = match (true) {
                $name === null => new ImportApplyEffectPlan(E::spousePerson($ownerSlot), I::BLOCK, 'SPOUSE_WITHOUT_NAME'),
                $gender === null => new ImportApplyEffectPlan(E::spousePerson($ownerSlot), I::BLOCK, 'SPOUSE_GENDER_UNDERIVABLE'),
                default => new ImportApplyEffectPlan(E::spousePerson($ownerSlot), I::CREATE, values: [
                    // Only what the source states; nothing else is inferred.
                    'full_name' => $name,
                    'national_id' => $sid,
                    'gender' => $gender,
                    'life_status' => 'UNKNOWN',
                    'marital_status' => 'UNKNOWN',
                    'birth_date' => null,
                    'mobile' => null,
                    'death_date' => null,
                ]),
            };
        }

        $out = [];
        foreach ($refs as $ref) {
            $key = $ref['row'].':'.$ref['slot'];
            $effect = E::spousePerson($ref['slot']);
            $person = match (true) {
                $personBlock !== null => new ImportApplyEffectPlan($effect, I::BLOCK, $personBlock),
                $existing !== null => new ImportApplyEffectPlan($effect, I::REUSE, existingId: (int) $existing->id, existingCode: $existing->person_code),
                // The HEAD occurrence owns the identity; this occurrence reuses it.
                $headRow !== null => $head[$headRow]->blocked() || ($rowBlocked[$headRow] && $headRow !== $ref['row'])
                    ? new ImportApplyEffectPlan($effect, I::BLOCK, 'SPOUSE_OWNER_BLOCKED')
                    : ($head[$headRow]->intent === I::REUSE
                        ? new ImportApplyEffectPlan($effect, I::REUSE, existingId: $head[$headRow]->existingId, existingCode: $head[$headRow]->existingCode)
                        : new ImportApplyEffectPlan($effect, I::REUSE, ownerRow: $headRow, ownerEffect: E::HEAD_PERSON)),
                $key === $ownerKey => $ownerPlan,
                $ownerPlan->blocked() || ($rowBlocked[$ownerRow] && $ownerRow !== $ref['row']) => new ImportApplyEffectPlan($effect, I::BLOCK, $ownerPlan->blocked() ? $ownerPlan->reason : 'SPOUSE_OWNER_BLOCKED'),
                default => new ImportApplyEffectPlan($effect, I::REUSE, ownerRow: $ownerRow, ownerEffect: E::spousePerson($ownerSlot)),
            };
            [$intent, $reason] = $membership[$key];
            if ($person->blocked()) {
                [$intent, $reason] = [I::BLOCK, $person->reason];
            }
            $out[$key] = [$person, new ImportApplyEffectPlan(
                E::spouseMembership($ref['slot']), $intent, $reason,
                values: $intent === I::CREATE ? ['relationship_type' => RelationshipTypes::SPOUSE, 'is_household_head' => false, 'started_at' => self::EXECUTION_DATE] : [],
            )];
        }

        return $out;
    }

    /** Widow / remarriage: at most one living-head household keeps the spouse. */
    private function sharedSpouse(int $row, array $rowsOf, callable $life): array
    {
        $statuses = array_map($life, $rowsOf);
        $alive = array_values(array_filter($rowsOf, fn ($n) => $life($n) === 'ALIVE'));
        $unknown = count(array_filter($statuses, fn ($s) => $s !== 'ALIVE' && $s !== 'DECEASED'));
        if ($unknown > 0 || count($alive) > 1) {
            return [I::BLOCK, 'SPOUSE_SHARED_UNRESOLVED'];
        }

        return $alive === [$row] ? [I::CREATE, null] : [I::OMIT, 'HISTORICAL_RELATIONSHIP_NO_ACTIVE_HOUSEHOLD'];
    }

    /**
     * The one occurrence that CREATES a spouse Person not in the registry and
     * not a batch head: the occurrence that also gets the SPOUSE membership
     * (so no membership depends on another row), otherwise the first by
     * source row and slot; occurrences in rows that are already blocked are
     * passed over while an executable one exists. Deterministic.
     */
    private function spouseOwner(array $refs, array $membership, array $rowBlocked): string
    {
        $key = fn ($ref) => $ref['row'].':'.$ref['slot'];
        foreach ($refs as $ref) {
            if ($membership[$key($ref)][0] === I::CREATE && ! $rowBlocked[$ref['row']]) {
                return $key($ref);
            }
        }
        foreach ($refs as $ref) {
            if (! $rowBlocked[$ref['row']]) {
                return $key($ref);
            }
        }

        return $key($refs[0]);
    }

    // ===================================================== declaration, residence

    private function declaration(array $n): ImportApplyEffectPlan
    {
        $values = [];
        foreach (['declared_household_size', 'declared_living_sons', 'declared_living_daughters'] as $field) {
            $v = $n[$field] ?? null;
            if ($v !== null && ((int) $v < 0 || (int) $v > 32767)) {
                return new ImportApplyEffectPlan(E::HOUSEHOLD_DECLARATION, I::BLOCK, 'DECLARATION_OUT_OF_RANGE');
            }
            $values[$field] = $v === null ? null : (int) $v;
        }
        if (array_filter($values, fn ($v) => $v !== null) === []) {
            return new ImportApplyEffectPlan(E::HOUSEHOLD_DECLARATION, I::OMIT, 'NO_DECLARED_VALUES');
        }

        // Declared as stated: never recalculated from memberships, no child Persons.
        return new ImportApplyEffectPlan(E::HOUSEHOLD_DECLARATION, I::CREATE, values: [...$values, 'source' => 'IMPORT', 'declared_at' => null]);
    }

    private function residence(array $n): ImportApplyEffectPlan
    {
        $text = InitialFamilyRow::normalizeKey($n['original_residence_text'] ?? null);
        if ($text === null) {
            return new ImportApplyEffectPlan(E::RESIDENCE, I::OMIT, 'NO_ORIGINAL_RESIDENCE');
        }

        // المدينة is the ORIGINAL residence only — no current location, no displacement.
        return new ImportApplyEffectPlan(E::RESIDENCE, I::CREATE, values: [
            'original_residence_text' => $text, 'source' => 'IMPORT', 'started_at' => self::EXECUTION_DATE,
        ]);
    }

    private static function id(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
