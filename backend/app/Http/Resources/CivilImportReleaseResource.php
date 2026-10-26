<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One identity reference release and its preparation (policy, counts,
 * warning counts) for the civil import screens (FB-CIVIL-01A-UI U1).
 * Counts and codes only.
 *
 * @property array<string, mixed> $resource
 */
class CivilImportReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $p = $r['preparation'];

        return [
            'id' => $r['id'],
            'status' => $r['status'],
            'run_id' => $r['run_id'],
            'base_release_id' => $r['base_release_id'],
            'record_count' => $r['record_count'],
            'is_active' => $r['is_active'],
            'preparation' => $p === null ? null : [
                'status' => $p['status'],
                'policy_id' => $p['policy_id'],
                'comparison_policy' => $p['comparison_policy'],
                'blank_field_policy' => $p['blank_field_policy'],
                'quarantine' => $p['quarantine'],
                'counts' => [
                    'staged' => $p['counts']['staged'],
                    'new' => $p['counts']['new'],
                    'changed' => $p['counts']['changed'],
                    'unchanged' => $p['counts']['unchanged'],
                    'conflict' => $p['counts']['conflict'],
                    'carried_forward' => $p['counts']['carried_forward'],
                    'blank_over_value' => $p['counts']['blank_over_value'],
                    'expected_presence' => $p['counts']['expected_presence'],
                ],
                'warnings' => $p['warnings'],
                'prepared_at' => $p['prepared_at'],
                'verified_at' => $p['verified_at'],
            ],
            'created_at' => $r['created_at'],
            'published_at' => $r['published_at'],
            'abandoned_at' => $r['abandoned_at'],
        ];
    }
}
