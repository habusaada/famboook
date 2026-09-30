<?php

namespace App\Support\Import\Apply;

use App\Enums\ImportApplyEffect;
use App\Enums\ImportApplyIntent;

/**
 * The plan for a whole batch (docs/03 §96b): batch-level precondition
 * failures (when any fails, no row is planned) and one plan per staged row.
 * Immutable and deterministic — the same inputs always give the same plan
 * and the same fingerprint.
 */
final readonly class ImportBatchApplyPlan
{
    /**
     * @param  list<array{code: string, count?: int}>  $preconditionFailures
     * @param  list<ImportRowApplyPlan>  $rows  in source row order
     */
    public function __construct(
        public int $batchId,
        public ?string $reconciliationFingerprint,
        public array $preconditionFailures,
        public array $rows,
    ) {}

    public function preconditionsMet(): bool
    {
        return $this->preconditionFailures === [];
    }

    /** Every row can be executed as planned (conservative batch readiness). */
    public function executable(): bool
    {
        return $this->preconditionsMet() && $this->rows !== [] && $this->blockedRows() === 0;
    }

    public function blockedRows(): int
    {
        return count(array_filter($this->rows, fn (ImportRowApplyPlan $r) => ! $r->executable()));
    }

    public function row(int $rowNumber): ?ImportRowApplyPlan
    {
        foreach ($this->rows as $row) {
            if ($row->rowNumber === $rowNumber) {
                return $row;
            }
        }

        return null;
    }

    /** SHA-256 of the full plan: equal only for an identical plan. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->batchId, $this->reconciliationFingerprint, $this->preconditionFailures,
            array_map(fn (ImportRowApplyPlan $r) => $r->signature(), $this->rows),
        ]));
    }

    /**
     * Aggregate counts (no identity data).
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $group = fn (ImportApplyEffect $e) => match (true) {
            $e === ImportApplyEffect::HEAD_PERSON => 'head_persons',
            $e === ImportApplyEffect::FAMILY => 'families',
            $e === ImportApplyEffect::HEAD_MEMBERSHIP => 'head_memberships',
            $e === ImportApplyEffect::HOUSEHOLD_DECLARATION => 'declarations',
            $e === ImportApplyEffect::RESIDENCE => 'residences',
            $e->entityType() === 'PERSON' => 'spouse_persons',
            default => 'spouse_memberships',
        };
        $intents = array_fill_keys(array_map(fn ($i) => $i->value, ImportApplyIntent::cases()), 0);
        $effects = array_fill_keys(['families', 'head_persons', 'head_memberships', 'spouse_persons', 'spouse_memberships', 'declarations', 'residences'], $intents);
        $reasons = [];
        $warnings = [];
        $spouseReuse = ['existing' => 0, 'planned' => 0];
        $reusedExisting = [];
        $warningRows = 0;

        foreach ($this->rows as $row) {
            $warningRows += $row->warnings === [] ? 0 : 1;
            foreach ($row->warnings as $code) {
                $warnings[$code] = ($warnings[$code] ?? 0) + 1;
            }
            foreach ($row->rowBlocks as $code) {
                $reasons[$code] = ($reasons[$code] ?? 0) + 1;
            }
            foreach ($row->effects as $plan) {
                $effects[$group($plan->effect)][$plan->intent->value]++;
                if ($plan->reason !== null) {
                    $reasons[$plan->reason] = ($reasons[$plan->reason] ?? 0) + 1;
                }
                if ($plan->intent === ImportApplyIntent::REUSE && $plan->effect->entityType() === 'PERSON') {
                    if ($plan->existingId !== null) {
                        $reusedExisting[$plan->existingId] = true;
                    }
                    if ($plan->effect !== ImportApplyEffect::HEAD_PERSON) {
                        $spouseReuse[$plan->existingId !== null ? 'existing' : 'planned']++;
                    }
                }
            }
        }
        ksort($reasons);
        ksort($warnings);

        return [
            'source_rows' => count($this->rows),
            'executable_rows' => count($this->rows) - $this->blockedRows(),
            'blocked_rows' => $this->blockedRows(),
            'warning_rows' => $warningRows,
            'effects' => $effects,
            'spouse_slots' => array_sum($effects['spouse_persons']),
            'spouse_person_reuse' => $spouseReuse,
            'persons' => [
                'create' => $effects['head_persons']['CREATE'] + $effects['spouse_persons']['CREATE'],
                'reuse_existing' => count($reusedExisting),
            ],
            'reasons' => $reasons,
            'warnings' => $warnings,
        ];
    }
}
