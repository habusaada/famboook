<?php

namespace App\Actions\CivilLookup;

use App\Models\CivilLookupPolicyChange;
use App\Models\CivilLookupUserAccess;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets the individual Civil Registry Lookup override of one user (FB-CIVIL-02A,
 * ADR §L-3): REVOKED blocks the user whatever their role; DEFAULT removes the
 * override (the role decides). GRANTED is reserved for the family side (02B)
 * and refused while that side is closed. Nothing else about the user — roles,
 * permissions, a Coordinator assignment — is touched. Recorded append-only.
 */
final class SetCivilLookupUserAccessAction
{
    public const DEFAULT = 'DEFAULT';

    public function handle(User $actor, User $target, string $access): ?CivilLookupUserAccess
    {
        if (! $actor->can(UpdateCivilLookupRolePolicyAction::MANAGE)) {
            throw new AuthorizationException;
        }
        $allowed = [CivilLookupUserAccess::REVOKED, self::DEFAULT];
        if (config('civil_lookup.family_side_enabled') === true) {
            $allowed[] = CivilLookupUserAccess::GRANTED;
        }
        if (! in_array($access, $allowed, true)) {
            throw ValidationException::withMessages(['access' => 'قيمة الوصول غير مسموح بها.']);
        }

        return DB::transaction(function () use ($actor, $target, $access) {
            $current = CivilLookupUserAccess::query()->whereKey($target->id)->lockForUpdate()->first();
            $old = $current?->access;
            $new = $access === self::DEFAULT ? null : $access;
            if ($old === $new) {
                return $current;
            }
            CivilLookupPolicyChange::create([
                'subject_type' => CivilLookupPolicyChange::USER, 'user_id' => $target->id,
                'old_access' => $old, 'new_access' => $new, 'changed_by' => $actor->id,
            ]);
            if ($new === null) {
                $current?->delete();

                return null;
            }

            return CivilLookupUserAccess::query()->updateOrCreate(['user_id' => $target->id], ['access' => $new, 'changed_by' => $actor->id, 'changed_at' => now()]);
        });
    }
}
