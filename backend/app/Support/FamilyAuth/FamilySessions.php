<?php

namespace App\Support\FamilyAuth;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Models\User;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/**
 * Ends every session of a family-side account (docs/11 §30a): its rows in
 * the `sessions` table are deleted and its remember token is cleared, the
 * same mechanism the Staff password reset uses.
 *
 * Transactions: with the `database` session driver the rows live on the
 * application's connection, so a revocation made inside a Domain Action's
 * transaction commits or rolls back with the identity change — a failed
 * action never leaves sessions deleted. With any other driver there are no
 * rows to delete here; nothing is lost, because the access resolver denies
 * a suspended, ended or mismatched identity on the very next request.
 */
final class FamilySessions
{
    /** Returns how many session rows were deleted. */
    public static function revoke(User $user, User|int|null $actor = null, ?BackedEnum $reason = null): int
    {
        $deleted = 0;
        if (config('session.driver') === 'database') {
            $deleted = DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        }
        $user->forceFill(['remember_token' => null])->save();

        AuthSecurityLog::record(
            AuthSecurityEventType::SESSIONS_REVOKED,
            AuthSecurityEventOutcome::SUCCESS,
            $reason,
            user: $user,
            actor: $actor,
        );

        return $deleted;
    }
}
