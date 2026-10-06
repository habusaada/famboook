<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Resources\FamilyAccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «حسابي» (docs/11 §23a, PWA-3B.5): the signed-in household head's own
 * account facts. SELF only — the account, link and Person are the ones the
 * family.context boundary resolved; nothing in the request can choose
 * another. A pure read: no write, no security event.
 */
class FamilyAccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        // Account and trust state: never stored by a shared or browser cache.
        return (new FamilyAccountResource(EnsureFamilyContext::context($request)))
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }
}
