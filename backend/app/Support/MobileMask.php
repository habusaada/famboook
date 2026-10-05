<?php

namespace App\Support;

use App\Support\FamilyAuth\FamilyInput;
use App\Support\FamilyAuth\FamilyMobile;

/**
 * The single masking rule for mobile numbers shown in the Family Portal
 * (docs/11 §23a, FP-ADR-062): screen privacy, not withholding.
 *
 * A valid mobile (FamilyMobile: ten digits beginning 05) is shown as `05`,
 * five asterisks and its last three digits — 0591234567 → 05*****567, the
 * convention of the activation confirmation. A stored value that is not a
 * valid mobile is never given an invented `05` prefix: it becomes five
 * asterisks and at most its last three characters, never more than half of
 * it. NULL or blank stays NULL (not recorded).
 */
final class MobileMask
{
    public const PREFIX = '*****';

    public const MAX_VISIBLE = 3;

    /** The mask of a stored registry value, as entered. */
    public static function mask(#[\SensitiveParameter] ?string $mobile): ?string
    {
        $normalized = FamilyMobile::normalize($mobile);
        if ($normalized !== null) {
            return self::normalized($normalized);
        }

        // A stored value too long to clean is still a recorded value.
        $value = FamilyInput::clean($mobile) ?? trim((string) $mobile);
        if ($value === '') {
            return null;
        }
        $visible = min(self::MAX_VISIBLE, intdiv(mb_strlen($value), 2));

        return self::PREFIX.($visible > 0 ? mb_substr($value, -$visible) : '');
    }

    /** The mask of an already normalized mobile (FamilyMobile::normalize). */
    public static function normalized(#[\SensitiveParameter] string $mobile): string
    {
        return '05'.self::PREFIX.substr($mobile, -self::MAX_VISIBLE);
    }
}
