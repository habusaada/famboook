<?php

namespace App\Support\Import\Apply;

/**
 * What executing one import row did (docs/03 §96b). Identifiers and counts
 * only — never names, National IDs or source values.
 */
final readonly class ImportRowApplyResult
{
    public const APPLIED = 'APPLIED';

    public const ALREADY_APPLIED = 'ALREADY_APPLIED';

    /**
     * @param  list<string>  $pendingEffects  cross-row REUSE effects of this row still awaiting their owner
     */
    public function __construct(
        public string $outcome,
        public int $importRowId,
        public int $rowNumber,
        public int $familyId,
        public int $provenanceRecords,
        public array $pendingEffects = [],
        public int $completedDependentLinks = 0,
    ) {}
}
