<?php

namespace App\Support\FamilyAuth;

use App\Enums\MobileTrustDenial;
use App\Models\PersonMobileTrust;

/**
 * What CurrentTrustedMobile decided. Read-only and built only by it: either
 * the TRUSTED row of the Person's current number together with the
 * normalized destination, or one internal denial and nothing else.
 *
 * The destination exists in memory only, to be handed to the SMS sender. It
 * is never stored, logged or put in a security event.
 */
final readonly class TrustedMobileResult
{
    private function __construct(
        public ?MobileTrustDenial $denial,
        public ?PersonMobileTrust $trust = null,
        #[\SensitiveParameter] public ?string $destination = null,
    ) {}

    /** @internal CurrentTrustedMobile only. */
    public static function trusted(PersonMobileTrust $trust, #[\SensitiveParameter] string $destination): self
    {
        return new self(null, $trust, $destination);
    }

    /** @internal CurrentTrustedMobile only. */
    public static function denied(MobileTrustDenial $denial): self
    {
        return new self($denial);
    }

    public function isTrusted(): bool
    {
        return $this->denial === null;
    }

    /**
     * The derived state shown to authorized Staff: NO_MOBILE, UNVERIFIED,
     * TRUSTED, STALE, REVOKED (or UNAVAILABLE without a fingerprint key).
     */
    public function state(): string
    {
        return match ($this->denial) {
            null => 'TRUSTED',
            MobileTrustDenial::NO_VALID_MOBILE => 'NO_MOBILE',
            MobileTrustDenial::FINGERPRINT_UNAVAILABLE => 'UNAVAILABLE',
            default => $this->denial->value,
        };
    }
}
