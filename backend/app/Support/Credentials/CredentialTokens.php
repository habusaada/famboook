<?php

namespace App\Support\Credentials;

use App\Models\DigitalCredential;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * The opaque credential token (docs/11 FP-ADR-070): 32 random bytes,
 * base64url without padding (43 characters). It carries no subject, type or
 * id and is derived from nothing.
 *
 * - hash(): plain SHA-256, the only lookup key. A keyed HMAC protects
 *   LOW-entropy values (National IDs, mobiles, OTPs) against brute force from
 *   a database copy; a 256-bit random token cannot be guessed or reversed, so
 *   a key would add no security — only a key-loss / rotation failure mode
 *   that would break every printed QR. Deliberately independent of the
 *   Family Auth keys and of OTP / Sanctum / reset / member_ref machinery.
 * - seal() / reveal(): Laravel Crypt (APP_KEY, APP_PREVIOUS_KEYS on
 *   rotation), only to re-show the owner's QR. reveal() is the ONE place a
 *   stored token is decrypted.
 *
 * A token is never logged, flashed, put in activity or security metadata or
 * in an API URL.
 */
final class CredentialTokens
{
    public const CURRENT_VERSION = 1;

    private const FORMAT = '/\A[A-Za-z0-9_-]{43}\z/';

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(#[\SensitiveParameter] mixed $token): bool
    {
        return is_string($token) && preg_match(self::FORMAT, $token) === 1;
    }

    public static function seal(#[\SensitiveParameter] string $token): string
    {
        return Crypt::encryptString($token);
    }

    /** The stored token, or NULL when it cannot be decrypted (logged without any value). */
    public static function reveal(DigitalCredential $credential): ?string
    {
        try {
            $token = Crypt::decryptString($credential->token_encrypted);
        } catch (DecryptException) {
            Log::error('Digital credential token could not be decrypted.', ['credential_id' => $credential->getKey()]);

            return null;
        }

        return self::isWellFormed($token) ? $token : null;
    }
}
