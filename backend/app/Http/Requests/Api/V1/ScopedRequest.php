<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Support\Reporting\OrganizationalScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Organizational scope shared by the Operational Dashboard and Reports:
 * a Clan (required), optionally narrowed to one of its Branch Groups
 * and/or Branches. A Branch Group or Branch of another Clan, or a Branch
 * outside the chosen group, is rejected (422).
 */
abstract class ScopedRequest extends FormRequest
{
    private ?OrganizationalScope $scope = null;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clan' => ['required', 'string', 'max:50'],
            'branch_group' => ['sometimes', 'nullable', 'string', 'max:50'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:50'],
            ...$this->filterRules(),
        ];
    }

    /** Additional, request-specific filters. @return array<string, mixed> */
    protected function filterRules(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['clan.required' => 'العشيرة / العائلة مطلوبة.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $clan = Clan::where('code', $this->input('clan'))->first();
            if ($clan === null) {
                $validator->errors()->add('clan', 'العشيرة / العائلة غير موجودة.');

                return;
            }

            $group = null;
            if ($this->filled('branch_group')) {
                $group = BranchGroup::where('clan_id', $clan->id)->where('code', $this->input('branch_group'))->first();
                if ($group === null) {
                    $validator->errors()->add('branch_group', 'مجموعة الفروع لا تتبع العشيرة / العائلة المختارة.');

                    return;
                }
            }

            $branch = null;
            if ($this->filled('branch')) {
                $branch = Branch::where('clan_id', $clan->id)->where('code', $this->input('branch'))->first();
                if ($branch === null) {
                    $validator->errors()->add('branch', 'الفرع لا يتبع العشيرة / العائلة المختارة.');

                    return;
                }
                if ($group !== null && $branch->branch_group_id !== $group->id) {
                    $validator->errors()->add('branch', 'الفرع لا يتبع مجموعة الفروع المختارة.');

                    return;
                }
            }

            $this->scope = new OrganizationalScope($clan, $group ?? $branch?->group, $branch);
        }];
    }

    public function scope(): OrganizationalScope
    {
        return $this->scope;
    }
}
