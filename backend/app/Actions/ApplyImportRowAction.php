<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Enums\ImportApplyEffect as E;
use App\Enums\ImportApplyIntent as I;
use App\Enums\ImportApplyOutcome as O;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Exceptions\DuplicateNationalIdException;
use App\Exceptions\ImportApplyExecutionException;
use App\Exceptions\MissingRelationshipTypeException;
use App\Models\Branch;
use App\Models\Clan;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\ImportApplyRecord;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Person;
use App\Models\User;
use App\Support\FamilyActivityLog;
use App\Support\Import\Apply\ApprovedApplyPlan;
use App\Support\Import\Apply\ImportApplyEffectPlan;
use App\Support\Import\Apply\ImportRowApplyPlan;
use App\Support\Import\Apply\ImportRowApplyResult;
use App\Support\NationalIdFingerprint;
use App\Support\RelationshipTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Executes ONE planned import row (docs/03 §96b) — the only code that turns
 * an approved plan into registry writes. Internal: no endpoint, no runner.
 *
 * ONE SOURCE ROW = ONE DATABASE TRANSACTION: Persons, Family, memberships,
 * declaration, residence, activities, provenance and the row's APPLIED link
 * commit together, or nothing of the row remains.
 *
 * The planner decides (CREATE / REUSE / OMIT / BLOCK); this action only
 * executes those decisions with the canonical domain actions, re-validating
 * the registry at write time. Owner-only Person creation: a Person planned in
 * another row is never created here; its REUSED provenance is written when
 * the real person_id is known — here if the owner already ran, otherwise by
 * the owner's transaction later. Failures are ImportApplyExecutionException
 * codes (never exception text); batch error state belongs to the runner.
 */
class ApplyImportRowAction
{
    private const MANDATORY = [E::HEAD_PERSON, E::FAMILY, E::HEAD_MEMBERSHIP, E::HOUSEHOLD_DECLARATION, E::RESIDENCE];

    public function __construct(
        private readonly CreatePersonAction $persons = new CreatePersonAction,
        private readonly CreateFamilyAction $families = new CreateFamilyAction,
        private readonly CreateFamilyMembershipAction $memberships = new CreateFamilyMembershipAction,
        private readonly CreateFamilyResidenceAction $residences = new CreateFamilyResidenceAction,
        private readonly RecordHouseholdDeclarationAction $declarations = new RecordHouseholdDeclarationAction,
        private readonly RecordImportApplyEffectAction $provenance = new RecordImportApplyEffectAction,
    ) {}

    public function handle(ApprovedApplyPlan $plan, ImportRow $row, User $user): ImportRowApplyResult
    {
        try {
            return DB::transaction(fn () => $this->execute($plan, $row, $user));
        } catch (ImportApplyExecutionException $e) {
            throw $e;
        } catch (Throwable $e) {
            // The transaction is already rolled back; only a code leaves here.
            throw new ImportApplyExecutionException('UNEXPECTED_ERROR', $row->row_number, $e);
        }
    }

