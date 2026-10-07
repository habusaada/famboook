<?php

namespace App\Actions;

use App\Enums\CredentialRevokeReason;
use App\Enums\CredentialStatus;
use App\Enums\FamilyActivityType;
use App\Exceptions\CredentialException;
use App\Models\DigitalCredential;
use App\Models\Family;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Revokes the Family's ACTIVE Digital Family Card (docs/11 FP-ADR-070,
 * family-card.revoke) for a Staff reason — ADMINISTRATIVE or COMPROMISED;
 * REISSUED belongs to the reissue action. The row stays as history and its
 * QR no longer verifies. Allowed whatever the Family's status and while
 * issuance is switched off.
 */
class RevokeFamilyCredentialAction
{
    public function handle(Family $family, CredentialRevokeReason $reason, int $actorUserId): DigitalCredential
    {
        if (! in_array($reason, CredentialRevokeReason::staffReasons(), true)) {
            throw new InvalidArgumentException('REISSUED is set only by the reissue action.');
        }

        return DB::transaction(function () use ($family, $reason, $actorUserId) {
            Family::withTrashed()->whereKey($family->getKey())->lockForUpdate()->firstOrFail();
            $credential = self::lockActive($family);

            self::revoke($credential, $reason, $actorUserId);
            FamilyActivityLog::record($family->id, FamilyActivityType::FAMILY_CARD_REVOKED, $credential, $actorUserId, [
                'revoke_reason' => $reason->value,
            ]);

            return $credential;
        });
    }

    /** @internal shared with the reissue action, inside the caller's transaction */
    public static function lockActive(Family $family): DigitalCredential
    {
        $credential = DigitalCredential::query()
            ->where('family_id', $family->getKey())
            ->active()
            ->lockForUpdate()
            ->first();
        if ($credential === null) {
            throw new CredentialException(CredentialException::NO_ACTIVE_CARD);
        }

        return $credential;
    }

    /** @internal ACTIVE → REVOKED, inside the caller's transaction */
    public static function revoke(DigitalCredential $credential, CredentialRevokeReason $reason, int $actorUserId): void
    {
        $credential->forceFill([
            'status' => CredentialStatus::REVOKED,
            'revoked_at' => now(),
            'revoked_by' => $actorUserId,
            'revoke_reason' => $reason,
        ])->save();
    }
}
