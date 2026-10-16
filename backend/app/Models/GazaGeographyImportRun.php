<?php

namespace App\Models;

use App\Enums\GazaGeographyImportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit record of one Gaza geographic import attempt (FB-GEO-01 Stage
 * 1C.1). Kept apart from the official geographic records; written only by
 * ImportGazaGeographyAction. The level counts are those of the source;
 * locations_* / flags_* are set when the run SUCCEEDED. error_summary is
 * sanitized: an import refusal and its problems, or only the class and
 * SQLSTATE of an unexpected error — never SQL, host, database or
 * credentials.
 */
class GazaGeographyImportRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'source_authority', 'source_filename', 'source_sha256', 'source_version', 'status',
        'started_at', 'finished_at',
        'governorates_count', 'cities_count', 'areas_count', 'neighborhoods_count', 'landmarks_count',
        'locations_created', 'locations_unchanged', 'locations_missing_from_source',
        'flags_created', 'flags_unchanged', 'error_summary',
    ];

    protected function casts(): array
    {
        return [
            'status' => GazaGeographyImportStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'governorates_count' => 'integer',
            'cities_count' => 'integer',
            'areas_count' => 'integer',
            'neighborhoods_count' => 'integer',
            'landmarks_count' => 'integer',
            'locations_created' => 'integer',
            'locations_unchanged' => 'integer',
            'locations_missing_from_source' => 'integer',
            'flags_created' => 'integer',
            'flags_unchanged' => 'integer',
        ];
    }
}
