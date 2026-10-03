<?php

namespace App\Support\FamilyAuth;

use App\Enums\CoordinatorAccessDenial;
use App\Models\CoordinatorScopeAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The outcome of CoordinatorScopes::context(): either a denial and nothing
 * else, or the Coordinator and their effective assignments. Built only by
 * CoordinatorScopes — a client can never hand in a scope.
 *
 * It is NOT a Family context: the account's own household stays in
 * FamilyAccessResult, and nothing here grants anything in the Family context.
 */
final readonly class CoordinatorAccessResult
{
    /** @param  Collection<int, CoordinatorScopeAssignment>  $assignments */
    private function __construct(
        public ?CoordinatorAccessDenial $denial,
        public ?User $user = null,
        public Collection $assignments = new Collection,
    ) {}

    /** @internal CoordinatorScopes only. */
    public static function denied(CoordinatorAccessDenial $denial): self
    {
        return new self($denial);
    }

    /**
     * @internal CoordinatorScopes only.
     *
     * @param  Collection<int, CoordinatorScopeAssignment>  $assignments
     */
    public static function granted(User $user, Collection $assignments): self
    {
        return new self(null, $user, $assignments);
    }

    public function allowed(): bool
    {
        return $this->denial === null;
    }
}
