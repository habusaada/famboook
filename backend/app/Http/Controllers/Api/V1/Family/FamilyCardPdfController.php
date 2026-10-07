<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Exceptions\CredentialException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyContext;
use App\Models\DigitalCredential;
use App\Support\Credentials\FamilyCardPdf;
use App\Support\Credentials\FamilyCardView;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The printable «بطاقة الأسرة الرقمية» (docs/11 FP-ADR-071, PWA-8.3):
 * GET /api/v1/family/card/pdf for the signed-in household head — the PDF of
 * the Family's EXISTING ACTIVE credential. The Family comes only from the
 * family.context boundary; no parameter is read.
 *
 * ZERO domain side effects: it never issues, reissues, revokes or rotates a
 * credential, and changes no Family data — POST /api/v1/family/card stays
 * the only (lazy) issuance path. No ACTIVE card → 404 CARD_NOT_ISSUED; a
 * token that cannot be decrypted → 409 CARD_QR_UNAVAILABLE (a card is never
 * printed without its QR). Generated in memory, never stored; no activity,
 * security event or log entry for an ordinary download.
 */
class FamilyCardPdfController extends Controller
{
    public function show(Request $request): Response
    {
        $context = EnsureFamilyContext::context($request);
        $credential = DigitalCredential::query()
            ->where('family_id', $context->family->getKey())
            ->active()
            ->first();
        if ($credential === null) {
            throw new CredentialException(CredentialException::CARD_NOT_ISSUED);
        }

        $card = FamilyCardView::forOwner($context, $credential);
        if (! $card->qrAvailable()) {
            throw new CredentialException(CredentialException::CARD_QR_UNAVAILABLE);
        }

        $pdf = FamilyCardPdf::render($card);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.FamilyCardPdf::filename($card).'"',
            'Content-Length' => (string) strlen($pdf),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
