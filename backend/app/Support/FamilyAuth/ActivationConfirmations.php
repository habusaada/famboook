<?php

namespace App\Support\FamilyAuth;

use App\Support\MobileMask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The confirmation step of first self-activation (docs/11 §30a, FP-ADR-053):
 * between "here is your National ID" and "send the code", the user sees a
 * MASKED mobile — 05*****123, the last three digits only — and confirms it.
 *
 * A confirmation is CACHE STATE ONLY: a reference, the keyed LOGIN_ID
 * fingerprint, the masked number and the internal Person id — created only
 * for an identifier that may activate (FP-ADR-054: a refused identifier
 * gets no confirmation at all). Never a National ID, never a full number.
 * It does not trust anything and sends nothing: the OTP is issued by
 * FamilyOtpFlow::send, and only a correct code establishes trust.
 *
 * Single use: a confirmation is claimed atomically by exactly one send.
 */
final class ActivationConfirmations
{
    /** How long the user has to confirm the number. */
    public const TTL = 600;

    /** A confirmation for the eligible Person behind $loginKey. */
    public function create(string $loginKey, int $personId, string $maskedMobile): string
    {
        $uuid = (string) Str::uuid();
        Cache::put(self::key($uuid), [
            'login_key' => $loginKey,
            'person_id' => $personId,
            'masked_mobile' => $maskedMobile,
        ], self::TTL);

        return $uuid;
    }

    /**
     * The confirmation behind a reference, claimed by THIS call only — NULL
     * when unknown, expired or already used.
     *
     * @return array{login_key: string, person_id: int, masked_mobile: string}|null
     */
    public function claim(string $uuid): ?array
    {
        $state = Cache::get(self::key($uuid));
        if (! is_array($state) || ! Cache::add(self::key($uuid).'|claimed', true, self::TTL)) {
            return null;
        }
        Cache::forget(self::key($uuid));

        return $state;
    }

    /** The displayable mask of a real (normalized) number: 05*****NNN. */
    public static function mask(#[\SensitiveParameter] string $mobile): string
    {
        return MobileMask::normalized($mobile);
    }

    private static function key(string $uuid): string
    {
        return 'family-activation-confirmation|'.$uuid;
    }
}
