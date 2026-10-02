<?php

namespace App\Actions;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\MobileTrustRevokeReason;
use App\Enums\MobileTrustStatus;
use App\Exceptions\MobileTrustException;
use App\Models\AuthOtpChallenge;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\MobileTrusts;
use Illuminate\Support\Facades\DB;

/**
 * Revokes a TRUSTED mobile (docs/05 §53b): TRUSTED → REVOKED, with the
 * actor, the time and a reason code. Requires an active Staff-side actor
 * holding person-mobile-trust.revoke. The row stays as history, and every
 * open OTP challenge bound to it is superseded, so the trust is unusable
 * immediately.
 *
 * Mobile trust is the trust of an OTP destination — not the account, the
 * link or the session. Revoking it does NOT deactivate the User, end the
 * User-Person Link or revoke authenticated sessions; those have their own
 * lifecycle actions.
 */
class RevokePersonMobileTrustAction
{
    public function handle(User $actor, PersonMobileTrust $trust, MobileTrustRevokeReason $reason): PersonMobileTrust
    {
        MobileTrusts::authorize($actor, 'person-mobile-trust.revoke');

        return DB::transaction(function () use ($actor, $trust, $reason) {
            /** @var PersonMobileTrust $trust */
            $trust = PersonMobileTrust::query()->whereKey($trust->getKey())->lockForUpdate()->firstOrFail();
            if ($trust->status !== MobileTrustStatus::TRUSTED) {
                throw new MobileTrustException(MobileTrustException::NOT_TRUSTED);
            }

            $trust->forceFill([
                'status' => MobileTrustStatus::REVOKED,
                'revoked_by' => $actor->getKey(),
                'revoked_at' => now(),
                'revoke_reason' => $reason->value,
            ])->save();

            AuthOtpChallenge::supersedeOpenForTrust($trust->getKey());

            AuthSecurityLog::record(
                AuthSecurityEventType::MOBILE_TRUST_REVOKED,
                AuthSecurityEventOutcome::SUCCESS,
                $reason,
                person: $trust->person()->withTrashed()->first(), actor: $actor, trust: $trust,
                metadata: ['status_from' => MobileTrustStatus::TRUSTED->value, 'status_to' => MobileTrustStatus::REVOKED->value],
            );

            return $trust;
        });
    }
}
