<?php

namespace App\Actions;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\PasswordChangeDenial;
use App\Models\User;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyAuth\FamilySessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in household head changes their own password (docs/11 FU-16,
 * FP-ADR-078). One transaction:
 *
 *   lock the User → the current password must match → the new password
 *   must differ from it → new password (hashed by the model's cast) → EVERY
 *   session of the User ended → PASSWORD_CHANGED
 *
 * The account is the one the family.context boundary resolved — never a
 * client-chosen one. A wrong current password or an unchanged password is a
 * field error for the user's own input, recorded as PASSWORD_CHANGED
 * FAILURE with its PasswordChangeDenial code; it never locks the account or
 * ends a session (the route limiter bounds attempts). The browser that
 * changed the password signs in afresh AFTER the commit (the caller, as in
 * the password reset), so its new session is never among those ended. Mobile
 * trust, the link and the identity are not touched; no SMS is sent.
 */
class ChangeFamilyPasswordAction
{
    public function handle(
        FamilyAccessResult $context,
        #[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword,
    ): User {
        $denial = null;
        $user = DB::transaction(function () use ($context, $currentPassword, $newPassword, &$denial) {
            /** @var User $user */
            $user = User::query()->whereKey($context->user->getKey())->lockForUpdate()->firstOrFail();

            if (! is_string($user->password) || ! Hash::check($currentPassword, $user->password)) {
                $denial = PasswordChangeDenial::WRONG_CURRENT_PASSWORD;

                return $user;
            }
            // The current password is verified, so comparing the two inputs
            // is comparing the new password with the stored one.
            if (hash_equals($currentPassword, $newPassword)) {
                $denial = PasswordChangeDenial::PASSWORD_UNCHANGED;

                return $user;
            }

            $user->forceFill(['password' => $newPassword])->save();

            // Every session that existed before this moment, and the remember token.
            FamilySessions::revoke($user, null, AuthSecurityEventType::PASSWORD_CHANGED);

            AuthSecurityLog::record(
                AuthSecurityEventType::PASSWORD_CHANGED,
                AuthSecurityEventOutcome::SUCCESS,
                person: $context->person, user: $user, link: $context->link,
            );

            return $user;
        });

        if ($denial !== null) {
            AuthSecurityLog::record(
                AuthSecurityEventType::PASSWORD_CHANGED,
                AuthSecurityEventOutcome::FAILURE,
                $denial,
                person: $context->person, user: $user, link: $context->link,
            );

            throw ValidationException::withMessages($denial === PasswordChangeDenial::WRONG_CURRENT_PASSWORD
                ? ['current_password' => 'كلمة المرور الحالية غير صحيحة.']
                : ['password' => 'كلمة المرور الجديدة مطابقة لكلمة المرور الحالية. اختر كلمة مرور مختلفة.']);
        }

        return $user;
    }
}
