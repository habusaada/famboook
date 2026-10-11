<?php

namespace App\Support\ContactUpdate;

use App\Enums\OtpPurpose;
use App\Models\AuthOtpChallenge;
use App\Models\ChangeRequest;
use App\Models\Person;
use App\Support\MobileMask;

/**
 * What the Family Portal may know about a CONTACT_UPDATE's codes (docs/11
 * FP-ADR-079): per code, the MASKED destination and its timers — never a
 * challenge reference, a code, a full number or a trust detail. The codes
 * are found by the request; the client never names a challenge.
 */
final class ContactUpdateVerification
{
    /** The request's latest challenge of a purpose that is still open. */
    public static function openChallenge(ChangeRequest $request, OtpPurpose $purpose): ?AuthOtpChallenge
    {
        return AuthOtpChallenge::query()->open()
            ->where('change_request_id', $request->getKey())
            ->where('purpose', $purpose->value)
            ->orderByDesc('id')
            ->first();
    }

    /** @return array<string, mixed> */
    public static function state(ChangeRequest $request): array
    {
        $person = $request->person_id === null ? null : Person::withTrashed()->find($request->person_id);
        $transfer = (bool) ($request->submitted_data['trust_transfer'] ?? false);

        return [
            'self' => (bool) ($request->submitted_data['self'] ?? false),
            // The head's own TRUSTED number moves with the change (R2); false
            // for a member, or for a head whose number was not trusted (R1).
            'trust_transfer' => $transfer,
            'new_mobile' => self::code(self::openChallenge($request, OtpPurpose::CONTACT_NEW_MOBILE), MobileMask::mask($request->submitted_data['mobile'] ?? null)),
            'old_mobile' => $transfer ? self::code(self::openChallenge($request, OtpPurpose::CONTACT_OLD_MOBILE), MobileMask::mask($person?->mobile)) : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function code(?AuthOtpChallenge $challenge, ?string $masked): ?array
    {
        if ($challenge === null) {
            return null;
        }
        $cooldown = $challenge->last_sent_at->getTimestamp() + (int) config('family_auth.otp.resend_cooldown_seconds') - now()->getTimestamp();

        return [
            'masked' => $masked,
            'verified' => $challenge->verified_at !== null,
            'expires_in_seconds' => max(0, $challenge->expires_at->getTimestamp() - now()->getTimestamp()),
            'resend_after_seconds' => max(0, $cooldown),
            'can_resend' => $challenge->verified_at === null && $challenge->send_count < (int) config('family_auth.otp.max_sends'),
        ];
    }
}
