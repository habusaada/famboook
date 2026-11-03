<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Exceptions\RegistryAdmissionReferenceException as RefException;
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use JsonException;

/**
 * Opaque references to one suggested child, for a later admission request
 * (FB-FAMILY-ADD-01 ADD-WP-06B-2; ADR §7, §7a). INTERNAL: nothing issues or reads them
 * yet, and no route, action or job uses this class.
 *
 * - Laravel Crypt, as CivilLookupRefs and CredentialTokens: AES-256-CBC with a random
 *   IV and an HMAC-SHA256 over it (encrypt-then-MAC), keyed by APP_KEY. A token
 *   encrypted under a key in APP_PREVIOUS_KEYS still opens; one under any other key
 *   does not. The same input never gives the same token.
 * - The whole payload is encrypted: no ID, purpose or release travels in clear.
 * - Bound to the purpose, the user, the Family, the household head's Person and the
 *   published release; valid for TTL_SECONDS.
 * - Stateless: a reference can be presented again until it expires. The admission
 *   action must not trust it beyond identifying the child: it re-reads the registry
 *   and evaluates again under its locks, and idempotency is its own (ADR §10).
 * - Every refusal is REFERENCE_INVALID; only a valid reference of another release is
 *   RELEASE_CHANGED. Nothing says which check failed.
 * - openForReplay() (ADD-WP-07B-2a) opens an authentic, possibly expired reference, up to
 *   REPLAY_WINDOW_SECONDS after issue, as a RegistryAdmissionReplayClaim: it can only be
 *   compared with committed admission evidence, never admit.
 */
final class RegistryAdmissionRefs
{
    public const VERSION = 1;

    public const PURPOSE = 'ADMIT_REGISTRY_CHILD';

    public const TTL_SECONDS = 900;

    /** How long after issued-at an authentic reference may still identify a committed admission for a replay. */
    public const REPLAY_WINDOW_SECONDS = 86400;

    /** Tolerated clock difference between application servers, for issued-at only. */
    public const CLOCK_SKEW_SECONDS = 30;

    /** Longest token accepted; checked, with canonical base64, before any decryption. */
    public const MAX_LENGTH = 4096;

    public const PARENT_LINKS = ['FATHER', 'MOTHER'];

    /** Exactly these keys: version, purpose, user, family, head, release, national ID, link, issued, expires, nonce. */
    private const KEYS = ['f', 'h', 'i', 'j', 'l', 'n', 'p', 'r', 'u', 'v', 'x'];

    public static function issue(FamilyAccessResult $context, int $releaseId, #[\SensitiveParameter] string $childNationalId, string $parentLink): string
    {
        if (! $context->hasFamilyContext() || $releaseId < 1
            || ! CivilRegistryValues::validNationalId($childNationalId) || ! in_array($parentLink, self::PARENT_LINKS, true)) {
            throw new InvalidArgumentException('An admission reference needs a household context, a release, a valid National ID and FATHER or MOTHER.');
        }
        $now = now()->getTimestamp();

        return Crypt::encryptString(json_encode([
            'v' => self::VERSION,
            'p' => self::PURPOSE,
            'u' => (int) $context->user->getKey(),
            'f' => (int) $context->family->getKey(),
            'h' => (int) $context->person->getKey(),
            'r' => $releaseId,
            'n' => $childNationalId,
            'l' => $parentLink,
            'i' => $now,
            'x' => $now + self::TTL_SECONDS,
            'j' => bin2hex(random_bytes(16)),
        ], JSON_THROW_ON_ERROR));
    }

    /** @throws RefException REFERENCE_INVALID or RELEASE_CHANGED */
    public static function read(mixed $token, FamilyAccessResult $context, int $activeReleaseId): RegistryAdmissionReference
    {
        $payload = self::open($token);
        $now = now()->getTimestamp();

        if (! self::authentic($payload, $context, $now) || $payload['x'] <= $now) {
            throw new RefException(RefException::REFERENCE_INVALID);
        }
        if ($payload['r'] !== $activeReleaseId) {
            throw new RefException(RefException::RELEASE_CHANGED);
        }

        return new RegistryAdmissionReference($payload['r'], $payload['n'], $payload['l'], $payload['i'], $payload['x']);
    }

    /**
     * REPLAY ONLY (ADD-WP-07B-2a; ADD-WP-07B-2R D-B3): every check of read() except the
     * expiry, which is replaced by REPLAY_WINDOW_SECONDS from issued-at, and except the
     * release, which a replay does not compare (D-B2). The result can only be compared
     * with committed evidence; it never authorizes an admission, and read() keeps its
     * TTL_SECONDS lifetime unchanged.
     *
     * @throws RefException REFERENCE_INVALID
     */
    public static function openForReplay(mixed $token, FamilyAccessResult $context): RegistryAdmissionReplayClaim
    {
        $payload = self::open($token);
        $now = now()->getTimestamp();

        if (! self::authentic($payload, $context, $now) || $now - $payload['i'] >= self::REPLAY_WINDOW_SECONDS) {
            throw new RefException(RefException::REFERENCE_INVALID);
        }

        return RegistryAdmissionReplayClaim::of($payload['u'], $payload['f'], $payload['h'], $payload['n']);
    }

    /**
     * Ours, well formed, issued no later than the clock skew allows, with the fixed
     * lifetime, and bound to the current family.context. Expiry and release are the
     * callers' checks.
     */
    private static function authentic(mixed $payload, FamilyAccessResult $context, int $now): bool
    {
        return is_array($payload)
            && self::sortedKeys($payload) === self::KEYS
            && $payload['v'] === self::VERSION
            && $payload['p'] === self::PURPOSE
            && self::positiveInt($payload['u']) && self::positiveInt($payload['f']) && self::positiveInt($payload['h']) && self::positiveInt($payload['r'])
            && is_string($payload['n']) && CivilRegistryValues::validNationalId($payload['n'])
            && in_array($payload['l'], self::PARENT_LINKS, true)
            && is_int($payload['i']) && is_int($payload['x'])
            && $payload['i'] <= $now + self::CLOCK_SKEW_SECONDS
            && $payload['x'] - $payload['i'] === self::TTL_SECONDS
            && is_string($payload['j']) && preg_match('/\A[0-9a-f]{32}\z/', $payload['j']) === 1
            && $context->hasFamilyContext()
            && $payload['u'] === (int) $context->user->getKey()
            && $payload['f'] === (int) $context->family->getKey()
            && $payload['h'] === (int) $context->person->getKey();
    }

    /** The decrypted payload, or NULL for anything that is not one of our tokens. */
    private static function open(mixed $token): mixed
    {
        if (! is_string($token) || $token === '' || strlen($token) > self::MAX_LENGTH) {
            return null;
        }
        // Canonical base64 only: lenient decoding would accept variants of one token.
        $raw = base64_decode($token, true);
        if ($raw === false || base64_encode($raw) !== $token) {
            return null;
        }
        try {
            return json_decode(Crypt::decryptString($token), true, 2, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }
    }

    /** @param  array<mixed>  $payload  @return list<int|string> */
    private static function sortedKeys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);

        return $keys;
    }

    private static function positiveInt(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }
}
