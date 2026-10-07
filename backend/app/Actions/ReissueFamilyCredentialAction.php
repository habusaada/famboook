<?php

namespace App\Actions;

use App\Enums\CredentialRevokeReason;
use App\Enums\FamilyActivityType;
use App\Models\DigitalCredential;
use App\Models\Family;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Reissues the Digital Family Card (docs/11 FP-ADR-070, family-card.reissue):
 * the ACTIVE card is revoked with REISSUED and a NEW credential — new card
 * number, new QR token — is issued, in one transaction on the locked
 * families row. Requires an ACTIVE Family and an ACTIVE card, and is blocked
 * while issuance is switched off (it creates a credential). One activity
 * entry, about the new card.
 */
class ReissueFamilyCredentialAction
{
    public function handle(Family $family, int $actorUserId): DigitalCredential
    {
        return DB::transaction(function () use ($family, $actorUserId) {
            $family = IssueFamilyCredentialAction::lockActiveFamily($family);
            $current = RevokeFamilyCredentialAction::lockActive($family);
            IssueFamilyCredentialAction::assertIssuanceEnabled();

            RevokeFamilyCredentialAction::revoke($current, CredentialRevokeReason::REISSUED, $actorUserId);
            $credential = IssueFamilyCredentialAction::create($family, $actorUserId);
            FamilyActivityLog::record($family->id, FamilyActivityType::FAMILY_CARD_REISSUED, $credential, $actorUserId);

            return $credential;
        });
    }
}
