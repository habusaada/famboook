<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Enums\LifeEvidence;
use App\Support\CivilLookup\CivilRecord;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The life status of one suggested child (FB-FAMILY-ADD-01 RR-02; D-06, G-05). FAILS
 * CLOSED at every step:
 *
 * 1. The registry's own death evidence first (ProofOfLife): a valid `deathdate` is
 *    DECEASED, an unreadable one is UNVERIFIED (ambiguous). Neither is ever overridden.
 * 2. An empty `deathdate` proves nothing. Only a bound RegistryLifeVerifier (an
 *    authoritative source, G-05) can then prove a life. NONE is bound: UNVERIFIED.
 * 3. Its answer counts only when it is ALIVE with a time that is not in the future
 *    (30 s of clock skew) and not older than `life_verification.max_age_hours`.
 *    DECEASED is DECEASED. UNKNOWN, no time, stale or future evidence, an invalid age
 *    limit, or any failure to read is UNVERIFIED.
 *
 * 4. RR-02A (D-06R, proposed; disabled by default): when no proof was found and the
 *    verifier did not fail or say DECEASED, a child of a release listed with its
 *    extraction date under the ACTIVE revised policy (RegistryLifePolicy) is
 *    DECLARATION_IN_SCOPE: admissible only with the head's declaration of current life,
 *    and NEVER treated as alive. Otherwise UNVERIFIED.
 *
 * It writes nothing. A failure is logged by exception class only, never with an ID.
 */
final class RegistryLifeStatus
{
    private const CLOCK_SKEW_SECONDS = 30;

    public static function of(CivilRecord $record): LifeStatus
    {
        $status = match (ProofOfLife::evaluate($record)) {
            LifeEvidence::DECEASED => LifeStatus::DECEASED,
            LifeEvidence::INDETERMINATE => LifeStatus::UNVERIFIED,
            LifeEvidence::UNVERIFIED => self::verified($record->mainid),
        };

        return $status === self::NOT_PROVEN && RegistryLifePolicy::extractedOn($record->releaseId) !== null
            ? LifeStatus::DECLARATION_IN_SCOPE
            : ($status === self::NOT_PROVEN ? LifeStatus::UNVERIFIED : $status);
    }

    /**
     * Internal: the verifier was not bound, or answered without proof (UNKNOWN, stale,
     * future, undated). Unlike a failure or a deceased answer, this is not contradictory
     * evidence, so the revised policy may still apply.
     */
    private const NOT_PROVEN = null;

    private static function verified(#[\SensitiveParameter] string $nationalId): ?LifeStatus
    {
        if (! app()->bound(RegistryLifeVerifier::class)) {
            return self::NOT_PROVEN;
        }
        try {
            $answer = app(RegistryLifeVerifier::class)->verify($nationalId);
        } catch (Throwable $e) {
            Log::warning('Registry life verification failed.', ['exception' => $e::class]);

            return LifeStatus::UNVERIFIED;
        }
        if (! $answer instanceof LifeVerification) {
            return LifeStatus::UNVERIFIED;
        }

        return match ($answer->status) {
            LifeVerificationStatus::DECEASED => LifeStatus::DECEASED,
            LifeVerificationStatus::ALIVE => self::fresh($answer->verifiedAt) ? LifeStatus::PROVEN_ALIVE : self::NOT_PROVEN,
            LifeVerificationStatus::UNKNOWN => self::NOT_PROVEN,
        };
    }

    private static function fresh(?DateTimeImmutable $at): bool
    {
        $hours = config('family_registry_admission.life_verification.max_age_hours');
        if ($at === null || ! is_int($hours) || $hours < 1) {
            return false;
        }
        $now = now()->getTimestamp();

        return $at->getTimestamp() <= $now + self::CLOCK_SKEW_SECONDS && $at->getTimestamp() >= $now - $hours * 3600;
    }
}