    private function execute(ApprovedApplyPlan $plan, ImportRow $row, User $user): ImportRowApplyResult
    {
        // ---- batch, row and plan integrity --------------------------------------
        /** @var ImportBatch|null $batch */
        $batch = ImportBatch::query()->whereKey($plan->batchId())->lockForUpdate()->first();
        /** @var ImportRow|null $locked */
        $locked = ImportRow::query()->whereKey($row->id)->lockForUpdate()->first();
        $n = $locked?->row_number ?? $row->row_number;
        if ($batch === null || $locked === null || (int) $locked->import_batch_id !== (int) $batch->id) {
            throw new ImportApplyExecutionException('ROW_PLAN_MISMATCH', $n);
        }
        if (! in_array($batch->status, [ImportBatchStatus::APPLYING, ImportBatchStatus::PARTIALLY_APPLIED], true)) {
            throw new ImportApplyExecutionException('BATCH_NOT_APPLYING', $n);
        }
        if ($batch->apply_plan_fingerprint === null || ! hash_equals($batch->apply_plan_fingerprint, $plan->fingerprint)) {
            throw new ImportApplyExecutionException('APPLY_PLAN_NOT_APPROVED', $n);
        }
        $rowPlan = $plan->rowForImportRow($locked->id);
        if ($rowPlan === null || $rowPlan->rowNumber !== $n) {
            throw new ImportApplyExecutionException('ROW_PLAN_MISMATCH', $n);
        }
        if ($locked->status === ImportRowStatus::APPLIED) {
            return $this->verifyApplied($plan, $rowPlan, $locked);
        }
        if (! $rowPlan->executable()) {
            throw new ImportApplyExecutionException('ROW_PLAN_BLOCKED', $n);
        }
        $this->assertShape($rowPlan, $batch, $n);
        if (ImportApplyRecord::query()->where('import_row_id', $locked->id)->exists()) {
            throw new ImportApplyExecutionException('PROVENANCE_CONFLICT', $n);
        }

        $date = $batch->apply_started_at->toDateString();
        $uid = $user->id;
        $record = fn (ImportApplyEffectPlan $e, O $outcome, ?Model $entity, ?string $reason = null) => $this->provenance->handle($locked, $e->effect, $outcome, $entity, $reason, $uid);
        $createdPersons = [];

        // ---- head ------------------------------------------------------------------
        $headPlan = $rowPlan->effect(E::HEAD_PERSON);
        if ($headPlan->intent === I::CREATE) {
            $head = $this->createPerson($headPlan, $n, $uid);
            $record($headPlan, O::CREATED, $head);
            $createdPersons[] = [E::HEAD_PERSON, $head];
        } else {
            $head = $this->existingPerson($headPlan, $n, 'HEAD_PERSON_MISSING');
            if (FamilyMembership::query()->where('person_id', $head->id)->where('is_active', true)->exists()) {
                throw new ImportApplyExecutionException('HEAD_PERSON_NOW_LINKED', $n);
            }
            $record($headPlan, O::REUSED, $head);
        }

        // ---- family (+ FAMILY_CREATED from the action, FAMILY_IMPORTED here) --------
        $familyPlan = $rowPlan->effect(E::FAMILY);
        $family = $this->createFamily($familyPlan, $date, $n, $uid);
        $record($familyPlan, O::CREATED, $family, in_array('HOUSEHOLD_HEAD_DECEASED', $rowPlan->warnings, true) ? 'HOUSEHOLD_HEAD_DECEASED' : null);
        FamilyActivityLog::record($family->id, FamilyActivityType::FAMILY_IMPORTED, $family, $uid, ['import_batch_id' => $batch->id, 'source_row_number' => $n]);

        // ---- HEAD membership ---------------------------------------------------------
        $headMembership = $this->attach($family, $head, RelationshipTypes::HEAD, true, $date, $uid, (int) $batch->clan_id, $n, 'HEAD_PERSON_NOW_LINKED');
        $record($rowPlan->effect(E::HEAD_MEMBERSHIP), O::CREATED, $headMembership);

        // ---- spouses, slot by slot ---------------------------------------------------
        $pending = [];
        foreach ($this->spouseSlots($rowPlan) as $slot) {
            $personPlan = $rowPlan->effect(E::spousePerson($slot));
            $membershipPlan = $rowPlan->effect(E::spouseMembership($slot));
            $spouse = null;

            if ($personPlan->intent === I::CREATE) {
                $spouse = $this->createPerson($personPlan, $n, $uid);
                $record($personPlan, O::CREATED, $spouse);
                $createdPersons[] = [$personPlan->effect, $spouse];
            } elseif ($personPlan->intent === I::REUSE && $personPlan->ownerRow === null) {
                $spouse = $this->existingPerson($personPlan, $n, 'SPOUSE_PERSON_MISSING');
                $record($personPlan, O::REUSED, $spouse);
            } elseif ($personPlan->intent === I::REUSE) {
                // A Person owned by another row: never created here.
                $spouse = $this->ownedPerson($plan, $personPlan, $n);
                if ($spouse !== null) {
                    $record($personPlan, O::REUSED, $spouse);
                } else {
                    $pending[] = $personPlan->effect->value;
                }
            } else {
                $record($personPlan, O::OMITTED, null, $personPlan->reason);
            }

            if ($membershipPlan->intent === I::CREATE) {
                // Generic rule: no registry write may depend on an unmaterialized Person.
                if ($spouse === null) {
                    throw new ImportApplyExecutionException('CROSS_ROW_PERSON_NOT_MATERIALIZED', $n);
                }
                $membership = $this->attach($family, $spouse, RelationshipTypes::SPOUSE, false, $date, $uid, (int) $batch->clan_id, $n, 'SPOUSE_PERSON_NOW_LINKED');
                $record($membershipPlan, O::CREATED, $membership);
            } else {
                $record($membershipPlan, O::OMITTED, null, $membershipPlan->reason);
            }
        }

        // ---- declaration, residence --------------------------------------------------
        $declarationPlan = $rowPlan->effect(E::HOUSEHOLD_DECLARATION);
        if ($declarationPlan->intent === I::CREATE) {
            // The Family was created by this row: no declaration is current yet (null).
            $declaration = $this->attempt(fn () => $this->declarations->handle($family, $declarationPlan->values, null, $uid), $n, 'DECLARATION_INVALID');
            $record($declarationPlan, O::CREATED, $declaration);
        } else {
            $record($declarationPlan, O::OMITTED, null, $declarationPlan->reason);
        }
        $residencePlan = $rowPlan->effect(E::RESIDENCE);
        if ($residencePlan->intent === I::CREATE) {
            $residence = $this->attempt(fn () => $this->residences->handle($family, [
                'original_residence_text' => $residencePlan->values['original_residence_text'],
                'source' => $residencePlan->values['source'],
            ], $date, $uid), $n, 'RESIDENCE_INVALID');
            $record($residencePlan, O::CREATED, $residence);
        } else {
            $record($residencePlan, O::OMITTED, null, $residencePlan->reason);
        }

        // ---- owner: complete REUSED links of rows that already ran -------------------
        $links = 0;
        foreach ($createdPersons as [$effect, $person]) {
            foreach ($plan->dependentsOf($n, $effect) as $dependent) {
                $dependentRow = ImportRow::query()->whereKey($dependent['row']->importRowId)->first();
                if ($dependentRow?->status !== ImportRowStatus::APPLIED) {
                    continue; // it records its own REUSE when it runs
                }
                // The dependent's approved identity must be this Person's.
                $this->assertIdentity($person, $dependent['effect'], $n);
                $this->provenance->handle($dependentRow, $dependent['effect']->effect, O::REUSED, $person, null, $uid);
                $links++;
            }
        }

        // ---- completeness, then the row link -------------------------------------------
        $records = ImportApplyRecord::query()->where('import_row_id', $locked->id)->count();
        if ($records !== count($rowPlan->effects) - count($pending)) {
            throw new ImportApplyExecutionException('ROW_EFFECT_INCOMPLETE', $n);
        }
        DB::table('import_rows')->where('id', $locked->id)->update([
            'status' => ImportRowStatus::APPLIED->value, 'family_id' => $family->id, 'updated_at' => now(),
        ]);

        return new ImportRowApplyResult(ImportRowApplyResult::APPLIED, $locked->id, $n, $family->id, $records, $pending, $links);
    }

