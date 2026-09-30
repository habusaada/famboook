<?php

namespace App\Support\Import\Apply;

use App\Enums\ImportApplyEffect;
use App\Enums\ImportApplyIntent;

/**
 * The planned decision for ONE effect of one import row (docs/03 §96b).
 * Immutable. `values` are the approved source-derived values a future Apply
 * needs to execute a CREATE — they may contain identity data and are never
 * presented by the Dry Run.
 *
 * A REUSE points either at an existing registry entity (existingId /
 * existingCode) or at the effect that plans the entity inside this batch
 * (ownerRow + ownerEffect): the canonical creating effect of a Person that
 * does not exist yet.
 */
final readonly class ImportApplyEffectPlan
{
    /** @param array<string, mixed> $values */
    public function __construct(
        public ImportApplyEffect $effect,
        public ImportApplyIntent $intent,
        public ?string $reason = null,
        public ?int $existingId = null,
        public ?string $existingCode = null,
        public ?int $ownerRow = null,
        public ?ImportApplyEffect $ownerEffect = null,
        public array $values = [],
    ) {}

    public function blocked(): bool
    {
        return $this->intent === ImportApplyIntent::BLOCK;
    }

    /** Deterministic, value-free description (for the plan fingerprint and tests). */
    public function signature(): array
    {
        return [
            $this->effect->value, $this->intent->value, $this->reason, $this->existingId,
            $this->ownerRow, $this->ownerEffect?->value, $this->values,
        ];
    }
}
