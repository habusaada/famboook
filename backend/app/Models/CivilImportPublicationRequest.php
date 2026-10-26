<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A two-person publication request for a VERIFIED identity release
 * (FB-CIVIL-01A-UI U3, ADR §3.3, D-UI-6/11/12). Enforced here and, on
 * PostgreSQL, by trg_civil_import_publication_guard:
 *
 *   PENDING_APPROVAL → APPROVED | REJECTED (another user) | WITHDRAWN (requester) | EXPIRED
 *   APPROVED         → EXECUTED | FAILED (the worker)
 *
 * Closed requests are never reused; the identity of a request (release,
 * baseline, requester, justification, snapshot, window) is immutable.
 * Codes and counts only — never an identity value or free text.
 */
class CivilImportPublicationRequest extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'requested_at';

    public const PENDING = 'PENDING_APPROVAL';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const WITHDRAWN = 'WITHDRAWN';

    public const EXPIRED = 'EXPIRED';

    public const EXECUTED = 'EXECUTED';

    public const FAILED = 'FAILED';

    public const OPEN = [self::PENDING, self::APPROVED];

    public const CLOSED = [self::REJECTED, self::WITHDRAWN, self::EXPIRED, self::EXECUTED, self::FAILED];

    /** D-UI-11. */
    public const LIFETIME_HOURS = 24;

    public const JUSTIFICATIONS = ['ROUTINE_REFRESH', 'INITIAL_LOAD', 'CORRECTION'];

    public const REJECTION_REASONS = ['COUNTS_UNEXPECTED', 'NOT_AUTHORIZED_NOW', 'NEEDS_REVIEW', 'OTHER_DECISION'];

    private const TRANSITIONS = [
        self::PENDING => [self::APPROVED, self::REJECTED, self::WITHDRAWN, self::EXPIRED],
        self::APPROVED => [self::EXECUTED, self::FAILED],
    ];

    private const IMMUTABLE = ['id', 'release_id', 'run_id', 'base_release_id', 'requested_by', 'justification_code', 'snapshot',
        'requested_at', 'expires_at'];

    protected $fillable = ['release_id', 'run_id', 'base_release_id', 'requested_by', 'justification_code', 'snapshot', 'expires_at'];

    protected static function booted(): void
    {
        static::updating(function (self $request) {
            $from = $request->getOriginal('status');
            if (in_array($from, self::CLOSED, true)) {
                throw new LogicException('A closed publication request is immutable.');
            }
            foreach (self::IMMUTABLE as $column) {
                if ($request->isDirty($column)) {
                    throw new LogicException('The identity of a publication request is immutable.');
                }
            }
            $to = $request->status;
            if ($to !== $from && ! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new LogicException("Publication request transition {$from} -> {$to} is not allowed.");
            }
            if ($request->decided_by !== null && (int) $request->decided_by === (int) $request->requested_by) {
                throw new LogicException('The requester never decides their own publication request.');
            }
        });
        static::deleting(function () {
            throw new LogicException('Publication requests are history and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'release_id' => 'integer',
            'base_release_id' => 'integer',
            'snapshot' => 'array',
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isExpiredAt(Carbon $now): bool
    {
        return $this->expires_at->lte($now);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The worker's PUBLISH request, created by the approval. */
    public function publishRequest(): HasOne
    {
        return $this->hasOne(CivilImportRequest::class, 'publication_request_id');
    }
}
