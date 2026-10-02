<?php

namespace App\Support\FamilyAuth;

/**
 * The strict Family Portal National ID normalizer (docs/11 §30a, docs/03
 * §89b): exactly nine ASCII digits after FamilyInput::clean(), otherwise
 * invalid. No check-digit rule.
 *
 * This is the login INPUT contract only. It is separate from
 * App\Support\NationalId::normalize() (purpose-limited comparison, keeps
 * letters) and it never changes what is stored in persons.national_id.
 */
final class FamilyNationalId
{
    /** The nine digits, or null when the input is not a valid identifier. */
    public static function normalize(mixed $input): ?string
    {
        $value = FamilyInput::clean($input);

        return $value !== null && preg_match('/\A[0-9]{9}\z/', $value) === 1 ? $value : null;
    }
}
