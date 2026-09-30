<?php

namespace App\Support\Import\Apply;

use App\Enums\ImportApplyEffect;
use App\Enums\ImportApplyIntent;
use App\Exceptions\ImportApplyExecutionException;
use App\Models\ImportBatch;

/**
 * A batch plan PROVEN to be the approved one (docs/03 §96b): it can only be
 * built by verify(), which requires that its fingerprint equals the batch's
 * stored apply_plan_fingerprint. The row executor accepts nothing else, so a
 * hand-built or altered plan is never trusted execution input. The future
 * runner builds it once per chunk from the planner (as-of-Apply-start).
 */
final class ApprovedApplyPlan
{
    /** @var array<int, ImportRowApplyPlan> by import_rows.id */
    private array $byImportRowId = [];

    /** @var array<string, list<array{row: ImportRowApplyPlan, effect: ImportApplyEffectPlan}>> "ownerRow|ownerEffect" => dependents */
    private array $dependents = [];

    private function __construct(public readonly ImportBatchApplyPlan $plan, public readonly string $fingerprint)
    {
        foreach ($plan->rows as $row) {
            $this->byImportRowId[$row->importRowId] = $row;
            foreach ($row->effects as $effect) {
                if ($effect->intent === ImportApplyIntent::REUSE && $effect->ownerRow !== null) {
                    $this->dependents[$effect->ownerRow.'|'.$effect->ownerEffect->value][] = ['row' => $row, 'effect' => $effect];
                }
            }
        }
    }

    public static function verify(ImportBatch $batch, ImportBatchApplyPlan $plan): self
    {
        $fingerprint = $plan->fingerprint();
        if ($batch->apply_plan_fingerprint === null || $plan->batchId !== $batch->id || ! $plan->preconditionsMet()
            || ! hash_equals($batch->apply_plan_fingerprint, $fingerprint)) {
            throw new ImportApplyExecutionException('APPLY_PLAN_NOT_APPROVED');
        }

        return new self($plan, $fingerprint);
    }

    public function batchId(): int
    {
        return $this->plan->batchId;
    }

    public function rowForImportRow(int $importRowId): ?ImportRowApplyPlan
    {
        return $this->byImportRowId[$importRowId] ?? null;
    }

    public function row(int $rowNumber): ?ImportRowApplyPlan
    {
        return $this->plan->row($rowNumber);
    }

    /**
     * Effects in OTHER rows that reuse the Person this row's effect creates.
     *
     * @return list<array{row: ImportRowApplyPlan, effect: ImportApplyEffectPlan}>
     */
    public function dependentsOf(int $ownerRowNumber, ImportApplyEffect $ownerEffect): array
    {
        return $this->dependents[$ownerRowNumber.'|'.$ownerEffect->value] ?? [];
    }
}
