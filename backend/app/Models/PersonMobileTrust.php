<?php

namespace App\Models;

use App\Enums\MobileTrustStatus;
use App\Enums\MobileVerificationMethod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trust of one exact normalized mobile number for one Person (docs/02 §45b,
 * docs/03 §89b). The number is held as a keyed fingerprint (context MOBILE)
 * plus two digits for masked display; the fingerprint is hidden from every
 * serialization and is not unique — shared numbers are valid data and each
 * Person is verified individually. assisted_by is kept separately from
 * verified_by. Rows are history and are never deleted.
 *
 * Schema foundation only (PWA-1C). No row exists for an imported mobile:
 * NO_MOBILE and UNVERIFIED are derived, so presence is never trust.
 */
class PersonMobileTrust extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'person_id',
        'mobile_fingerprint',
        'mobile_last2',
        'key_version',
        'status',
        'verification_method',
        'assisted_by',
        'assisted_at',
        'verified_by',
        'verified_at',
        'stale_at',
        'revoked_by',
        'revoked_at',
        'revoke_reason',
    ];

    protected $hidden = [
        'mobile_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'key_version' => 'integer',
            'status' => MobileTrustStatus::class,
            'verification_method' => MobileVerificationMethod::class,
            'assisted_at' => 'datetime',
            'verified_at' => 'datetime',
            'stale_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** The auto-generated UUID is a public key only; `id` stays the PK. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assisted_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
