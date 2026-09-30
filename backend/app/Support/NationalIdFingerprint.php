<?php

namespace App\Support;

use LogicException;

/**
 * Internal identity evidence for import Apply (docs/03 §96b): a keyed
 * HMAC-SHA256 of an EXACT stored National ID, keyed with the application key
 * (APP_KEY), so the small, enumerable ID domain cannot be brute-forced from
 * the value and nothing is reversible. Stable for one installation.
 *
 * The planner puts it on every Person REUSE effect; the row executor
 * recomputes it from the Person it is about to reuse and refuses when the
 * National ID changed after planning. Exact value (as the planner matches) —
 * a reformatted ID is a different identity here, never silently accepted.
 *
 * Execution evidence ONLY: never presented, logged, stored in provenance or
 * activity, or put in an exception message.
 */
final class NationalIdFingerprint
{
    private const CONTEXT = 'famboook.import-apply.person-identity.v1:';

    public static function of(?string $nationalId): ?string
    {
        $value = trim((string) $nationalId);
        if ($value === '') {
            return null;
        }

        return hash_hmac('sha256', self::CONTEXT.$value, self::key());
    }

    /** Constant-time comparison of a Person's current National ID with approved evidence. */
    public static function matches(?string $nationalId, ?string $expected): bool
    {
        $actual = self::of($nationalId);

        return $actual !== null && $expected !== null && hash_equals($expected, $actual);
    }

    private static function key(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }
        if ($key === '') {
            throw new LogicException('The application key is required for import identity evidence.');
        }

        return $key;
    }
}
