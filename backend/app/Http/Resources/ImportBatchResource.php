<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An import batch for the Import Wizard (docs/03 §96a). Batch-level facts
 * only, enough to reopen the Wizard: never row payloads, cell values, National
 * IDs, names, internal ids or the private file path. Worksheets are described
 * by name and counts; columns are served by the columns endpoint.
 */
class ImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $worksheets = collect($this->inspection['worksheets'] ?? [])->map(fn (array $sheet) => [
            'name' => $sheet['name'],
            'data_rows' => $sheet['data_rows'],
            'column_count' => count($sheet['columns']),
            'plausible' => $sheet['plausible'],
            'recognized' => $sheet['recognized'],
            'too_many_rows' => $sheet['too_many_rows'],
        ])->values()->all();

        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'import_mode' => $this->import_mode,
            'source_filename' => $this->source_filename,
            'source_size_bytes' => $this->source_size_bytes,
            'row_count' => $this->row_count,
            'clan' => $this->whenLoaded('clan', fn () => ['code' => $this->clan->code, 'name' => $this->clan->name]),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader ? ['name' => $this->uploader->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'worksheets' => $worksheets,
            'suggested_worksheet' => $this->inspection['suggested_worksheet'] ?? null,
            'worksheet_name' => $this->worksheet_name,
            // The confirmed mapping (column letters only).
            'column_mapping' => $this->column_mapping,
            'mapping_confirmed_at' => $this->mapping_confirmed_at?->toIso8601String(),
            'staged' => $this->isStaged(),
        ];
    }
}
