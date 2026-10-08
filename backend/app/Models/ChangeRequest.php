<?php

namespace App\Models;

use App\Enums\ChangeRequestRejectionReason;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Support\ChangeRequests\ChangeRequestCodes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * A Change Request (docs/02 §49–§57, PWA-5a): one family's proposal of one
 * typed change to its canonical registry, with its review decision and apply
 * outcome. Never canonical data itself.
 *
 * - `uuid` is the public identifier (also the route key); `request_code`
 *   (CRQ-000001) the human reference; `id` never leaves the server.
 * - `status` is not mass assignable: only the PWA-5b Domain Actions move it,
 *   along ChangeRequestTransitions.
 * - The proposal is immutable once submitted (AE-5): type, target, payload,
 *   base fingerprint and requester never change outside a DRAFT. A terminal
 *   request does not change at all. Requests are never deleted.
 * - base_fingerprint is hidden from every serialization and never returned.
 */
class ChangeRequest extends Model
{
    use HasFactory, HasUuids;

    /** Fixed at submission; editable only while DRAFT (unused in V1). */
    private const PROPOSAL_FIELDS = [
        'uuid', 'request_code', 'family_id', 'type', 'payload_version', 'person_id', 'target_membership_id',
        'submitted_data', 'reason', 'base_fingerprint', 'base_key_version', 'submitted_by', 'submitted_by_person_id',
        'client_reference',
    ];

    protected $fillable = [
        'family_id',
        'type',
        'payload_version',
        'person_id',
        'target_membership_id',
        'submitted_data',
        'reason',
        'base_fingerprint',
        'base_key_version',
        'submitted_by',
        'submitted_by_person_id',
        'client_reference',
    ];

    protected $hidden = [
        'id',
        'base_fingerprint',
        'base_key_version',
    ];

    protected static function booted(): void
    {
        static::creating(function (ChangeRequest $request) {
            $request->request_code ??= ChangeRequestCodes::next();
        });

        static::updating(function (ChangeRequest $request) {
            $original = ChangeRequestStatus::from($request->getRawOriginal('status'));
            if ($original->isTerminal()) {
                throw new LogicException('A finished change request is immutable.');
            }
            $proposalChanged = array_intersect(array_keys($request->getDirty()), self::PROPOSAL_FIELDS) !== [];
            if ($proposalChanged && $original !== ChangeRequestStatus::DRAFT) {
                throw new LogicException('A submitted change request proposal is immutable.');
            }
        });

        static::deleting(function () {
            throw new LogicException('Change requests are never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'type' => ChangeRequestType::class,
            'status' => ChangeRequestStatus::class,
            'rejection_reason_code' => ChangeRequestRejectionReason::class,
            'submitted_data' => 'array',
            'payload_version' => 'integer',
            'base_key_version' => 'integer',
            'apply_failure_count' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'applied_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_apply_failed_at' => 'datetime',
        ];
    }

    /** The auto-generated UUID is the public key only; `id` stays the PK. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** Route binding by the public UUID, never the internal id. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Only a well-formed UUID is looked up: anything else (an internal id, a
     * request code, junk) is simply not found — and never reaches a
     * PostgreSQL uuid comparison.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return is_string($value) && Str::isUuid($value)
            ? $this->newQuery()->where($field ?? $this->getRouteKeyName(), $value)->first()
            : null;
    }

    /** The request's Family for good — still resolved if the Family is later archived (soft-deleted). */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class)->withTrashed();
    }

    /** The targeted Person, when the type names one. */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    /** The targeted membership, when the request names a household member. */
    public function targetMembership(): BelongsTo
    {
        return $this->belongsTo(FamilyMembership::class, 'target_membership_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** The Person the submitting account acted as (User ≠ Person). */
    public function submitterPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'submitted_by_person_id')->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** The append-only transition history, oldest first. */
    public function workflowEvents(): MorphMany
    {
        return $this->morphMany(WorkflowEvent::class, 'workflowable')->orderBy('id');
    }
}
