<?php

namespace App\Support\Credentials;

use App\Models\DigitalCredential;
use App\Support\FamilyAuth\FamilyAccessResult;
use Carbon\CarbonInterface;

/**
 * What the OWNER's Digital Family Card shows (docs/11 FP-ADR-070 / 071) —
 * one server-side assembly shared by the authenticated card JSON (POST
 * /api/v1/family/card) and its PDF (GET /api/v1/family/card/pdf), so the two
 * can never drift.
 *
 * Built from the family.context result and the Family's credential: the card
 * number, the family_code, the issue date, the Clan and Branch names, the
 * CURRENT household head's full registered name (the owner's own card) and
 * the verification URL. The URL carries the opaque token and exists only in
 * memory — revealed through CredentialTokens::reveal(), the one decryption
 * point — never stored, logged or printed as text; NULL when the stored
 * token cannot be decrypted.
 */
final readonly class FamilyCardView
{
    private function __construct(
        public string $credentialNumber,
        public string $familyCode,
        public CarbonInterface $issuedAt,
        public ?string $clan,
        public ?string $branch,
        public string $headName,
        #[\SensitiveParameter] public ?string $verificationUrl,
    ) {}

    public static function forOwner(FamilyAccessResult $context, DigitalCredential $credential): self
    {
        $family = $context->family->loadMissing(['clan', 'branch']);
        $token = CredentialTokens::reveal($credential);

        return new self(
            credentialNumber: $credential->credential_number,
            familyCode: $family->family_code,
            issuedAt: $credential->issued_at,
            clan: $family->clan?->name,
            branch: $family->branch?->name,
            headName: $context->person->full_name,
            verificationUrl: $token === null ? null : config('credentials.verify_base_url').$token,
        );
    }

    public function qrAvailable(): bool
    {
        return $this->verificationUrl !== null;
    }

    /** The calendar date in words — an Arabic month with Latin digits, as the Family Portal shows it. */
    public function issuedAtLabel(): string
    {
        return $this->issuedAt->copy()->locale('ar')->translatedFormat('j F Y');
    }

    /**
     * The PWA-8.2 card JSON contract, unchanged: the same keys, in the same
     * order, with the QR as an SVG data URI.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'credential_number' => $this->credentialNumber,
            'family_code' => $this->familyCode,
            'issued_at' => $this->issuedAt->toDateString(),
            'clan' => $this->clan,
            'branch' => $this->branch,
            'head_name' => $this->headName,
            'verification_url' => $this->verificationUrl,
            'qr' => $this->verificationUrl === null ? null : QrCodes::svgDataUri($this->verificationUrl),
            'qr_available' => $this->qrAvailable(),
        ];
    }
}
