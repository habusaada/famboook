<?php

namespace App\Support\Import\Apply;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\DB;

/**
 * Progress of an Apply, read from the DATABASE (docs/03 §96b) — never an
 * in-memory percentage. Identifiers, counts and safe codes only: no names,
 * National IDs or source values. For the future API.
 */
final readonly class ImportApplyProgress
{
    public function __construct(
        public string $outcome,
        public string $status,
        public int $totalRows,
        public int $appliedRows,
        public int $remainingRows,
        public int $executedInChunk = 0,
        public int $skippedAlreadyApplied = 0,
        public ?int $pendingLinks = null,
        public ?string $errorCode = null,
        public ?int $errorRowNumber = null,
    ) {}

    public function completed(): bool
    {
        return $this->status === ImportBatchStatus::APPLIED->value;
    }

    /** Snapshot of the batch as the database holds it now. */
    public static function of(ImportBatch $batch, string $outcome, int $executed = 0, int $skipped = 0, ?int $pendingLinks = null): self
    {
        $batch = $batch->fresh();
        $rows = DB::table('import_rows')->where('import_batch_id', $batch->id);
        $total = (clone $rows)->count();
        $applied = (clone $rows)->where('status', 'APPLIED')->count();

        return new self(
            $outcome, $batch->status->value, $total, $applied, $total - $applied, $executed, $skipped,
            $pendingLinks, $batch->apply_error_code, $batch->apply_error_row_number,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'status' => $this->status,
            'total_rows' => $this->totalRows,
            'applied_rows' => $this->appliedRows,
            'remaining_rows' => $this->remainingRows,
            'executed_in_chunk' => $this->executedInChunk,
            'skipped_already_applied' => $this->skippedAlreadyApplied,
            'pending_links' => $this->pendingLinks,
            'completed' => $this->completed(),
            'error_code' => $this->errorCode,
            'error_row_number' => $this->errorRowNumber,
        ];
    }
}
