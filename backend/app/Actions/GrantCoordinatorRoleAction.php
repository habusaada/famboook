<?php

namespace App\Actions;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Exceptions\CoordinatorException;
use App\Models\Person;
use App\Models\User;
use App\Support\AccountSide;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\Coordinators;
use App\Support\FamilyAuth\FamilyAccessResolver;
use Illuminate\Support\Facades\DB;

/**
 * Grants the COORDINATOR role to a Person's family-side account (docs/11 §8,
 * §30a). A Staff act: an active Staff-side holder of coordinator-scope.manage.
 *
 * The account must already be a valid Family Portal account of THIS Person
 * with a Family context right now — FAMILY_USER, an eligible household head
 * (V1). A Staff, mixed or role-less account is refused: COORDINATOR is never
 * added to a Staff account, and a Staff member who coordinates uses a
 * separate family-side account.
 *
 * The role alone opens nothing: Coordinator Space also needs an active scope
 * assignment, which this action never creates.
 */
class GrantCoordinatorRoleAction
{
    public function __construct(private readonly FamilyAccessResolver $resolver) {}

    public function handle(User $actor, Person $person): User
    {
        Coordinators::authorize($actor);

        return DB::transaction(function () use ($actor, $person) {
            $account = Coordinators::accountOf($person);
            if ($account === null) {
                throw new CoordinatorException(CoordinatorException::NO_FAMILY_ACCOUNT);
            }
            /** @var User $user */
            $user = User::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            $context = $this->resolver->familyContext($user);
            if (! $context->hasFamilyContext() || ! $context->person->is($person)) {
                throw new CoordinatorException(CoordinatorException::NOT_ELIGIBLE);
            }
            if ($user->hasRole(AccountSide::COORDINATOR)) {
                throw new CoordinatorException(CoordinatorException::ALREADY_COORDINATOR);
            }

            $user->assignRole(AccountSide::COORDINATOR);

            AuthSecurityLog::record(
                AuthSecurityEventType::COORDINATOR_ROLE_GRANTED,
                AuthSecurityEventOutcome::SUCCESS,
                person: $person, user: $user, actor: $actor, link: $context->link,
            );

            return $user;
        });
    }
}
