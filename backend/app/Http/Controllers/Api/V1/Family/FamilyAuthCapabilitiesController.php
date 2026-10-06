<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Which Family authentication surfaces are open right now (docs/11 §30a,
 * FU-14, FP-ADR-065): the three global gates, as booleans, and nothing else.
 *
 * Public and outside every Family Auth gate, so the Family Portal can learn
 * — at runtime, not at build time — whether to offer activation, login or
 * password reset. No input, no account, no lookup: the values are exactly
 * what EnsureActivationEnabled, EnsureFamilyLoginEnabled and
 * EnsurePasswordResetEnabled read, so a surface reported closed answers 503
 * and one reported open is open. The answer is the same for every caller;
 * a closed surface was already observable through its 503.
 */
class FamilyAuthCapabilitiesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'activation' => config('family_auth.activation_enabled') === true,
            'login' => config('family_auth.login_enabled') === true,
            'password_reset' => config('family_auth.password_reset_enabled') === true,
        ]])->header('Cache-Control', 'no-store, private');
    }
}
