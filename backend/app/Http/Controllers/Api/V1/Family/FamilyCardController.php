<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Actions\IssueFamilyCredentialAction;
use App\Enums\CredentialIssueChannel;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyContext;
use App\Support\Credentials\CredentialTokens;
use App\Support\Credentials\QrCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «بطاقة الأسرة الرقمية» for the signed-in household head (docs/11 FP-ADR-070,
 * PWA-8.2): POST /api/v1/family/card ENSURES the Family has an ACTIVE Digital
 * Family Card — lazily issuing one by a system process the first time — and
 * returns it. Idempotent; never a write on GET. The Family is the one the
 * family.context boundary resolved; nothing in the request chooses another.
 *
 * This is the ONLY PWA-8.2 path that decrypts the stored token, to rebuild
 * the owner's QR. When it cannot be decrypted the card is still returned,
 * without a QR (qr_available false).
 */
class FamilyCardController extends Controller
{
    public function ensure(Request $request, IssueFamilyCredentialAction $issue): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);
        $credential = $issue->handle($context->family, CredentialIssueChannel::FAMILY_PORTAL, null, returnExisting: true);
        $family = $context->family->loadMissing(['clan', 'branch']);

        $token = CredentialTokens::reveal($credential);
        $url = $token === null ? null : config('credentials.verify_base_url').$token;

        return response()->json(['data' => [
            'credential_number' => $credential->credential_number,
            'family_code' => $family->family_code,
            'issued_at' => $credential->issued_at->toDateString(),
            'clan' => $family->clan?->name,
            'branch' => $family->branch?->name,
            // The owner's own card: the current head's full registered name.
            'head_name' => $context->person->full_name,
            'verification_url' => $url,
            'qr' => $url === null ? null : QrCodes::svgDataUri($url),
            'qr_available' => $url !== null,
        ]])->header('Cache-Control', 'no-store, private');
    }
}
