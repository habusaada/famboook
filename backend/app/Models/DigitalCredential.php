<?php

namespace App\Models;

use App\Enums\CredentialRevokeReason;
use App\Enums\CredentialStatus;
use App\Enums\CredentialSubjectType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A digital credential (docs/11 FP-ADR-070, docs/02). PWA-8: the Digital
 * Family Card — subject FAMILY. Three distinct things: the Family's
 * family_code, this card's public credential_number and the opaque QR token.
 *
 * The token itself is never stored: token_hash (SHA-256) is the lookup key
 * and token_encrypted (Laravel Crypt) exists only to re-show the owner's QR
 * through CredentialTokens::reveal(). Both are hidden from every
 * serialization and there is deliberately no `encrypted` cast.
 *
 * Append-only history: a row only ever moves ACTIVE → REVOKED (once), and is
 * never deleted; number, token and subject never change. Reissue is a revoke
 * (REISSUED) plus a NEW row.
 */
class DigitalCredential extends Model
{
    /** The only attributes a revocation may change. */
    private const REVOCATION_FIELDS = ['status', 'revoked_at', 'revoked_by', 'revoke_reason', 'updated_at'];

    protected $fillable = [
        'subject_type',
        'family_id',
        'credential_number',
        'token_hash',
        'token_encrypted',
        'token_version',
        'status',
        'issued_at',
        'issued_by',
    ];

    protected $hidden = [
        'token_hash',
        'token_encrypted',
    ];

    protected static function booted(): void
    {
        static::updating(function (DigitalCredential $credential) {
            $onlyRevocation = array_diff(array_keys($credential->getDirty()), self::REVOCATION_FIELDS) === [];
            if ($credential->getRawOriginal('status') !== CredentialStatus::ACTIVE->value || ! $onlyRevocation) {
                throw new LogicException('Digital credentials are immutable; only a single revocation may be recorded.');
            }
        });

        static::deleting(function () {
            throw new LogicException('Digital credentials are never deleted; revoke them instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'subject_type' => CredentialSubjectType::class,
            'status' => CredentialStatus::class,
            'revoke_reason' => CredentialRevokeReason::class,
            'token_version' => 'integer',
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isActive(): bool
    {
        return $this->status === CredentialStatus::ACTIVE;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', CredentialStatus::ACTIVE->value);
    }
}
