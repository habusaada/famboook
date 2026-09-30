<?php

namespace App\Support\Import\Apply;

use App\Enums\ImportApplyOutcome;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\DB;

/**
 * The context the SAME ImportApplyPlanner plans in (docs/03 §96b):
 *
 * - current(): the Dry Run / Apply start — the registry as it is now, with
 *   every precondition.
 * - asOfApplyStart($batch): resume / completion verification — reconstructs
 *   the plan that was approved when Apply started. The registry entities
 *   this batch's provenance proves it CREATED (Persons and memberships) are
 *   this batch's own expected changes and are ignored while planning; every
 *   OTHER registry fact is read live, so an external change that alters a
 *   decision still changes the plan fingerprint. Preconditions that Apply
 *   itself invalidates (status, applied rows, provenance, registry-marker
 *   freshness) are replaced by "Apply has started".
 *
 * Read only: it loads ids from import_apply_records and writes nothing.
 */
final readonly class ImportApplyPlanningContext
{
    /**
     * @param  array<int, true>  $batchCreatedPersons
     * @param  array<int, true>  $batchCreatedMemberships
     */
    private function __construct(
        public bool $asOfApplyStart,
        private array $batchCreatedPersons,
        private array $batchCreatedMemberships,
    ) {}

    public static function current(): self
    {
        return new self(false, [], []);
    }

    public static function asOfApplyStart(ImportBatch $batch): self
    {
        $persons = [];
        $memberships = [];
        $created = DB::table('import_apply_records')->where('import_batch_id', $batch->id)
            ->where('outcome', ImportApplyOutcome::CREATED->value)
            ->whereIn('entity_type', ['PERSON', 'MEMBERSHIP'])
            ->get(['entity_type', 'entity_id']);
        foreach ($created as $record) {
            if ($record->entity_type === 'PERSON') {
                $persons[(int) $record->entity_id] = true;
            } else {
                $memberships[(int) $record->entity_id] = true;
            }
        }

        return new self(true, $persons, $memberships);
    }

    /** A Person this batch created (ignored when reconstructing the approved plan). */
    public function ownsPerson(int $personId): bool
    {
        return isset($this->batchCreatedPersons[$personId]);
    }

    /** A membership this batch created (ignored when reconstructing the approved plan). */
    public function ownsMembership(int $membershipId): bool
    {
        return isset($this->batchCreatedMemberships[$membershipId]);
    }
}
