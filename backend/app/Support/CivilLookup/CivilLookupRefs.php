<?php

namespace App\Support\CivilLookup;

use App\Exceptions\CivilLookupException;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Opaque lookup references (FB-CIVIL-02A, ADR §L-7): authenticated encryption
 * (APP_KEY) of a small payload bound to the user, the release and an expiry.
 * They travel in POST bodies only — never in a URL — and carry the National
 * ID or the criteria only in encrypted form.
 *
 *   SEARCH  a list search (kind + normalized criteria): further pages are free
 *   RECORD  a record listed in an authorized result: opening it is free
 *   LINK    a linked citizen (relation + National ID): opening it is a new lookup
 *   LINKS   the reverse-link lists of a record (§L-8)
 *
 * FB-CIVIL-02B (ADR 02B §B-1): version 2 also binds the lookup context, so a
 * reference issued on one side (Staff, Family Portal, Coordinator Space) is
 * REFERENCE_INVALID on any other — even for the same user.
 */
final class CivilLookupRefs
{
    public const SEARCH = 'S';

    public const RECORD = 'R';

    public const LINK = 'L';

    public const LINKS = 'X';

    /** @param  array<string, mixed>  $data */
    public static function issue(string $type, User $user, string $context, int $releaseId, array $data): string
    {
        $ttl = (int) config('civil_lookup.reference_ttl_minutes', 30);

        return Crypt::encryptString((string) json_encode([
            'v' => 2, 't' => $type, 'u' => $user->id, 'c' => $context, 'r' => $releaseId, 'x' => now()->addMinutes($ttl)->getTimestamp(), 'd' => $data,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{release_id: int, data: array<string, mixed>}
     *
     * @throws CivilLookupException REFERENCE_INVALID
     */
    public static function read(mixed $token, string $type, User $user, string $context): array
    {
        $ref = self::readAny($token, [$type], $user, $context);

        return ['release_id' => $ref['release_id'], 'data' => $ref['data']];
    }

    /**
     * FB-CIVIL-02G: a reference of one of $types (a reveal accepts RECORD and LINK), with its type.
     *
     * @param  list<string>  $types
     * @return array{type: string, release_id: int, data: array<string, mixed>}
     *
     * @throws CivilLookupException REFERENCE_INVALID
     */
    public static function readAny(mixed $token, array $types, User $user, string $context): array
    {
        try {
            $payload = is_string($token) && $token !== '' && strlen($token) <= 4096 ? json_decode(Crypt::decryptString($token), true) : null;
        } catch (DecryptException) {
            $payload = null;
        }
        if (! is_array($payload) || ($payload['v'] ?? null) !== 2 || ! in_array($payload['t'] ?? null, $types, true) || ($payload['u'] ?? null) !== $user->id
            || ($payload['c'] ?? null) !== $context
            || ! is_int($payload['r'] ?? null) || ! is_int($payload['x'] ?? null) || $payload['x'] < now()->getTimestamp() || ! is_array($payload['d'] ?? null)) {
            throw new CivilLookupException(CivilLookupException::REFERENCE_INVALID);
        }

        return ['type' => $payload['t'], 'release_id' => $payload['r'], 'data' => $payload['d']];
    }
}
