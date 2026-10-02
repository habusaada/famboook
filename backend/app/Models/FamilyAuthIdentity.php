<?php

namespace App\Models;

use App\Enums\AuthIdentityStatus;
use App\Enums\AuthIdentitySupersedeReason;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The authentication identity of a family-side account (docs/02 §45b,
 * docs/04 §55b). login_key is a keyed fingerprint of the nine normalized
 * National ID digits (KeyedFingerprint, context LOGIN_ID) — never the raw
 * identifier — and is hidden from every serialization. Superseded rows are
 * history and are never deleted.
 *
 * Schema foundation only (PWA-1C): identities are created at activation.
 */
class FamilyAuthIdentity extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'login_key',
        'key_version',
        'status',
        'superseded_at',
        'supersede_reason',
    ];

    protected $hidden = [
        'login_key',
    ];

    protected function casts(): array
    {
        return [
            'key_version' => 'integer',
            'status' => AuthIdentityStatus::class,
            'superseded_at' => 'datetime',
            'supersede_reason' => AuthIdentitySupersedeReason::class,
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
}
