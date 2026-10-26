<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The liveness of one import worker instance (FB-CIVIL-01A-UI U2, ADR §4),
 * written by the worker; read by the web application to describe the worker.
 */
class CivilImportWorkerHeartbeat extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'instance';

    protected $keyType = 'string';

    protected $fillable = ['instance', 'state', 'current_request_id', 'started_at', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
