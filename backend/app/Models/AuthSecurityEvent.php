<?php

namespace App\Models;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * One append-only authentication / security event (docs/02 §45b, docs/11
 * §30a): never updated or deleted, retained 24 months.
 *
 * The record cannot carry a secret: there is no free-text column, an
 * identifier appears only as a keyed fingerprint, reasons are codes, and
 * metadata accepts only the allow-listed keys below with a code, a boolean
 * or a small counter as value — so a National ID, a mobile number, an OTP or
 * a password cannot be stored even by mistake.
 *
 * Schema foundation only (PWA-1C): nothing records events yet.
 */
class AuthSecurityEvent extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    /** Keys that may appear in metadata, and nothing else. */
    public const ALLOWED_METADATA = [
        'purpose',
        'verification_method',
        'scope_type',
        'key_version',
        'attempts',
        'send_count',
        'status_from',
        'status_to',
    ];

    /** Largest counter a metadata value may hold (far below any identifier). */
    public const MAX_METADATA_INT = 9999;

    protected $fillable = [
        'event_type',
        'outcome',
        'reason_code',
        'person_id',
        'user_id',
        'actor_user_id',
        'user_person_link_id',
        'mobile_trust_id',
        'otp_challenge_uuid',
        'login_key',
        'ip',
        'user_agent_hash',
        'metadata',
    ];

    protected $hidden = [
        'login_key',
    ];

    protected static function booted(): void
    {
        static::creating(function (AuthSecurityEvent $event) {
            self::assertSafeMetadata($event->metadata);
        });
        static::updating(function () {
            throw new LogicException('Security events are immutable.');
        });
        static::deleting(function () {
            throw new LogicException('Security events cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'event_type' => AuthSecurityEventType::class,
            'outcome' => AuthSecurityEventOutcome::class,
            'metadata' => 'array',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function userPersonLink(): BelongsTo
    {
        return $this->belongsTo(UserPersonLink::class);
    }

    public function mobileTrust(): BelongsTo
    {
        return $this->belongsTo(PersonMobileTrust::class, 'mobile_trust_id');
    }

    /** @param  array<string, mixed>|null  $metadata */
    public static function assertSafeMetadata(?array $metadata): void
    {
        foreach ($metadata ?? [] as $key => $value) {
            if (! in_array($key, self::ALLOWED_METADATA, true)) {
                throw new InvalidArgumentException("Security event metadata key not allowed: {$key}");
            }
            $safe = is_bool($value)
                || (is_int($value) && $value >= 0 && $value <= self::MAX_METADATA_INT)
                || (is_string($value) && preg_match('/\A[A-Z][A-Z_]{1,59}\z/', $value) === 1);
            if (! $safe) {
                throw new InvalidArgumentException("Security event metadata {$key} must be a code, a boolean or a small counter.");
            }
        }
    }
}
