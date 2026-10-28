<?php

namespace App\Support\CivilLookup;

use App\Exceptions\CivilLookupException;
use App\Models\CivilLookupRolePolicy;
use App\Models\CivilLookupUserAccess;
use App\Models\User;
use App\Support\AccountSide;

/**
 * Who may look up, from which context, under which policy (FB-CIVIL-02A,
 * ADR §L-3, §L-6; FB-CIVIL-02B, ADR 02B §B-3, §B-4). Every condition must
 * hold:
 *
 *   1. the gate of the context is open (CivilLookupGate::allowsContext);
 *   2. the account side matches the context — STAFF: a Staff-side account;
 *      FAMILY: a family-side account; COORDINATOR: a family-side account
 *      holding COORDINATOR (the household-head and assignment rules are the
 *      route boundaries family.context / coordinator.space, which run first);
 *   3. the user holds the permission (for a family-side role it exists only
 *      while that side's switch is open — RolePermissionSeeder);
 *   4. the user is not individually REVOKED, and a family-side context also
 *      needs an explicit GRANTED row naming that context;
 *   5. the role policy of the context is enabled — STAFF: any of the user's
 *      roles; FAMILY: FAMILY_USER; COORDINATOR: COORDINATOR. A Coordinator
 *      assignment alone grants nothing.
 *
 * The daily limit is the existing rule (§L-6): the most generous enabled
 * policy among ALL the user's roles, and one counter per user whatever the
 * context — switching between the Family Portal and Coordinator Space never
 * yields a second allowance.
 */
final class CivilLookupAccess
{
    public const SEARCH = 'civil-lookup.search';

    public const VIEW = 'civil-lookup.view';

    public const VIEW_FULL = 'civil-lookup.view-full';

    /** The role whose policy must be enabled for a family-side context. */
    private const CONTEXT_ROLES = [
        CivilLookupContext::FAMILY => AccountSide::FAMILY_USER,
        CivilLookupContext::COORDINATOR => AccountSide::COORDINATOR,
    ];

    /**
     * @return array{allowed: bool, reason: ?string, policy: ?EffectivePolicy}
     */
    public static function evaluate(User $user, string $context, string $permission = self::SEARCH): array
    {
        $refuse = fn (string $reason) => ['allowed' => false, 'reason' => $reason, 'policy' => null];
        if (! CivilLookupGate::allowsContext($context)) {
            return $refuse(CivilLookupException::DISABLED);
        }
        $side = AccountSide::of($user);
        $sideMatches = match ($context) {
            CivilLookupContext::STAFF => $side === AccountSide::STAFF,
            CivilLookupContext::FAMILY => $side === AccountSide::FAMILY,
            CivilLookupContext::COORDINATOR => $side === AccountSide::FAMILY && $user->hasRole(AccountSide::COORDINATOR),
            default => false,
        };
        if (! $sideMatches || ! $user->can($permission)) {
            return $refuse(CivilLookupException::NOT_ALLOWED);
        }
        $access = CivilLookupUserAccess::query()->find($user->id);
        if ($access?->access === CivilLookupUserAccess::REVOKED) {
            return $refuse(CivilLookupException::NOT_ALLOWED);
        }
        if (isset(self::CONTEXT_ROLES[$context])) {
            $contextPolicyEnabled = CivilLookupRolePolicy::query()
                ->where('role_name', self::CONTEXT_ROLES[$context])->where('enabled', true)->exists();
            if ($access === null || ! $access->grants($context) || ! $contextPolicyEnabled) {
                return $refuse(CivilLookupException::NOT_ALLOWED);
            }
        }
        $policy = self::policyFor($user);

        return $policy === null ? $refuse(CivilLookupException::NOT_ALLOWED) : ['allowed' => true, 'reason' => null, 'policy' => $policy];
    }

    /** @throws CivilLookupException */
    public static function authorize(User $user, string $context, string $permission = self::SEARCH): EffectivePolicy
    {
        $result = self::evaluate($user, $context, $permission);
        if (! $result['allowed']) {
            throw new CivilLookupException($result['reason']);
        }

        return $result['policy'];
    }

    /** The most generous enabled policy among the user's roles (unlimited > higher limit; ties by role name). */
    public static function policyFor(User $user): ?EffectivePolicy
    {
        $policies = CivilLookupRolePolicy::query()->whereIn('role_name', $user->getRoleNames()->all())->where('enabled', true)
            ->orderBy('role_name')->get();
        $best = null;
        foreach ($policies as $policy) {
            $limit = $policy->daily_limit === null && $policy->unlimited_allowed ? null : max(1, (int) $policy->daily_limit);
            if ($best === null || ($best->dailyLimit !== null && ($limit === null || $limit > $best->dailyLimit))) {
                $best = new EffectivePolicy($policy->role_name, $limit);
            }
        }

        return $best;
    }
}
