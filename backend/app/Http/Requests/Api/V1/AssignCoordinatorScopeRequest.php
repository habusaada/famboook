<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CoordinatorScopeType;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A scope to assign (docs/06 §22b): the level and the target by its public
 * codes — the Clan code, plus the Branch Group or Branch code within that
 * Clan (codes are unique per Clan). Exactly the codes of the level, no other.
 */
class AssignCoordinatorScopeRequest extends FormRequest
{
    private Clan|BranchGroup|Branch|null $target = null;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = $this->input('scope_type');

        return [
            'scope_type' => ['required', 'string', Rule::enum(CoordinatorScopeType::class)],
            'clan' => ['required', 'string', 'max:50'],
            'branch_group' => $type === CoordinatorScopeType::BRANCH_GROUP->value ? ['required', 'string', 'max:50'] : ['prohibited'],
            'branch' => $type === CoordinatorScopeType::BRANCH->value ? ['required', 'string', 'max:50'] : ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'scope_type.required' => 'مستوى النطاق مطلوب.',
            'scope_type.enum' => 'مستوى النطاق غير صالح.',
            'clan.required' => 'رمز العشيرة مطلوب.',
            'branch_group.required' => 'رمز مجموعة الفروع مطلوب.',
            'branch.required' => 'رمز الفرع مطلوب.',
            'branch_group.prohibited' => 'لا يُرسل رمز مجموعة فروع لهذا المستوى.',
            'branch.prohibited' => 'لا يُرسل رمز فرع لهذا المستوى.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $clan = Clan::query()->where('code', $this->input('clan'))->first();
            if ($clan === null) {
                $validator->errors()->add('clan', 'العشيرة غير موجودة.');

                return;
            }
            $this->target = match (CoordinatorScopeType::from($this->input('scope_type'))) {
                CoordinatorScopeType::CLAN => $clan,
                CoordinatorScopeType::BRANCH_GROUP => BranchGroup::query()->where('clan_id', $clan->id)->where('code', $this->input('branch_group'))->first(),
                CoordinatorScopeType::BRANCH => Branch::query()->where('clan_id', $clan->id)->where('code', $this->input('branch'))->first(),
            };
            if ($this->target === null) {
                $field = $this->input('scope_type') === CoordinatorScopeType::BRANCH->value ? 'branch' : 'branch_group';
                $validator->errors()->add($field, $field === 'branch' ? 'الفرع غير موجود في هذه العشيرة.' : 'مجموعة الفروع غير موجودة في هذه العشيرة.');
            }
        }];
    }

    public function target(): Clan|BranchGroup|Branch
    {
        return $this->target;
    }
}
