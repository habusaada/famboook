<?php

namespace App\Support\CivilLookup;

use App\Exceptions\CivilLookupException;
use App\Models\CivilLookupRolePolicy;
use App\Models\CivilLookupUserAccess;
use App\Models\User;
use App\Support\AccountSide;

/**
 * Who may look up, and under which policy (FB-CIVIL-02A, ADR §L-3, §L-6).
 * Every condition must hold: the lookup is open; the account side is allowed
 * (Staff; the family side only in 02B, with an explicit GRANTED row); the user
 * holds the permission; the user is not individually REVOKED; one of the
 * user's roles has an enabled policy. Several enabled policies (FAMILY_USER +
 * COORDINATOR, 02B) resolve deterministically to the most generous one; the
 * counter is per user either way.
 */
final class CivilLookupAccess
{
    public const SEARCH = 'civil-lookup.search';

    public const VIEW = 'civil-lookup.view';

    public const VIEW_FULL = 'civil-lookup.view-full';

    /**
     * @return array{allowed: bool, reason: ?string, policy: ?EffectivePolicy}
     */
    public static function evaluate(User $user, string $permission = self::SEARCH): array
    {
        $refuse = fn (string $reason) => ['allowed' => false, 'reason' => $reason, 'policy' => null];
        if (! CivilLookupGate::allows()) {
            return $refuse(CivilLookupException::DISABLED);
        }
        $side = AccountSide::of($user);
        $access = CivilLookupUserAccess::query()->find($user->id)?->access;
        $familySideOpen = config('civil_lookup.family_side_enabled') === true && $access === CivilLookupUserAccess::GRANTED;
        if (! ($side === AccountSide::STAFF || ($side === AccountSide::FAMILY && $familySideOpen))) {
            return $refuse(CivilLookupException::NOT_ALLOWED);
        }
        if (! $user->can($permission) || $access === CivilLookupUserAccess::REVOKED) {
            return $refuse(CivilLookupException::NOT_ALLOWED);
        }
        $policy = self::policyFor($user);

        return $policy === null ? $refuse(CivilLookupException::NOT_ALLOWED) : ['allowed' => true, 'reason' => null, 'policy' => $policy];
    }

    /** @throws CivilLookupException */
    public static function authorize(User $user, string $permission = self::SEARCH): EffectivePolicy
    {
        $result = self::evaluate($user, $permission);
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