    // ======================================================================== plan shape

    /** The mandatory effects exist with executable intents; the Family targets this batch's Clan. */
    private function assertShape(ImportRowApplyPlan $plan, ImportBatch $batch, int $n): void
    {
        foreach (self::MANDATORY as $effect) {
            if ($plan->effect($effect) === null) {
                throw new ImportApplyExecutionException('MANDATORY_EFFECT_MISSING', $n);
            }
        }
        $head = $plan->effect(E::HEAD_PERSON);
        if ($plan->intentOf(E::FAMILY) !== I::CREATE || $plan->intentOf(E::HEAD_MEMBERSHIP) !== I::CREATE
            || ! in_array($head->intent, [I::CREATE, I::REUSE], true) || ($head->intent === I::REUSE && $head->existingId === null)
            || (int) ($plan->effect(E::FAMILY)->values['clan_id'] ?? 0) !== (int) $batch->clan_id) {
            throw new ImportApplyExecutionException('ROW_PLAN_MISMATCH', $n);
        }
        foreach ($this->spouseSlots($plan) as $slot) {
            if ($plan->effect(E::spousePerson($slot)) === null || $plan->effect(E::spouseMembership($slot)) === null) {
                throw new ImportApplyExecutionException('MANDATORY_EFFECT_MISSING', $n);
            }
        }
        // Every Person REUSE carries its identity evidence.
        foreach ($plan->effects as $effect) {
            if ($effect->intent === I::REUSE && $effect->effect->entityType() === 'PERSON' && ! isset($effect->values['identity'])) {
                throw new ImportApplyExecutionException('ROW_PLAN_MISMATCH', $n);
            }
        }
    }

