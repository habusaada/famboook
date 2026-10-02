<?php

namespace App\Models;

use App\Enums\UserPersonLinkStatus;
use App\Enums\UserPersonLinkType;
use App\Enums\UserPersonLinkVerificationMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The explicit User-Person Link (docs/02 §41-§45b, docs/04 §55b): "this
 * User is this Person". Never deleted — ended links are history. VERIFIED
 * means the relation is proven; ACTIVE means proven and enabled.
 *
 * Schema foundation only (PWA-1C): the lifecycle actions and the eligibility
 * resolver arrive with PWA-1D. A link by itself grants no Family access.
 */
class UserPersonLink extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'person_id',
        'link_type',
        'status',
        'verification_method',
        'verified_by',
        'verified_at',
        'activated_at',
        'suspended_at',
        'suspended_by',
        'suspension_reason',
        'ended_at',
        'ended_by',
        'end_reason',
    ];

    protected function casts(): array
    {
        return [
            'link_type' => UserPersonLinkType::class,
            'status' => UserPersonLinkStatus::class,
            'verification_method' => UserPersonLinkVerificationMethod::class,
            'verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** The auto-generated UUID is a public key only; `id` stays the PK. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function suspender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }

    public function ender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    /** Links that occupy the one-per-User / one-per-Person slot. */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereIn('status', [UserPersonLinkStatus::ACTIVE->value, UserPersonLinkStatus::SUSPENDED->value]);
    }
}
