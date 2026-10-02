<?php

namespace App\Support\FamilyAuth;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\MobileTrustStatus;
use App\Models\AuthOtpChallenge;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Support\AccountSide;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Shared mobile trust transitions (docs/05 §53b). A trust row only ever
 * moves away from TRUSTED — to STALE or REVOKED — and is never moved back:
 * a renewed trust is always a NEW row, so the history stays true.
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

    /** Mobile trust is administered by an active Staff-side holder of $permission. */
    public static function authorize(User $actor, string $permission): void
    {
        if (! $actor->is_active || ! AccountSide::isStaff($actor) || ! $actor->can($permission)) {
            throw new AuthorizationException('لا تملك صلاحية إدارة توثيق الجوال.');
        }
    }
}
