<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Gender;
use App\Models\Person;
use App\Support\HealthRecordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Basic Person field edits only. Does not accept life_status,
 * death_date, is_household_head, or any membership/family field —
 * those are separate, controlled domain operations (docs/03-BUSINESS-
 * RULES.md §14-16, §30: household head and death are controlled
 * operations, not generic field edits).
 */
class UpdatePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->can('person.update')) {
            return false;
        }

        // docs/06-PERMISSIONS.md §39, §95: person.update alone does not
        // authorize changing the National ID.
        if ($this->has('national_id') && ! $user->can('person.national-id.update')) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'national_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'gender' => [
                'sometimes',
                Rule::enum(Gender::class),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $gender = Gender::tryFrom((string) $value);
                    if ($gender === null) {
                        return;
                    }

                    try {
                        HealthRecordRules::assertGenderChangeAllowed($this->route('person'), $gender);
                    } catch (ValidationException $e) {
                        $fail($e->errors()['gender'][0]);
                    }
                },
            ],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:50'],
            'alternate_mobile' => ['sometimes', 'nullable', 'string', 'max:50'],
            // Descriptive only; meaningless without the alternate number it
            // describes (the Person model clears it if that number is removed).
            'alternate_mobile_owner_relation' => [
                'sometimes', 'nullable', 'string', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (filled($value) && blank($this->effectiveAlternateMobile())) {
                        $fail('لا يمكن تحديد صاحب الرقم البديل دون إدخال رقم جوال بديل.');
                    }
                },
            ],
        ];
    }

    /** The alternate mobile after this update: the one sent, or the stored one. */
    private function effectiveAlternateMobile(): ?string
    {
        if ($this->has('alternate_mobile')) {
            return $this->input('alternate_mobile');
        }

        /** @var Person $person */
        $person = $this->route('person');

        return $person->alternate_mobile;
    }
}
