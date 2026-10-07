<?php

namespace App\Support\Credentials;

use App\Models\DigitalCredential;
use RuntimeException;

/**
 * The public card number (docs/11 FP-ADR-070): FC-XXXX-XXXX-XX — ten random
 * characters of the Crockford base32 alphabet (no I, L, O, U), about 50
 * bits. Random, non-sequential and derived from nothing (no id, code or
 * National ID). It identifies the card, it is not a secret and it cannot be
 * turned into a token.
 *
 * Uniqueness is checked BEFORE the insert (a unique violation would abort a
 * PostgreSQL transaction); the unique index stays the final guard.
 */
final class CredentialNumbers
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const FORMAT = '/\AFC-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{2}\z/';

    private const ATTEMPTS = 5;

    public static function generate(): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $number = self::candidate();
            if (! DigitalCredential::query()->where('credential_number', $number)->exists()) {
                return $number;
            }
        }

        throw new RuntimeException('Could not generate a unique credential number.');
    }

    public static function candidate(): string
    {
        $chars = '';
        for ($i = 0; $i < 10; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return 'FC-'.substr($chars, 0, 4).'-'.substr($chars, 4, 4).'-'.substr($chars, 8, 2);
    }
}
