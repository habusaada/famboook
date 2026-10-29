<?php

namespace App\Actions\CivilLookup;

use App\Exceptions\CivilLookupException;
use App\Models\CivilLookupPolicyChange;
use App\Models\CivilLookupUserAccess;
use App\Models\User;
use App\Support\AccountSide;
use App\Support\CivilLookup\CivilLookupGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets the individual Civil Registry Lookup override of one user (FB-CIVIL-02A,
 * ADR §L-3; FB-CIVIL-02B, ADR 02B §B-4):
 *
 *   REVOKED  blocks the user whatever their role, on every side
 *   DEFAULT  removes the override (Staff: the role decides; family side: closed)
 *   GRANTED  the explicit individual grant of the family-side contexts named
 *            in $sides (FAMILY, COORDINATOR). Only for a family-side account;
 *            COORDINATOR only for a holder of that role; each side only while
 *            its switch is open — so nothing can be GRANTED by default.
 *
 * A grant never overrides a gate, a permission, a role policy or the field
 * profile, and nothing else about the user — roles, permissions, a
 * Coordinator assignment — is touched. Recorded append-only.
 *
 * FB-CIVIL-02D: $expected is the state the editor loaded (access, sides).
 * When given, it is compared under the row lock and a different current
 * state refuses with ACCESS_CHANGED (409) — another administrator's change is
 * never silently overwritten. Without it the behaviour is unchanged.
 */
final class SetCivilLookupUserAccessAction
{
    public const DEFAULT = 'DEFAULT';

    /**
     * @param  list<string>  $sides  GRANTED only
     * @param  array{access: string, sides: list<string>}|null  $expected
     */
    public function handle(User $actor, User $target, string $access, array $sides = [], ?array $expected = null): ?CivilLookupUserAccess
    {
        if (! $actor->can(UpdateCivilLookupRolePolicyAction::MANAGE)) {
            throw new AuthorizationException;
        }
        if (! in_array($access, [CivilLookupUserAccess::REVOKED, CivilLookupUserAccess::GRANTED, self::DEFAULT], true)) {
            throw ValidationException::withMessages(['access' => 'قيمة الوصول غير مسموح بها.']);
        }
        $sideValue = $access === CivilLookupUserAccess::GRANTED ? $this->grantableSides($target, $sides) : null;
        if ($access !== CivilLookupUserAccess::GRANTED && $sides !== []) {
            throw ValidationException::withMessages(['sides' => 'تُحدَّد الجهات مع المنح فقط.']);
        }

        return DB::transaction(function () use ($actor, $target, $access, $sideValue, $expected) {
            $current = CivilLookupUserAccess::query()->whereKey($target->id)->lockForUpdate()->first();
            $old = $current?->access;
            $oldSides = $current?->sides;
            if ($expected !== null && (($old ?? self::DEFAULT) !== $expected['access']
                || $oldSides !== CivilLookupUserAccess::sideValue($expected['sides']))) {
                throw new CivilLookupException(CivilLookupException::ACCESS_CHANGED);
            }
            $new = $access === self::DEFAULT ? null : $access;
            if ($old === $new && $oldSides === $sideValue) {
                return $current;
            }
            CivilLookupPolicyChange::create([
                'subject_type' => CivilLookupPolicyChange::USER, 'user_id' => $target->id,
                'old_access' => $old, 'new_access' => $new, 'old_sides' => $oldSides, 'new_sides' => $sideValue,
                'changed_by' => $actor->id,
            ]);
            if ($new === null) {
                $current?->delete();

                return null;
            }

            return CivilLookupUserAccess::query()->updateOrCreate(['user_id' => $target->id],
                ['access' => $new, 'sides' => $sideValue, 'changed_by' => $actor->id, 'changed_at' => now()]);
        });
    }

    /**
     * The canonical sides value of a GRANTED row, or a validation error.
     *
     * @param  list<string>  $sides
     */
    private function grantableSides(User $target, array $sides): string
    {
        $sides = array_values(array_unique($sides));
        if ($sides === [] || array_diff($sides, CivilLookupUserAccess::SIDES) !== []) {
            throw ValidationException::withMessages(['sides' => 'حدد جهة المنح: بوابة الأسرة أو مساحة التنسيق.']);
        }
        if (! AccountSide::isFamily($target)) {
            throw ValidationException::withMessages(['access' => 'المنح الفردي متاح لحسابات الأسر فقط.']);
        }
        foreach ($sides as $side) {
            if (! CivilLookupGate::sideSwitchOpen($side)) {
                throw ValidationException::withMessages(['sides' => 'الاستعلام لهذه الجهة غير مفعّل، فلا يمكن المنح لها.']);
            }
            if ($side === AccountSide::COORDINATOR && ! $target->hasRole(AccountSide::COORDINATOR)) {
                throw ValidationException::withMessages(['sides' => 'هذا الحساب لا يحمل دور المنسّق.']);
            }
        }

        return (string) CivilLookupUserAccess::sideValue($sides);
    }
}
