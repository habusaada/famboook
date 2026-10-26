<?php

namespace App\Http\Resources;

use App\Models\CivilImportRequest;
use App\Models\CivilImportSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One catalogued import source (FB-CIVIL-01A-UI U2), for civil-import.stage
 * holders: an opaque id and the directory's safe label (O-2), the manifest
 * verdict as codes, and the latest validation and staging requests. Never a
 * path, a file name, file content or the manifest checksum.
 *
 * @property CivilImportSource $resource
 */
class CivilImportSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $validation = CivilImportRequest::query()->where('source_id', $s->id)->where('type', CivilImportRequest::VALIDATE_SOURCE)
            ->orderByDesc('queued_at')->first();
        $staged = CivilImportRequest::query()->where('source_id', $s->id)->where('type', CivilImportRequest::STAGE)
            ->whereNotNull('run_id')->orderByDesc('queued_at')->value('run_id');

        return [
            'id' => $s->id,
            'label' => $s->label,
            'table' => $s->source_table,
            'files' => $s->file_count,
            'total_rows' => $s->total_rows,
            'available' => $s->available,
            'manifest_status' => $s->manifest_status,
            'manifest_problems' => $s->manifest_problems ?? (object) [],
            'discovered_at' => $s->discovered_at?->toIso8601String(),
            'last_seen_at' => $s->last_seen_at?->toIso8601String(),
            'last_validation' => $validation === null ? null : [
                'request_id' => $validation->id,
                'status' => $validation->status,
                'reason_code' => $validation->reason_code,
                'problems' => $validation->result['problems'] ?? (object) [],
                'finished_at' => $validation->finished_at?->toIso8601String(),
            ],
            'staged_run_id' => $staged,
        ];
    }
}
