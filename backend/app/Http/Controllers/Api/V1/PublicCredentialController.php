<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Credentials\CredentialResolver;
use App\Support\Credentials\FamilyCredentialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PUBLIC verification of a Digital Family Card (docs/11 §19, FP-ADR-070):
 * POST /api/v1/credentials/verify with {token} in the BODY — never in an API
 * URL. Called by the browser from /verify/{token}, so the rate limiter sees
 * the real client IP. No authentication, no write, no event.
 *
 * One generic failure for everything that does not verify (malformed,
 * missing, unknown, revoked, inactive / archived / deleted Family): the same
 * 404 body, status and headers. No FormRequest, so a malformed token cannot
 * produce a distinguishable 422. The token is never logged.
 */
class PublicCredentialController extends Controller
{
    public const MESSAGE = 'تعذّر التحقق من هذه البطاقة.';

    public const CODE = 'CREDENTIAL_NOT_VERIFIABLE';

    public function verify(Request $request): JsonResponse
    {
        $credential = CredentialResolver::resolveFamily($request->input('token'));
        if ($credential === null) {
            return self::notVerifiable();
        }

        return response()
            ->json(['data' => FamilyCredentialPresenter::present($credential, $credential->family)])
            ->header('Cache-Control', 'no-store, private');
    }

    public static function notVerifiable(): JsonResponse
    {
        return response()
            ->json(['message' => self::MESSAGE, 'code' => self::CODE], 404)
            ->header('Cache-Control', 'no-store, private');
    }
}
