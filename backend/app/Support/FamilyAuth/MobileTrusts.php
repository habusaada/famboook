<?php

namespace App\Support\FamilyAuth;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\FingerprintContext;
use App\Enums\MobileTrustStatus;
use App\Enums\MobileVerificationMethod;
use App\Models\AuthOtpChallenge;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Support\AccountSide;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Shared mobile trust transitions (docs/05 §53b). A trust row only ever
 * moves away from TRUSTED — to STALE or REVOKED — and is never moved back:
 * a renewed trust is always a NEW row, so the history stays true. The one
 * way INTO TRUSTED besides a Staff grant is a PENDING_VERIFICATION row
 * confirmed by a successful first-activation OTP (SELF_OTP).
 */
final class MobileTrusts
{
    /**
     * The Person's number changed: the TRUSTED row for the previous number
     * becomes STALE and every open OTP challenge bound to it is superseded.
     * Called from the Person model when the CANONICAL mobile changed, and by
     * a grant that finds a trust for an older number. Returns the rows made
     * stale.
     */
    public static function markStale(Person $person, User|int|null $actor = null): int
    {
        return DB::transaction(function () use ($person, $actor) {
            $trusted = PersonMobileTrust::query()
                ->where('person_id', $person->getKey())
                ->where('status', MobileTrustStatus::TRUSTED->value)
                ->lockForUpdate()
                ->get();

            foreach ($trusted as $trust) {
                $trust->forceFill(['status' => MobileTrustStatus::STALE, 'stale_at' => now()])->save();
                AuthOtpChallenge::supersedeOpenForTrust($trust->getKey());
                AuthSecurityLog::record(
                    AuthSecurityEventType::MOBILE_TRUST_STALE,
                    AuthSecurityEventOutcome::SUCCESS,
                    person: $person, actor: $actor, trust: $trust,
                    metadata: ['status_from' => MobileTrustStatus::TRUSTED->value, 'status_to' => MobileTrustStatus::STALE->value],
                );
            }

            return $trusted->count();
        });
    }

    /**
     * First self-activation (FP-ADR-053): the PENDING_VERIFICATION row an
     * activation OTP is bound to, for the Person's CURRENT mobile — reused
     * when it is for that number, otherwise the outgrown one becomes STALE
     * (its open challenges superseded) and a new one is created. Pending is
     * NOT trust: CurrentTrustedMobile ignores it, and only
     * confirmSelfVerified() turns it into TRUSTED.
     *
     * Inside the caller's transaction, with the Person row locked.
     *
     * @param  string  $mobile  the Person's current number, already through FamilyMobile::normalize()
     */
    public static function pendingFor(Person $person, #[\SensitiveParameter] string $mobile): PersonMobileTrust
    {
        self::assertInTransaction();
        $pending = PersonMobileTrust::query()
            ->where('person_id', $person->getKey())
            ->where('status', MobileTrustStatus::PENDING_VERIFICATION->value)
            ->lockForUpdate()
            ->first();
        if ($pending !== null) {
            if ($pending->key_version === KeyedFingerprint::currentVersion()
                && KeyedFingerprint::matches(FingerprintContext::MOBILE, $mobile, $pending->mobile_fingerprint, $pending->key_version)) {
                return $pending;
            }
            $pending->forceFill(['status' => MobileTrustStatus::STALE, 'stale_at' => now()])->save();
            AuthOtpChallenge::supersedeOpenForTrust($pending->getKey());
        }

        return PersonMobileTrust::create([
            'person_id' => $person->getKey(),
            'mobile_fingerprint' => KeyedFingerprint::of(FingerprintContext::MOBILE, $mobile),
            'mobile_last2' => substr($mobile, -2),
            'key_version' => KeyedFingerprint::currentVersion(),
            'status' => MobileTrustStatus::PENDING_VERIFICATION,
        ]);
    }

    /**
     * A correct activation OTP proved possession of the number the pending
     * row is for: it becomes the Person's TRUSTED mobile, verification
     * method SELF_OTP, no Staff verifier. The ONLY way a self-verified trust
     * is created — never by a confirmation click, never by a failed,
     * expired or superseded code (the caller verified the code first).
     *
     * A current TRUSTED row for the same number (a Staff grant made in the
     * meantime) is kept and returned — never duplicated or replaced — and the
     * pending row is retired; a TRUSTED row for an older number becomes STALE
     * first, as for a Staff grant. The partial unique index keeps one TRUSTED
     * row per Person whatever happens.
     *
     * Inside the caller's transaction, with the Person row locked. NULL when
     * the pending row is no longer pending or no longer the current number.
     */
    public static function confirmSelfVerified(Person $person, PersonMobileTrust $pending, #[\SensitiveParameter] string $mobile): ?PersonMobileTrust
    {
        self::assertInTransaction();
        $pending = PersonMobileTrust::query()->whereKey($pending->getKey())->lockForUpdate()->first();
        if ($pending === null
            || $pending->person_id !== $person->getKey()
            || $pending->status !== MobileTrustStatus::PENDING_VERIFICATION
            || ! KeyedFingerprint::matches(FingerprintContext::MOBILE, $mobile, $pending->mobile_fingerprint, $pending->key_version)) {
            return null;
        }

        $current = PersonMobileTrust::query()
            ->where('person_id', $person->getKey())
            ->where('status', MobileTrustStatus::TRUSTED->value)
            ->lockForUpdate()
            ->first();
        if ($current !== null && KeyedFingerprint::matches(FingerprintContext::MOBILE, $mobile, $current->mobile_fingerprint, $current->key_version)) {
            $pending->forceFill(['status' => MobileTrustStatus::STALE, 'stale_at' => now()])->save();

            return $current;
        }
        if ($current !== null) {
            self::markStale($person);
        }

        $pending->forceFill([
            'status' => MobileTrustStatus::TRUSTED,
            'verification_method' => MobileVerificationMethod::SELF_OTP,
            'verified_at' => now(),
        ])->save();
        AuthSecurityLog::record(
            AuthSecurityEventType::MOBILE_TRUST_GRANTED,
            AuthSecurityEventOutcome::SUCCESS,
            MobileVerificationMethod::SELF_OTP,
            person: $person, trust: $pending,
            metadata: ['verification_method' => MobileVerificationMethod::SELF_OTP->value],
        );

        return $pending;
    }

    private static function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A self-verified mobile trust changes only inside its caller\'s transaction.');
        }
    }

    /** Mobile trust is administered by an active Staff-side holder of $permission. */
    public static function authorize(User $actor, string $permission): void
    {
        if (! $actor->is_active || ! AccountSide::isStaff($actor) || ! $actor->can($permission)) {
            throw new AuthorizationException('لا تملك صلاحية إدارة توثيق الجوال.');
        }
    }
}