    /** @return list<int> source slots with spouse effects, ascending */
    private function spouseSlots(ImportRowApplyPlan $plan): array
    {
        $slots = array_unique(array_filter(array_map(fn (ImportApplyEffectPlan $e) => $e->effect->spouseSlot(), $plan->effects)));
        sort($slots);

        return array_values($slots);
    }

    // ======================================================================== execution helpers

    private function createPerson(ImportApplyEffectPlan $plan, int $n, int $uid): Person
    {
        // Only the values carried by the approved plan; initial life status as planned.
        return $this->attempt(fn () => $this->persons->handle($plan->values, $uid), $n, 'PERSON_VALUES_INVALID');
    }

    /** The exact planned registry Person, locked and still usable. */
    private function existingPerson(ImportApplyEffectPlan $plan, int $n, string $missingCode): Person
    {
        /** @var Person|null $person */
        $person = Person::withTrashed()->whereKey($plan->existingId)->lockForUpdate()->first();
        if ($person === null || $person->trashed() || $person->person_code !== $plan->existingCode) {
            throw new ImportApplyExecutionException($missingCode, $n);
        }
        $this->assertIdentity($person, $plan, $n);

        return $person;
    }

    /** The Person another row's effect CREATED, or null while that owner has not run. */
    private function ownedPerson(ApprovedApplyPlan $plan, ImportApplyEffectPlan $reuse, int $n): ?Person
    {
        $owner = $plan->row($reuse->ownerRow);
        if ($owner === null) {
            throw new ImportApplyExecutionException('ROW_PLAN_MISMATCH', $n);
        }
        $created = ImportApplyRecord::query()->where('import_row_id', $owner->importRowId)
            ->where('effect_key', $reuse->ownerEffect->value)->where('outcome', O::CREATED->value)->first();
        if ($created === null) {
            return null;
        }
        /** @var Person|null $person */
        $person = Person::withTrashed()->whereKey($created->entity_id)->lockForUpdate()->first();
        if ($person === null || $person->trashed()) {
            throw new ImportApplyExecutionException('SPOUSE_PERSON_MISSING', $n);
        }
        $this->assertIdentity($person, $reuse, $n);

        return $person;
    }

    /**
     * The Person still carries the exact National ID the planner matched on
     * (keyed fingerprint carried by the approved plan); never re-matched.
     */
    private function assertIdentity(Person $person, ImportApplyEffectPlan $reuse, int $n): void
    {
        if (! NationalIdFingerprint::matches($person->national_id, $reuse->values['identity'] ?? null)) {
            throw new ImportApplyExecutionException('PERSON_IDENTITY_CHANGED', $n);
        }
    }

