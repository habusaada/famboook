<?php

namespace App\Support;

use App\Support\FamilyAuth\FamilyNationalId;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * The registry National ID rule (CO-01D.4, docs/03 "National ID uniqueness",
 * docs/04 DB-ADR-061): every NEW Person — newborns included — has exactly
 * nine ASCII digits, never a placeholder of nine identical digits, and the
 * value is globally unique across every Person, soft-deleted ones included.
 *
 * Input is normalized with the strict Family normalizer (FamilyNationalId:
 * Arabic-Indic / Persian digits to ASCII; spaces, invisible marks, dashes,
 * dots, slashes and underscores dropped) and STORED in that canonical form,
 * so an equivalently formatted value can never bypass uniqueness. Anything
 * else is refused, never guessed.
 *
 * PostgreSQL enforces the same rule as the final guard (NOT NULL,
 * chk_person_national_id_format, uq_persons_national_id); the application
 * checks first for a clear answer and maps a lost race to the duplicate
 * error without disclosing who holds the value.
 */
final class RegistryNationalId
{
    public const MESSAGE = 'رقم الهوية مطلوب ويجب أن يتكون من 9 أرقام.';

    /** The PostgreSQL unique constraint on persons.national_id. */
    public const UNIQUE_CONSTRAINT = 'uq_persons_national_id';

    /** The canonical nine digits, or NULL when the value is missing or invalid. */
    public static function canonical(#[\SensitiveParameter] mixed $value): ?string
    {
        $digits = FamilyNationalId::normalize($value);
        if ($digits === null || preg_match('/\A([0-9])\1{8}\z/', $digits) === 1) {
            return null;
        }

        return $digits;
    }

    /** The canonical value, or a validation error on $field (the value is never echoed). */
    public static function required(#[\SensitiveParameter] mixed $value, string $field): string
    {
        return self::canonical($value) ?? throw ValidationException::withMessages([$field => self::MESSAGE]);
    }

    /** A Laravel validation rule for request input. */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (self::canonical($value) === null) {
                $fail(self::MESSAGE);
            }
        };
    }

    /** Whether a unique violation is the National ID constraint (not e.g. person_code). */
    public static function isDuplicate(UniqueConstraintViolationException $e): bool
    {
        return str_contains($e->getMessage(), self::UNIQUE_CONSTRAINT);
    }
}
