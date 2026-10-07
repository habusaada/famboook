<?php

namespace App\Actions;

use App\Enums\CredentialIssueChannel;
use App\Enums\CredentialStatus;
use App\Enums\CredentialSubjectType;
use App\Enums\FamilyActivityType;
use App\Enums\FamilyStatus;
use App\Exceptions\CredentialException;
use App\Models\DigitalCredential;
use App\Models\Family;
use App\Support\Credentials\CredentialNumbers;
use App\Support\Credentials\CredentialTokens;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Issues the Digital Family Card (docs/11 FP-ADR-070): a NEW credential for
 * an ACTIVE, not deleted Family — a fresh random card number and a fresh
 * opaque token (sealed; only its hash is a lookup key).
 *
 * Serialized on the families row (lockForUpdate): two concurrent first opens
 * of /family/card cannot both issue — the second sees the first card. The
 * partial unique index (one ACTIVE per Family) is the final guard.
 *
 * $returnExisting (Family Portal lazy issuance): an ACTIVE card is returned
 * instead of refused. Staff issuance refuses it (CARD_ALREADY_ACTIVE). The
 * issuance switch (credentials.family_card_issuance_enabled) blocks only a
 * NEW credential — an existing one is still returned.
 */
class IssueFamilyCredentialAction
{
    public function handle(Family $family, CredentialIssueChannel $channel, ?int $actorUserId, bool $returnExisting = false): DigitalCredential
    {
        return DB::transaction(function () use ($family, $channel, $actorUserId, $returnExisting) {
            $family = self::lockActiveFamily($family);

            $existing = DigitalCredential::query()
                ->where('family_id', $family->id)
                ->active()
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ($returnExisting) {
                    return $existing;
                }
                throw new CredentialException(CredentialException::CARD_ALREADY_ACTIVE);
            }
            self::assertIssuanceEnabled();

            $credential = self::create($family, $actorUserId);
            FamilyActivityLog::record($family->id, FamilyActivityType::FAMILY_CARD_ISSUED, $credential, $actorUserId, [
                'issue_channel' => $channel->value,
            ]);

            return $credential;
        });
    }

    /**
     * The families row, locked, if it is ACTIVE and not deleted.
     *
     * @internal shared with the reissue action
     */
    public static function lockActiveFamily(Family $family): Family
    {
        /** @var Family|null $locked */
        $locked = Family::withTrashed()->whereKey($family->getKey())->lockForUpdate()->first();
        if ($locked === null || $locked->trashed() || $locked->status !== FamilyStatus::ACTIVE) {
            throw new CredentialException(CredentialException::FAMILY_NOT_ACTIVE);
        }

        return $locked;
    }

    /** @internal shared with the reissue action */
    public static function assertIssuanceEnabled(): void
    {
        if (! filter_var(config('credentials.family_card_issuance_enabled'), FILTER_VALIDATE_BOOL)) {
            throw new CredentialException(CredentialException::ISSUANCE_DISABLED);
        }
    }

    /** @internal a NEW row (new number, new token), inside the caller's transaction */
    public static function create(Family $family, ?int $actorUserId): DigitalCredential
    {
        $token = CredentialTokens::generate();

        return DigitalCredential::create([
            'subject_type' => CredentialSubjectType::FAMILY,
            'family_id' => $family->id,
            'credential_number' => CredentialNumbers::generate(),
            'token_hash' => CredentialTokens::hash($token),
            'token_encrypted' => CredentialTokens::seal($token),
            'token_version' => CredentialTokens::CURRENT_VERSION,
            'status' => CredentialStatus::ACTIVE,
            'issued_at' => now(),
            'issued_by' => $actorUserId,
        ]);
    }
}
