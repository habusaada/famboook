<?php

namespace App\Support\FamilyAuth;

use App\Enums\FingerprintContext;
use InvalidArgumentException;
use LogicException;

/**
 * Keyed fingerprints of the Family Portal (docs/11 §30a, docs/04 §55b):
 * HMAC-SHA256 with the dedicated Family Auth secret (config/family_auth.php)
 * and a context prefix, as 64 lowercase hex characters.
 *
 * - Never APP_KEY, and never the import App\Support\NationalIdFingerprint
 *   (a different key, context and input contract).
 * - Fails closed: without a valid key every call throws. There is no
 *   fallback, so nothing can be fingerprinted with a weak or shared key.
 * - Versioned: stored fingerprints carry the key version that produced them;
 *   the previous key stays usable only while a rotation is in progress.
 *
 * Callers pass an already normalized value (FamilyNationalId, FamilyMobile).
 * Values and keys are never logged or put in an exception message.
 */
final class KeyedFingerprint
{
    public const MIN_KEY_BYTES = 32;

    public static function of(FingerprintContext $context, string $value, ?int $version = null): string
    {
        if ($value === '') {
            throw new InvalidArgumentException('A fingerprint needs a non-empty value.');
        }

        return hash_hmac('sha256', $context->value.$value, self::key($version ?? self::currentVersion()));
    }

    /** Constant-time comparison with a stored fingerprint. */
    public static function matches(FingerprintContext $context, string $value, ?string $expected, ?int $version = null): bool
    {
        return $expected !== null && hash_equals($expected, self::of($context, $value, $version));
    }

    public static function currentVersion(): int
    {
        $version = config('family_auth.fingerprint.key_version');
        if (! is_int($version) || $version < 1) {
            throw new LogicException('The Family Auth fingerprint key version is not valid.');
        }

        return $version;
    }

    /**
     * The usable key versions, current first.
     *
     * @return list<int>
     */
    public static function versions(): array
    {
        $versions = [self::currentVersion()];
        if (config('family_auth.fingerprint.previous_key') !== null) {
            $previous = (int) config('family_auth.fingerprint.previous_key_version');
            self::key($previous);
            $versions[] = $previous;
        }

        return $versions;
    }

    private static function key(int $version): string
    {
        $current = self::currentVersion();
        $previous = config('family_auth.fingerprint.previous_key_version');

        $configured = match (true) {
            $version === $current => config('family_auth.fingerprint.key'),
            is_int($previous) && $previous >= 1 && $previous !== $current && $version === $previous => config('family_auth.fingerprint.previous_key'),
            default => null,
        };

        $key = is_string($configured) ? $configured : '';
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }
        if (strlen($key) < self::MIN_KEY_BYTES) {
            throw new LogicException('The Family Auth fingerprint key is not configured for the requested version.');
        }

        return $key;
    }
}
