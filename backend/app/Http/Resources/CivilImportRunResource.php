<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One identity reference import run for the civil import screens
 * (FB-CIVIL-01A-UI U1). Built from IdentityImportMonitor's metadata array;
 * every key is listed explicitly. Never a file name, a SHA value, an
 * internal file id or any identity value.
 *
 * @property array<string, mixed> $resource
 */
class CivilImportRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'id' => $r['id'],
            'phase' => $r['phase'],
            'run_status' => $r['run_status'],
            'error_code' => $r['error_code'],
            'expected_rows' => $r['expected_rows'],
            'rows_loaded' => $r['rows_loaded'],
            'issues' => $r['issues'],
            'file_count' => $r['file_count'],
            'release_id' => $r['release_id'],
            'requested_by' => $r['requested_by'],
            'started_at' => $r['started_at'],
            'finished_at' => $r['finished_at'],
        ];
    }
}
