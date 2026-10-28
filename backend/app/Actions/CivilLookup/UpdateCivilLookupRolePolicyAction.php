<?php

namespace App\Actions\CivilLookup;

use App\Models\CivilLookupPolicyChange;
use App\Models\CivilLookupRolePolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes the Civil Registry Lookup policy of one role (FB-CIVIL-02A, ADR §L-6):
 * enabled and the daily limit. SUPER_ADMIN is always unlimited; every other
 * role has a limit of 1–100,000. The change and its previous values are
 * recorded (append-only) in the same transaction; an unchanged policy writes
 * nothing.
 */
final class UpdateCivilLookupRolePolicyAction
{
    public const MANAGE = 'civil-lookup.manage';

    public const MAX_LIMIT = 100000;

    public function handle(User $actor, string $roleName, bool $enabled, ?int $dailyLimit): CivilLookupRolePolicy
    {
        if (! $actor->can(self::MANAGE)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $roleName, $enabled, $dailyLimit) {
            $policy = CivilLookupRolePolicy::query()->where('role_name', $roleName)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(CivilLookupRolePolicy::class);
            if ($policy->unlimited_allowed && $dailyLimit !== null) {
                throw ValidationException::withMessages(['daily_limit' => 'هذا الدور غير محدود دائمًا.']);
            }
            if (! $policy->unlimited_allowed && ($dailyLimit === null || $dailyLimit < 1 || $dailyLimit > self::MAX_LIMIT)) {
                throw ValidationException::withMessages(['daily_limit' => 'الحد اليومي يجب أن يكون بين 1 و 100000.']);
            }
            if ($policy->enabled === $enabled && $policy->daily_limit === $dailyLimit) {
                return $policy;
            }
            CivilLookupPolicyChange::create([
                'subject_type' => CivilLookupPolicyChange::ROLE, 'role_name' => $policy->role_name,
                'old_enabled' => $policy->enabled, 'new_enabled' => $enabled,
                'old_daily_limit' => $policy->daily_limit, 'new_daily_limit' => $dailyLimit,
                'changed_by' => $actor->id,
            ]);
            $policy->update(['enabled' => $enabled, 'daily_limit' => $dailyLimit, 'updated_by' => $actor->id]);

            return $policy->refresh();
        });
    }
}
