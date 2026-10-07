<?php

namespace App\Support\ChangeRequests;

use App\Enums\FingerprintContext;
use App\Models\ChangeRequest;
use App\Support\FamilyAuth\KeyedFingerprint;

/**
 * The base fingerprint of a Change Request (PWA-5b, AE-7): a keyed HMAC of
 * exactly the canonical values the request would change, taken at
 * submission and recomputed from fresh values at approve and apply — a
 * mismatch means the registry changed underneath the request
 * (CHANGE_REQUEST_BASE_CHANGED), so a stale request never overwrites newer
 * data.
 *
 * The values are serialized canonically (maps sorted by key, recursively)
 * and fingerprinted with the existing Family Auth key under the dedicated
 * CHANGE_REQUEST_BASE context: no new secret, nothing readable stored. A
 * fingerprint is never returned, logged or put into an event.
 */
final class ChangeRequestBase
{
    /**
     * @param  array<string, mixed>  $values
     * @return array{fingerprint: string, key_version: int}
     */
    public static function of(array $values): array
    {
        $version = KeyedFingerprint::currentVersion();

        return [
            'fingerprint' => KeyedFingerprint::of(FingerprintContext::CHANGE_REQUEST_BASE, self::canonical($values), $version),
            'key_version' => $version,
        ];
    }

    /**
     * Do the fresh values still match the stored base? A missing base, or one
     * made with a key that is no longer usable, never matches.
     *
     * @param  array<string, mixed>  $values
     */
    public static function matches(ChangeRequest $request, array $values): bool
    {
        $version = $request->base_key_version;
        if ($request->base_fingerprint === null || $version === null || ! in_array($version, KeyedFingerprint::versions(), true)) {
            return false;
        }

        return KeyedFingerprint::matches(FingerprintContext::CHANGE_REQUEST_BASE, self::canonical($values), $request->base_fingerprint, $version);
    }

    /** @param array<array-key, mixed> $values */
    public static function canonical(array $values): string
    {
        return json_encode(self::sorted($values), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function sorted(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values, SORT_STRING);
        }

        return array_map(fn ($v) => is_array($v) ? self::sorted($v) : $v, $values);
    }
}
