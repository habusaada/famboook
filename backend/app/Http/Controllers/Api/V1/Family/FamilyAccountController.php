<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Actions\ChangeFamilyPasswordAction;
use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\FamilyAuthError;
use App\Enums\LoginDenial;
use App\Exceptions\FamilyAuthException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Requests\Api\V1\Family\FamilyPasswordChangeRequest;
use App\Http\Resources\FamilyAccountResource;
use App\Support\FamilyAuth\AuthSecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «حسابي» (docs/11 §23a, PWA-3B.5): the signed-in household head's own
 * account facts. SELF only — the account, link and Person are the ones the
 * family.context boundary resolved; nothing in the request can choose
 * another. show() is a pure read: no write, no security event.
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

    /**
     * «تغيير كلمة المرور» (docs/11 FU-16, FP-ADR-078): the signed-in head's
     * own password. A request that cannot carry a session is refused BEFORE
     * any password is checked. The action ends every session of the account
     * inside its transaction; this browser then signs in afresh after the
     * commit (the reset pattern), so it stays signed in on a new session.
     */
    public function changePassword(FamilyPasswordChangeRequest $request, ChangeFamilyPasswordAction $change): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);
        if (! $request->hasSession()) {
            AuthSecurityLog::record(AuthSecurityEventType::PASSWORD_CHANGED, AuthSecurityEventOutcome::DENIED, LoginDenial::SESSION_REQUIRED,
                person: $context->person, user: $context->user, link: $context->link);

            throw new FamilyAuthException(FamilyAuthError::PASSWORD_CHANGE_FAILED);
        }

        $user = $change->handle($context, (string) $request->input('current_password'), (string) $request->input('password'));

        // A session refresh, not a sign-in: the previous sign-in shown stays the same.
        return FamilySessionController::signIn($request, $user, refresh: true)->header('Cache-Control', 'no-store, private');
    }
}
