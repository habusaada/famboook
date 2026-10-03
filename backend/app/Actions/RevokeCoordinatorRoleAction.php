<?php

namespace App\Actions;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\CoordinatorRevokeReason;
use App\Exceptions\CoordinatorException;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Person;
use App\Models\User;
use App\Support\AccountSide;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\Coordinators;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Removes the COORDINATOR role from a Person's family-side account (docs/11
 * §30a). A Staff act: an active Staff-side holder of coordinator-scope.manage.
 *
 * In ONE transaction: every active scope assignment is revoked with
 * ROLE_REMOVED (each recorded), then the role is removed and recorded. The
 * account keeps FAMILY_USER and its own household; only Coordinator Space
 * goes, on its next request. Works whether or not the account is still
 * eligible — a role must always be removable.
 */
class RevokeCoordinatorRoleAction
{
    public function handle(User $actor, Person $person, CoordinatorRevokeReason $reason): User
    {
        if ($reason === CoordinatorRevokeReason::ROLE_REMOVED) {
            throw new InvalidArgumentException('ROLE_REMOVED is the reason given to the assignments, not to the role.');
        }
        Coordinators::authorize($actor);

        return DB::transaction(function () use ($actor, $person, $reason) {
            $account = Coordinators::accountOf($person);
            if ($account === null) {
                throw new CoordinatorException(CoordinatorException::NO_FAMILY_ACCOUNT);
            }
            /** @var User $user */
            $user = User::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            if (! $user->hasRole(AccountSide::COORDINATOR)) {
                throw new CoordinatorException(CoordinatorException::NOT_COORDINATOR);
            }

            $active = CoordinatorScopeAssignment::query()->active()->where('user_id', $user->getKey())->lockForUpdate()->get();
            foreach ($active as $assignment) {
                RevokeCoordinatorScopeAction::revoke($assignment, $actor, CoordinatorRevokeReason::ROLE_REMOVED);
            }

            $user->removeRole(AccountSide::COORDINATOR);

            AuthSecurityLog::record(
                AuthSecurityEventType::COORDINATOR_ROLE_REVOKED,
                AuthSecurityEventOutcome::SUCCESS,
                $reason,
                person: $person, user: $user, actor: $actor,
            );

            return $user;
        });
    }
}
