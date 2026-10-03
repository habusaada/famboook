<?php

namespace App\Actions;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\CoordinatorScopeType;
use App\Exceptions\CoordinatorException;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Person;
use App\Models\User;
use App\Support\AccountSide;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\Coordinators;
use App\Support\FamilyAuth\FamilyAccessResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Adds one organizational scope — a Clan, a Branch Group or a Branch — to a
 * Coordinator (docs/11 §8, §30a). A Staff act: an active Staff-side holder of
 * coordinator-scope.manage.
 *
 * Requires the COORDINATOR role ALREADY held (an assignment never grants the
 * role), a family-side account of THIS Person that still has its Family
 * context, and an ACTIVE target. The same active scope twice is refused (the
 * partial unique indexes are the backstop). Written in the existing typed
 * columns: clan_id always, plus branch_group_id or branch_id — never a
 * polymorphic reference.
 */
class AssignCoordinatorScopeAction
{
    public function __construct(private readonly FamilyAccessResolver $resolver) {}

    public function handle(User $actor, Person $person, Clan|BranchGroup|Branch $target): CoordinatorScopeAssignment
    {
        Coordinators::authorize($actor);

        return DB::transaction(function () use ($actor, $person, $target) {
            $account = Coordinators::accountOf($person);
            if ($account === null) {
                throw new CoordinatorException(CoordinatorException::NO_FAMILY_ACCOUNT);
            }
            /** @var User $user */
            $user = User::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            if (! $user->hasRole(AccountSide::COORDINATOR)) {
                throw new CoordinatorException(CoordinatorException::NOT_COORDINATOR);
            }
            $context = $this->resolver->familyContext($user);
            if (! $context->hasFamilyContext() || ! $context->person->is($person)) {
                throw new CoordinatorException(CoordinatorException::NOT_ELIGIBLE);
            }
            if (! self::active($target)) {
                throw new CoordinatorException(CoordinatorException::TARGET_INACTIVE);
            }

            $columns = match (true) {
                $target instanceof Clan => ['scope_type' => CoordinatorScopeType::CLAN, 'clan_id' => $target->id, 'branch_group_id' => null, 'branch_id' => null],
                $target instanceof BranchGroup => ['scope_type' => CoordinatorScopeType::BRANCH_GROUP, 'clan_id' => $target->clan_id, 'branch_group_id' => $target->id, 'branch_id' => null],
                $target instanceof Branch => ['scope_type' => CoordinatorScopeType::BRANCH, 'clan_id' => $target->clan_id, 'branch_group_id' => null, 'branch_id' => $target->id],
            };
            $exists = CoordinatorScopeAssignment::query()->active()->where('user_id', $user->getKey())
                ->where('scope_type', $columns['scope_type']->value)
                ->where('clan_id', $columns['clan_id'])
                ->where('branch_group_id', $columns['branch_group_id'])
                ->where('branch_id', $columns['branch_id'])
                ->exists();
            if ($exists) {
                throw new CoordinatorException(CoordinatorException::SCOPE_EXISTS);
            }

            try {
                // A savepoint: a concurrent duplicate trips the partial unique index.
                $assignment = DB::transaction(fn () => CoordinatorScopeAssignment::create([
                    'user_id' => $user->getKey(),
                    ...$columns,
                    'assigned_by' => $actor->getKey(),
                    'assigned_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new CoordinatorException(CoordinatorException::SCOPE_EXISTS);
            }

            AuthSecurityLog::record(
                AuthSecurityEventType::COORDINATOR_SCOPE_ASSIGNED,
                AuthSecurityEventOutcome::SUCCESS,
                person: $person, user: $user, actor: $actor, link: $context->link,
                metadata: ['scope_type' => $columns['scope_type']->value],
            );

            return $assignment;
        });
    }

    /** The target and everything above it is active. */
    private static function active(Clan|BranchGroup|Branch $target): bool
    {
        return match (true) {
            $target instanceof Clan => $target->is_active,
            $target instanceof BranchGroup => $target->is_active && $target->clan->is_active,
            $target instanceof Branch => $target->isSelectable(),
        };
    }
}
