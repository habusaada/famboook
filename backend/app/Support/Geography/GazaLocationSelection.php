<?php

namespace App\Support\Geography;

use App\Models\GazaLocation;
use Illuminate\Validation\ValidationException;

/**
 * Selecting a Gaza location for a residence (FB-GEO-01 Stage 3.2, Stage 3.1
 * audit D-7). Used by SelectableGazaLocation (field errors) and by the
 * residence Actions inside their write transaction (race-safe, and for any
 * caller that is not an HTTP request).
 *
 * - A NEW selection must exist and be active; any level is accepted.
 * - An UNCHANGED stored value is never re-checked, so a saved location that
 *   became inactive survives unrelated edits.
 * - UUIDs are compared and stored lower-case (PostgreSQL normalizes uuid
 *   values; SQLite compares strings).
 */
final class GazaLocationSelection
{
    public const UNAVAILABLE = 'الموقع الجغرافي المحدد غير متاح للاختيار.';

    public static function normalize(?string $id): ?string
    {
        return $id === null ? null : strtolower($id);
    }

    /**
     * Inside the caller's transaction: the location exists and is active,
     * read FOR SHARE on PostgreSQL so a concurrent deactivation waits until
     * this write commits (no-op on SQLite).
     *
     * @throws ValidationException
     */
    public static function assertSelectable(string $field, string $id): void
    {
        $active = GazaLocation::query()
            ->whereKey(self::normalize($id))
            ->where('is_active', true)
            ->sharedLock()
            ->exists();

        if (! $active) {
            throw ValidationException::withMessages([$field => self::UNAVAILABLE]);
        }
    }
}
