<?php

namespace App\Support;

/**
 * The single masking rule for National IDs shown to holders of
 * person.national-id.view-masked (docs/06 §35, AUTH-ADR-059).
 *
 * A fixed run of five asterisks followed by the last characters of the
 * stored value: at most four, and never more than half of it, so a short
 * value is never shown in full. The prefix length is fixed, so the mask
 * does not reveal how long the stored value is. Example: 123456789 →
 * *****6789. NULL or blank stays NULL (not recorded).
 *
 * Masking works on the stored value as entered; there is no normalization
 * (PDD-001 stays open).
 */
final class NationalIdMask
{
    public const PREFIX = '*****';

    public const MAX_VISIBLE = 4;

    public static function mask(?string $nationalId): ?string
    {
        $value = trim((string) $nationalId);
        if ($value === '') {
            return null;
        }

        $visible = min(self::MAX_VISIBLE, intdiv(mb_strlen($value), 2));

        return self::PREFIX.($visible > 0 ? mb_substr($value, -$visible) : '');
    }
}
