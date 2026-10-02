<?php

namespace App\Models;

use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An OTP challenge (docs/02 §45b, docs/04 §55b), bound to one purpose, one
 * Person and one trusted mobile. code_hash is a keyed hash (context
 * OTP_CODE), hidden from every serialization; the plaintext code is never
 * stored.
 *
 * Schema foundation only (PWA-1C): generating, sending, verifying and
 * superseding challenges is PWA-1E. Operational records, purgeable 90 days
 * after they finish.
 */
class AuthOtpChallenge extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'purpose',
        'person_id',
        'user_id',
        'mobile_trust_id',
        'code_hash',
        'expires_at',
        'attempts',
        'send_count',
        'last_sent_at',
        'verified_at',
        'grant_expires_at',
        'consumed_at',
        'superseded_at',
        'locked_at',
        'ip',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'attempts' => 'integer',
            'send_count' => 'integer',
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'verified_at' => 'datetime',
            'grant_expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'superseded_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    /** The auto-generated UUID is a public key only; `id` stays the PK. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Open = not consumed, not superseded and not locked (the partial unique
     * index). Expiry is time-dependent and is checked by the OTP service.
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('consumed_at')->whereNull('superseded_at')->whereNull('locked_at');
    }

    /**
     * Supersedes every open challenge bound to a mobile trust: they become
     * unusable immediately. The one place this rule lives — used when a
     * trust is revoked or becomes stale. Returns the rows superseded.
     */
    public static function supersedeOpenForTrust(int $mobileTrustId): int
    {
        return static::query()->open()->where('mobile_trust_id', $mobileTrustId)
            ->update(['superseded_at' => now(), 'updated_at' => now()]);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mobileTrust(): BelongsTo
    {
        return $this->belongsTo(PersonMobileTrust::class, 'mobile_trust_id');
    }
}
