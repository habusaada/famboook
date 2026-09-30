<?php

namespace App\Support\Import\Apply;

use App\Enums\ImportApplyEffect;
use App\Enums\ImportApplyIntent;

/**
 * The complete plan for ONE staged household row (docs/03 §96b): every
 * effect with its intent, the blocking reasons and the non-blocking
 * warnings. Immutable; a future Apply executes it without re-deciding
 * business rules.
 */
final readonly class ImportRowApplyPlan
{
    /**
     * @param  array<string, ImportApplyEffectPlan>  $effects  keyed by ImportApplyEffect value
     * @param  list<string>  $warnings  non-blocking evidence codes
     * @param  list<string>  $rowBlocks  blocking codes not tied to one effect
     * @param  array<int, ?string>  $spouseNationalIdsMasked  source slot => masked ID (display only)
     */
    public function __construct(
        public int $importRowId,
        public int $rowNumber,
        public ?string $sourceFamilyKey,
        public ?string $headNationalIdMasked,
        public array $effects,
        public array $warnings = [],
        public array $rowBlocks = [],
        public array $spouseNationalIdsMasked = [],
    ) {}

    public function effect(ImportApplyEffect $effect): ?ImportApplyEffectPlan
    {
        return $this->effects[$effect->value] ?? null;
    }

    /** @return list<string> every blocking reason (row-level first, then per effect) */
    public function blockReasons(): array
    {
        $reasons = $this->rowBlocks;
        foreach ($this->effects as $plan) {
            if ($plan->blocked()) {
                $reasons[] = $plan->reason;
            }
        }

        return array_values(array_unique($reasons));
    }

    public function executable(): bool
    {
        return $this->blockReasons() === [];
    }

    /** @return list<ImportApplyEffectPlan> */
    public function spouseEffects(): array
    {
        return array_values(array_filter($this->effects, fn (ImportApplyEffectPlan $p) => $p->effect->spouseSlot() !== null));
    }

    public function intentOf(ImportApplyEffect $effect): ?ImportApplyIntent
    {
        return $this->effect($effect)?->intent;
    }

    public function signature(): array
    {
        return [
            $this->importRowId, $this->rowNumber, $this->warnings, $this->rowBlocks,
            array_map(fn (ImportApplyEffectPlan $p) => $p->signature(), $this->effects),
        ];
    }
}
