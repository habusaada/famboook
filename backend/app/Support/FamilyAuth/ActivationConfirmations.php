<?php

namespace App\Support\FamilyAuth;

use App\Enums\FingerprintContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The confirmation step of first self-activation (docs/11 §30a, FP-ADR-053):
 * between "here is your National ID" and "send the code", the user sees a
 * MASKED mobile — 05*****123, the last three digits only — and confirms it.
 *
 * A confirmation is CACHE STATE ONLY, real or decoy alike: a reference, the
 * keyed LOGIN_ID fingerprint, the masked number, and — for an eligible
 * identifier only — the internal Person id. Never a National ID, never a
 * full number. It does not trust anything and sends nothing: the OTP is
 * issued by FamilyOtpFlow::send, and only a correct code establishes trust.
 *
 * ANTI-ENUMERATION. Every well-formed identifier gets a confirmation with a
 * masked number. A denied one (unknown, ineligible, no valid mobile…) shows
 * a FAKE mask derived from its keyed fingerprint — stable for that
 * identifier, so repeating the start reveals nothing — and its "send" yields
 * a decoy challenge. Residual, accepted with the decision: someone who
 * already knows a Person's real number can compare its last three digits.
 *
 * Single use: a confirmation is claimed atomically by exactly one send.
 */
final class ActivationConfirmations
{
    /** How long the user has to confirm the number. */
    public const TTL = 600;

    /** A confirmation; $personId NULL for a denied identifier (a decoy). */
    public function create(string $loginKey, ?int $personId, string $maskedMobile): string
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
     * @return array{login_key: string, person_id: ?int, masked_mobile: string}|null
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

    /** The displayable mask of a real number: 05*****NNN. */
    public static function mask(#[\SensitiveParameter] string $mobile): string
    {
        return '05*****'.substr($mobile, -3);
    }

    /** The stable fake mask of a denied identifier. */
    public static function decoyMask(string $loginKey): string
    {
        $digest = KeyedFingerprint::of(FingerprintContext::DECOY_MASK, $loginKey);

        return '05*****'.str_pad((string) (hexdec(substr($digest, 0, 8)) % 1000), 3, '0', STR_PAD_LEFT);
    }

    private static function key(string $uuid): string
    {
        return 'family-activation-confirmation|'.$uuid;
    }
}