    private function createFamily(ImportApplyEffectPlan $plan, string $date, int $n, int $uid): Family
    {
        $clanCode = Clan::query()->whereKey($plan->values['clan_id'])->value('code');
        $branchCode = null;
        if ($plan->values['branch_id'] !== null) {
            $branchCode = Branch::query()->whereKey($plan->values['branch_id'])->value('code');
            if ($branchCode === null) {
                throw new ImportApplyExecutionException('BRANCH_NOT_SELECTABLE', $n);
            }
        }
        try {
            return $this->families->handle([
                'clan_code' => $clanCode,
                'branch_code' => $branchCode,
                'registration_source' => $plan->values['registration_source'],
                // Every Family of one Apply run: the date Apply started.
                'registration_date' => $date,
            ], $uid);
        } catch (ValidationException $e) {
            $keys = array_keys($e->errors());
            throw new ImportApplyExecutionException(match (true) {
                in_array('branch_code', $keys, true) => 'BRANCH_NOT_SELECTABLE',
                in_array('clan_code', $keys, true) => 'CLAN_NOT_SELECTABLE',
                default => 'FAMILY_VALUES_INVALID',
            }, $n, $e);
        }
    }

    private function attach(Family $family, Person $person, string $type, bool $head, string $date, int $uid, int $clanId, int $n, string $linkedCode): FamilyMembership
    {
        try {
            return $this->memberships->handle($family, $person, $type, $head, $date, $uid, $clanId);
        } catch (MissingRelationshipTypeException $e) {
            throw new ImportApplyExecutionException('RELATIONSHIP_TYPE_UNAVAILABLE', $n, $e);
        } catch (ValidationException $e) {
            throw new ImportApplyExecutionException(array_key_exists('person', $e->errors()) ? $linkedCode : 'FAMILY_STATE_INVALID', $n, $e);
        }
    }

    /** Runs a canonical action, mapping its domain failures to stable codes. */
    private function attempt(callable $action, int $n, string $validationCode): mixed
    {
        try {
            return $action();
        } catch (DuplicateNationalIdException $e) {
            throw new ImportApplyExecutionException('NATIONAL_ID_TAKEN', $n, $e);
        } catch (MissingRelationshipTypeException $e) {
            throw new ImportApplyExecutionException('RELATIONSHIP_TYPE_UNAVAILABLE', $n, $e);
        } catch (ValidationException $e) {
            throw new ImportApplyExecutionException($validationCode, $n, $e);
        }
    }

    // ======================================================================== already applied

    /**
     * An APPLIED row is never re-executed: its Family and provenance must be
     * consistent with the approved plan (only a cross-row REUSE whose owner has
     * not run may still be missing). Never repaired automatically.
     */
    private function verifyApplied(ApprovedApplyPlan $plan, ImportRowApplyPlan $rowPlan, ImportRow $row): ImportRowApplyResult
    {
        $n = $row->row_number;
        $fail = fn () => throw new ImportApplyExecutionException('ROW_ALREADY_APPLIED_INCONSISTENT', $n);
        if ($row->family_id === null || ! Family::withTrashed()->whereKey($row->family_id)->exists()) {
            $fail();
        }
        $records = ImportApplyRecord::query()->where('import_row_id', $row->id)->get()->keyBy(fn ($r) => $r->effect_key->value);
        $pending = [];
        foreach ($rowPlan->effects as $key => $effect) {
            $record = $records[$key] ?? null;
            if ($record === null) {
                $ownerRan = $effect->intent === I::REUSE && $effect->ownerRow !== null
                    && ImportApplyRecord::query()->where('import_row_id', $plan->row($effect->ownerRow)->importRowId)->where('effect_key', $effect->ownerEffect->value)->exists();
                if ($effect->intent !== I::REUSE || $effect->ownerRow === null || $ownerRan) {
                    $fail();
                }
                $pending[] = $key;

                continue;
            }
            if ($record->outcome !== $effect->intent->outcome()) {
                $fail();
            }
        }
        if ($records->count() !== count($rowPlan->effects) - count($pending)
            || $records[E::FAMILY->value]->entity_id !== (int) $row->family_id
            || (int) FamilyMembership::query()->whereKey($records[E::HEAD_MEMBERSHIP->value]->entity_id)->value('family_id') !== (int) $row->family_id) {
            $fail();
        }

        return new ImportRowApplyResult(ImportRowApplyResult::ALREADY_APPLIED, $row->id, $n, (int) $row->family_id, $records->count(), $pending);
    }
}
