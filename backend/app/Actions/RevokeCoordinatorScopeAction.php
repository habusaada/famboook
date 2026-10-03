<?php

namespace App\Actions;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\CoordinatorRevokeReason;
use App\Exceptions\CoordinatorException;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\Coordinators;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Revokes ONE coordinator scope assignment (docs/11 §30a). A Staff act: an
 * active Staff-side holder of coordinator-scope.manage. The row stays as
 * history with who, when and why; nothing is deleted. The account loses that
 * scope on its next request.
 */
class RevokeCoordinatorScopeAction
{
    public function handle(User $actor, CoordinatorScopeAssignment $assignment, CoordinatorRevokeReason $reason): CoordinatorScopeAssignment
    {
        if ($reason === CoordinatorRevokeReason::ROLE_REMOVED) {
            throw new InvalidArgumentException('ROLE_REMOVED is set only by RevokeCoordinatorRoleAction.');
        }
        Coordinators::authorize($actor);

        return DB::transaction(function () use ($actor, $assignment, $reason) {
            /** @var CoordinatorScopeAssignment $locked */
            $locked = CoordinatorScopeAssignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at !== null) {
                throw new CoordinatorException(CoordinatorException::ALREADY_REVOKED);
            }

            return self::revoke($locked, $actor, $reason);
        });
    }

    /** @internal The transition itself, inside the caller's transaction. */
    public static function revoke(CoordinatorScopeAssignment $assignment, User $actor, CoordinatorRevokeReason $reason): CoordinatorScopeAssignment
    {
        $assignment->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $actor->getKey(),
            'revoke_reason' => $reason->value,
        ])->save();

        $link = UserPersonLink::query()->where('user_id', $assignment->user_id)->latest('id')->first();
        AuthSecurityLog::record(
            AuthSecurityEventType::COORDINATOR_SCOPE_REVOKED,
            AuthSecurityEventOutcome::SUCCESS,
            $reason,
            person: $link ? Person::withTrashed()->find($link->person_id) : null,
            user: $assignment->user,
            actor: $actor,
            metadata: ['scope_type' => $assignment->scope_type->value],
        );

        return $assignment;
    }
}
