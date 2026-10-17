<?php

namespace App\Support\Geography;

use App\Enums\GazaLocationLevel;
use App\Enums\GazaLocationReviewIssue;
use App\Enums\GazaLocationReviewStatus;
use App\Models\GazaLocation;
use Illuminate\Database\Eloquent\Collection;

/**
 * The family-safe landmark type (FB-GEO-03, API-D4 as amended): the ONE rule
 * deciding whether a family-facing response may show a landmark's type.
 *
 * - Only a LANDMARK has a type; every other level gets null.
 * - The type NAME is shown exactly as stored (edge-trimmed for display, like
 *   names); the type code is never exposed, and nothing is guessed or
 *   corrected from the landmark's name.
 * - It is withheld while the landmark has an UNUSUAL_LANDMARK_TYPE review
 *   flag that is OPEN or CORRECTION_REQUIRED (Stage 1A W2: a type taken from
 *   a non-landmark category). CONFIRMED or DISMISSED no longer blocks it —
 *   which does NOT mean the type was independently verified.
 * - Review flags themselves (status, notes, issue) are never exposed.
 *
 * load() resolves the flags of a whole set of locations in ONE query; of()
 * reads the result (and, as a fail-safe, resolves a single location that was
 * not loaded, so a blocked type can never leak).
 */
final class FamilyLandmarkType
{
    /** Review statuses that keep a flagged type hidden from families. */
    public const BLOCKING_STATUSES = [GazaLocationReviewStatus::OPEN, GazaLocationReviewStatus::CORRECTION_REQUIRED];

    /** Internal, batch-loaded per location; hidden from serialization (GazaLocation::$hidden). */
    public const BLOCKED_ATTRIBUTE = 'family_landmark_type_blocked';

    /** @param  Collection<int, GazaLocation>|GazaLocation  $locations */
    public static function load(Collection|GazaLocation $locations): void
    {
        $locations = $locations instanceof GazaLocation ? new Collection([$locations]) : $locations;
        $pending = $locations->filter(fn (GazaLocation $location) => $location->level === GazaLocationLevel::LANDMARK
            && ! array_key_exists(self::BLOCKED_ATTRIBUTE, $location->getAttributes()));
        if ($pending->isEmpty()) {
            return;
        }

        (new Collection($pending->values()->all()))->loadExists([
            'reviewFlags as '.self::BLOCKED_ATTRIBUTE => fn ($flags) => $flags
                ->where('issue_code', GazaLocationReviewIssue::UNUSUAL_LANDMARK_TYPE->value)
                ->whereIn('review_status', array_map(fn (GazaLocationReviewStatus $s) => $s->value, self::BLOCKING_STATUSES)),
        ]);
    }

    /** The type name a family may see, or null (not a landmark, or withheld). */
    public static function of(GazaLocation $location): ?string
    {
        if ($location->level !== GazaLocationLevel::LANDMARK) {
            return null;
        }
        self::load($location);

        return $location->getAttribute(self::BLOCKED_ATTRIBUTE) ? null : trim((string) $location->landmark_type_name);
    }
}
