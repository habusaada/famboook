<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * A run with its files (by ordinal only, D-UI-8), counters and release
 * (FB-CIVIL-01A-UI U1). File names are served only by the locations endpoint.
 *
 * @property array<string, mixed> $resource
 */
class CivilImportRunDetailResource extends CivilImportRunResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return parent::toArray($request) + [
            'source_table' => $r['source_table'],
            'counters' => [
                'new_records' => $r['counters']['new_records'],
                'new_versions' => $r['counters']['new_versions'],
                'unchanged' => $r['counters']['unchanged'],
                'carried_forward' => $r['counters']['carried_forward'],
            ],
            'files' => array_map(fn (array $f) => [
                'file_no' => $f['file_no'],
                'status' => $f['status'],
                'expected_rows' => $f['expected_rows'],
                'loaded_rows' => $f['loaded_rows'],
                'checksum_verified' => $f['checksum_verified'],
                'chunks' => $f['chunks'],
                'last_chunk_at' => $f['last_chunk_at'],
            ], $r['files']),
            'release' => $r['release'] === null ? null : [
                'id' => $r['release']['id'],
                'status' => $r['release']['status'],
                'base_release_id' => $r['release']['base_release_id'],
                'preparation_status' => $r['release']['preparation_status'],
            ],
            'allowed_actions' => $r['allowed_actions'],
            'active_request' => $r['active_request'],
        ];
    }
}
