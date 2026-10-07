<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Actions\IssueFamilyCredentialAction;
use App\Enums\CredentialIssueChannel;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyContext;
use App\Support\Credentials\FamilyCardView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «بطاقة الأسرة الرقمية» for the signed-in household head (docs/11 FP-ADR-070,
 * PWA-8.2): POST /api/v1/family/card ENSURES the Family has an ACTIVE Digital
 * Family Card — lazily issuing one by a system process the first time — and
 * returns it. Idempotent; never a write on GET. The Family is the one the
 * family.context boundary resolved; nothing in the request chooses another.
 *
 * The owner's QR is rebuilt through FamilyCardView (shared with the PDF,
 * PWA-8.3), which reveals the stored token via CredentialTokens::reveal().
 * When it cannot be decrypted the card is still returned here, without a QR
 * (qr_available false).
 */
class FamilyCardController extends Controller
{
    public function ensure(Request $request, IssueFamilyCredentialAction $issue): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);
        $credential = $issue->handle($context->family, CredentialIssueChannel::FAMILY_PORTAL, null, returnExisting: true);

        // The shared owner view (also behind the PDF); the PWA-8.2 contract.
        return response()->json(['data' => FamilyCardView::forOwner($context, $credential)->toArray()])
            ->header('Cache-Control', 'no-store, private');
    }
}
