<?php

namespace App\Actions;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\FingerprintContext;
use App\Enums\LifeStatus;
use App\Enums\MobileTrustStatus;
use App\Enums\MobileVerificationMethod;
use App\Exceptions\MobileTrustException;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\FamilyMobile;
use App\Support\FamilyAuth\KeyedFingerprint;
use App\Support\FamilyAuth\MobileTrusts;
use Illuminate\Support\Facades\DB;

/**
 * Grants TRUSTED status to a Person's CURRENT registry mobile (docs/03
 * §89b, docs/05 §53b). Requires an active Staff-side actor holding
 * person-mobile-trust.grant — initially SUPER_ADMIN and ADMINISTRATOR only.
 *
 * It takes no mobile number: the action reads the Person's stored mobile
 * itself, so an arbitrary number can never be trusted. Refused when the
 * Person is deleted, inactive or not ALIVE, when the current mobile is
 * absent or not a valid canonical number, and — as a conflict — when the
 * current number is already TRUSTED (the verifier, method and time of an
 * existing trust are never rewritten).
 *
 * A trust is always a NEW row; history is never overwritten. A trust for an
 * older number becomes STALE first. Household-head status is NOT required:
 * mobile trust belongs to the Person, eligibility is the resolver's job.
 * A shared number is trusted for this Person only.
 */
class GrantPersonMobileTrustAction
{
    public function handle(User $actor, Person $person, MobileVerificationMethod $method): PersonMobileTrust
    {
        MobileTrusts::authorize($actor, 'person-mobile-trust.grant');

        return DB::transaction(function () use ($actor, $person, $method) {
            /** @var Person|null $person */
            $person = Person::withTrashed()->whereKey($person->getKey())->lockForUpdate()->first();
            if ($person === null || $person->trashed() || ! $person->is_active || $person->life_status !== LifeStatus::ALIVE) {
                throw new MobileTrustException(MobileTrustException::PERSON_NOT_ELIGIBLE);
            }

            $mobile = FamilyMobile::normalize($person->mobile);
            if ($mobile === null) {
                throw new MobileTrustException(MobileTrustException::NO_VALID_MOBILE);
            }

            $current = PersonMobileTrust::query()
                ->where('person_id', $person->getKey())
                ->where('status', MobileTrustStatus::TRUSTED->value)
                ->lockForUpdate()
                ->first();
            if ($current !== null) {
                if (KeyedFingerprint::matches(FingerprintContext::MOBILE, $mobile, $current->mobile_fingerprint, $current->key_version)) {
                    throw new MobileTrustException(MobileTrustException::ALREADY_TRUSTED);
                }
                // A trust for a number the Person no longer has.
                MobileTrusts::markStale($person, $actor);
            }

            $trust = PersonMobileTrust::create([
                'person_id' => $person->getKey(),
                'mobile_fingerprint' => KeyedFingerprint::of(FingerprintContext::MOBILE, $mobile),
                'mobile_last2' => substr($mobile, -2),
                'key_version' => KeyedFingerprint::currentVersion(),
                'status' => MobileTrustStatus::TRUSTED,
                'verification_method' => $method,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
            ]);

            AuthSecurityLog::record(
                AuthSecurityEventType::MOBILE_TRUST_GRANTED,
                AuthSecurityEventOutcome::SUCCESS,
                $method,
                person: $person, actor: $actor, trust: $trust,
                metadata: ['verification_method' => $method->value],
            );

            return $trust;
        });
    }
}
