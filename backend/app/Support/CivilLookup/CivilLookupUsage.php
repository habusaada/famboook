<?php

namespace App\Support\CivilLookup;

use App\Models\CivilLookupRolePolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Today's lookup usage for the administration screen (FB-CIVIL-02A, ADR §L-6):
 * per role and the users who used up their quota. Counts and account names
 * only; never what was looked up.
 */
final class CivilLookupUsage
{
    /** @return array<string, mixed> */
    public static function today(): array
    {
        $date = CivilLookupQuota::today();
        $rows = DB::table('civil_lookup_daily_usage')->where('usage_date', $date)->where('used', '>', 0)->get(['user_id', 'used']);
        $users = User::query()->whereIn('id', $rows->pluck('user_id'))->with('roles')->get()->keyBy('id');

        $byRole = [];
        $exhausted = [];
        foreach ($rows as $row) {
            $user = $users->get($row->user_id);
            if ($user === null) {
                continue;
            }
            $policy = CivilLookupAccess::policyFor($user);
            $role = $policy?->role ?? ($user->getRoleNames()->first() ?? 'NONE');
            $byRole[$role] ??= ['lookups' => 0, 'users' => 0, 'exhausted' => 0];
            $byRole[$role]['lookups'] += (int) $row->used;
            $byRole[$role]['users']++;
            if ($policy !== null && ! $policy->unlimited() && (int) $row->used >= $policy->dailyLimit) {
                $byRole[$role]['exhausted']++;
                $exhausted[] = ['user_id' => $user->id, 'name' => $user->name, 'role' => $role, 'used' => (int) $row->used, 'daily_limit' => $policy->dailyLimit];
            }
        }
        $policies = CivilLookupRolePolicy::query()->orderBy('id')->with('updatedBy:id,name')->get()->map(fn (CivilLookupRolePolicy $p) => [
            'role' => $p->role_name,
            'enabled' => $p->enabled,
            'daily_limit' => $p->daily_limit,
            'unlimited' => $p->daily_limit === null,
            'unlimited_allowed' => $p->unlimited_allowed,
            'updated_by' => $p->updatedBy?->name,
            'updated_at' => $p->updated_at?->toIso8601String(),
            'today' => $byRole[$p->role_name] ?? ['lookups' => 0, 'users' => 0, 'exhausted' => 0],
        ])->all();
        usort($exhausted, fn ($a, $b) => [$a['role'], $a['name']] <=> [$b['role'], $b['name']]);

        return [
            'date' => $date,
            'resets_at' => CivilLookupQuota::resetsAt(),
            'total_lookups' => array_sum(array_column($byRole, 'lookups')),
            'active_users' => count($rows),
            'policies' => $policies,
            'exhausted_users' => $exhausted,
        ];
    }
}
