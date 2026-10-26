<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A catalogued server-side import source (FB-CIVIL-01A-UI U2, D-UI-3): a
 * directory under the worker's source root, written by the worker only
 * (CivilImportSourceCatalogue). The web application knows an opaque id and
 * the directory's safe label — never a path, a file name or file content.
 * manifest_sha256 detects a change between cataloguing and staging and is
 * never returned by the API.
 */
class CivilImportSource extends Model
{
    use HasUuids;

    public const CREATED_AT = 'discovered_at';

    public const VALID = 'VALID';

    public const INVALID = 'INVALID';

    /** Same rule as chk_civil_import_source_label. */
    public const LABEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    protected $fillable = [
        'label', 'manifest_status', 'manifest_problems', 'source_table', 'file_count', 'total_rows', 'manifest_sha256',
        'available', 'last_seen_at',
    ];

    protected $hidden = ['manifest_sha256'];

    protected function casts(): array
    {
        return [
            'manifest_problems' => 'array',
            'file_count' => 'integer',
            'total_rows' => 'integer',
            'available' => 'boolean',
            'discovered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function isReady(): bool
    {
        return $this->available && $this->manifest_status === self::VALID;
    }
}
