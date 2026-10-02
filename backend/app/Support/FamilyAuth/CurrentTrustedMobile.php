<?php

namespace App\Support\FamilyAuth;

use App\Enums\FingerprintContext;
use App\Enums\MobileTrustDenial;
use App\Enums\MobileTrustStatus;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The one authority on "does this Person have a TRUSTED mobile that is
 * exactly their CURRENT registry mobile?" (docs/11 §30a, docs/03 §89b).
 * OTP issuing, activation and password reset consume this service; nothing
 * else decides it by querying PersonMobileTrust.
 *
 * Trust belongs to Person + one exact normalized number. It succeeds only
 * when the stored mobile normalizes (FamilyMobile), a TRUSTED row exists for
 * this Person, and that row's fingerprint — under its own key version — is
 * the fingerprint of the current number. Presence is never trust: an
 * imported mobile has no row and is UNVERIFIED. Everything else fails
 * closed, including a missing fingerprint key.
 *
 * A pure read: no lock, no cache, no audit event. Denial reasons are
 * internal.
 */
final class CurrentTrustedMobile
{
    public function for(Person $person): TrustedMobileResult
    {
        $mobile = FamilyMobile::normalize($person->mobile);
        if ($mobile === null) {
            return TrustedMobileResult::denied(MobileTrustDenial::NO_VALID_MOBILE);
        }

        // The most recent record decides the derived state. History is
        // never revived: a STALE or REVOKED row stays what it is, even when
        // the Person's number is changed back to it.
        $latest = PersonMobileTrust::query()
            ->where('person_id', $person->getKey())
            ->where('status', '!=', MobileTrustStatus::PENDING_VERIFICATION->value)
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return TrustedMobileResult::denied(MobileTrustDenial::UNVERIFIED);
        }
        if ($latest->status === MobileTrustStatus::REVOKED) {
            return TrustedMobileResult::denied(MobileTrustDenial::REVOKED);
        }
        if ($latest->status !== MobileTrustStatus::TRUSTED) {
            return TrustedMobileResult::denied(MobileTrustDenial::STALE);
        }

        try {
            $matches = KeyedFingerprint::matches(FingerprintContext::MOBILE, $mobile, $latest->mobile_fingerprint, $latest->key_version);
        } catch (LogicException) {
            // No usable key: fail closed. Logged without any value.
            Log::error('Mobile trust denied: the Family Auth fingerprint key is unavailable.');

            return TrustedMobileResult::denied(MobileTrustDenial::FINGERPRINT_UNAVAILABLE);
        }

        // A TRUSTED row for another number is a trust the Person has outgrown.
        return $matches
            ? TrustedMobileResult::trusted($latest, $mobile)
            : TrustedMobileResult::denied(MobileTrustDenial::STALE);
    }
}
