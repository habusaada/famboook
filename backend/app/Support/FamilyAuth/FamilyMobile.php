<?php

namespace App\Support\FamilyAuth;

/**
 * The strict Family Portal mobile normalizer (docs/11 §30a): ten ASCII
 * digits beginning 05 after FamilyInput::clean(), otherwise invalid — which
 * means "no valid mobile" for trust and OTP purposes. It never changes what
 * is stored in persons.mobile, and a valid mobile is not a trusted one.
 */
final class FamilyMobile
{
    /** The ten digits, or null when the input is not a valid mobile. */
    public static function normalize(mixed $input): ?string
    {
        $value = FamilyInput::clean($input);

        return $value !== null && preg_match('/\A05[0-9]{8}\z/', $value) === 1 ? $value : null;
    }
}
