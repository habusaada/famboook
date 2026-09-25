<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Clan;
use App\Models\Family;
use Illuminate\Validation\ValidationException;

/**
 * A Family's Clan (required) and Branch (optional) — docs/03 §7a.
 *
 * - A newly selected Clan or Branch must be active (a Branch also needs an
 *   active group and Clan). An unchanged, since-deactivated value stays.
 * - A Branch must belong to the Family's Clan (also enforced by the
 *   families (branch_id, clan_id) composite foreign key).
 * - Changing the Clan never keeps an incompatible Branch: the request must
 *   supply a Branch of the new Clan or clear it explicitly.
 * - Choosing a Branch never moves the Family to another Clan.
 *
 * Payload keys: clan_code, branch_code (nullable).
 */
class FamilyLineage
{
    /** @return array<string, mixed> */
    public static function rules(bool $partial): array
    {
        return [
            'clan_code' => [$partial ? 'sometimes' : 'required', 'string', 'max:50'],
            'branch_code' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'clan_code.required' => 'العشيرة / العائلة مطلوبة.',
        ];
    }

    /**
     * The public Clan/Branch representation of a Family (codes and names,
     * no internal ids). An inactive Branch/Clan still displays.
     *
     * @return array{clan: ?array<string, mixed>, branch: ?array<string, mixed>}
     */
    public static function present(Family $family): array
    {
        $family->loadMissing(['clan', 'branch.group.branches']);
        $branch = $family->branch;
        $group = $branch?->group;

        return [
            'clan' => $family->clan ? [
                'code' => $family->clan->code,
                'name' => $family->clan->name,
                'is_active' => $family->clan->is_active,
            ] : null,
            'branch' => $branch ? [
                'code' => $branch->code,
                'name' => $branch->name,
                'is_active' => $branch->is_active,
                'group' => [
                    'code' => $group->code,
                    // null for an unnamed administrative container.
                    'name' => $group->name,
                    'display_name' => $group->displayName(),
                ],
            ] : null,
        ];
    }

    /**
     * Applies clan_code / branch_code to the Family (not saved).
     *
     * @param  array<string, mixed>  $data
     */
    public static function apply(Family $family, array $data): void
    {
        $currentClanId = $family->getOriginal('clan_id');
        $currentBranchId = $family->getOriginal('branch_id');

        $clanId = $currentClanId;
        if (array_key_exists('clan_code', $data)) {
            $clan = Clan::where('code', $data['clan_code'])->first();
            if ($clan === null) {
                throw ValidationException::withMessages(['clan_code' => 'العشيرة / العائلة غير موجودة.']);
            }
            if (! $clan->is_active && $clan->id !== $currentClanId) {
                throw ValidationException::withMessages(['clan_code' => "العشيرة / العائلة «{$clan->name}» غير مفعّلة ولا يمكن اختيارها."]);
            }
            $clanId = $clan->id;
        }

        $branchId = $currentBranchId;
        if (array_key_exists('branch_code', $data)) {
            $branchId = null;
            if ($data['branch_code'] !== null) {
                // Looked up inside the (new or current) Clan only.
                $branch = Branch::where('clan_id', $clanId)->where('code', $data['branch_code'])->first();
                if ($branch === null) {
                    throw ValidationException::withMessages(['branch_code' => 'الفرع المحدد لا يتبع العشيرة / العائلة المختارة.']);
                }
                if ($branch->id !== $currentBranchId && ! $branch->isSelectable()) {
                    throw ValidationException::withMessages(['branch_code' => "الفرع «{$branch->name}» غير مفعّل ولا يمكن اختياره."]);
                }
                $branchId = $branch->id;
            }
        } elseif ($clanId !== $currentClanId && $currentBranchId !== null) {
            // The Clan changed but the request says nothing about the Branch.
            throw ValidationException::withMessages([
                'branch_code' => 'عند تغيير العشيرة / العائلة يجب اختيار فرع يتبعها أو ترك الفرع فارغًا.',
            ]);
        }

        $family->clan_id = $clanId;
        $family->branch_id = $branchId;
    }
}
