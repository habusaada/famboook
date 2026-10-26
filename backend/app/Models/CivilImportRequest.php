<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One civil identity import execution request (FB-CIVIL-01A-UI U2, ADR
 * §3.2). The web application only creates requests (QUEUED) and cancels them;
 * the dedicated worker runs them. State machine, enforced here and, on
 * PostgreSQL, by trg_civil_import_request_guard:
 *
 *   QUEUED  → RUNNING (worker) | CANCELLED (operator)
 *   RUNNING → SUCCEEDED | FAILED (worker) | CANCELLED (worker, after a cancellation request)
 *
 * Finished requests and the request's identity (type, requester, target,
 * idempotency key) are immutable; requests are never deleted. `result` holds
 * allow-listed counts and codes only (CivilImportResult); `message` is the
 * fixed text of `reason_code`.
 */
class CivilImportRequest extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'queued_at';

    public const QUEUED = 'QUEUED';

    public const RUNNING = 'RUNNING';

    public const SUCCEEDED = 'SUCCEEDED';

    public const FAILED = 'FAILED';

    public const CANCELLED = 'CANCELLED';

    public const ACTIVE = [self::QUEUED, self::RUNNING];

    public const FINISHED = [self::SUCCEEDED, self::FAILED, self::CANCELLED];

    // Types (U2). PUBLISH (U3) is created ONLY by the approval of a publication
    // request (ApproveCivilImportPublicationAction) — never by the queue action.
    public const VALIDATE_SOURCE = 'VALIDATE_SOURCE';

    public const STAGE = 'STAGE';

    public const ABORT = 'ABORT';

    public const ASSESS = 'ASSESS';

    public const PREPARE = 'PREPARE';

    public const VERIFY = 'VERIFY';

    public const PUBLISH = 'PUBLISH';

    /** The types QueueCivilImportRequestAction accepts. PUBLISH is deliberately absent. */
    public const TYPES = [self::VALIDATE_SOURCE, self::STAGE, self::ABORT, self::ASSESS, self::PREPARE, self::VERIFY];

    /** Types the operator may stop while RUNNING (at the next committed chunk). */
    public const CANCELLABLE_WHILE_RUNNING = [self::VALIDATE_SOURCE, self::STAGE, self::PREPARE];

    private const TRANSITIONS = [
        self::QUEUED => [self::RUNNING, self::CANCELLED],
        self::RUNNING => [self::SUCCEEDED, self::FAILED, self::CANCELLED],
    ];

    private const IMMUTABLE = ['id', 'type', 'requested_by', 'source_id', 'idempotency_key', 'queued_at', 'publication_request_id'];

    protected $fillable = [
        'type', 'requested_by', 'source_id', 'run_id', 'release_id', 'idempotency_key', 'publication_request_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $request) {
            $from = $request->getOriginal('status');
            if (in_array($from, self::FINISHED, true)) {
                throw new LogicException('A finished civil import request is immutable.');
            }
            foreach (self::IMMUTABLE as $column) {
                if ($request->isDirty($column)) {
                    throw new LogicException('The identity of a civil import request is immutable.');
                }
            }
            foreach (['run_id', 'release_id'] as $column) {
                if ($request->getOriginal($column) !== null && $request->isDirty($column)) {
                    throw new LogicException('The target of a civil import request is immutable.');
                }
            }
            $to = $request->status;
            if ($to !== $from && ! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new LogicException("Civil import request transition {$from} -> {$to} is not allowed.");
            }
            if ($from === self::RUNNING && $to === self::CANCELLED && $request->cancel_requested_at === null) {
                throw new LogicException('A running request is cancelled only after a cancellation request.');
            }
        });
        static::deleting(function () {
            throw new LogicException('Civil import requests are history and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'release_id' => 'integer',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(CivilImportSource::class, 'source_id');
    }
}
