<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DisplacementStatus;
use App\Models\Family;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial correction of the Family's CURRENT residence
 * (docs/03-BUSINESS-RULES.md §56 "Data Correction"). Only the fields sent
 * are changed, so one edit never touches fields it didn't send (e.g. an
 * address edit leaves a legacy NULL displacement_status alone).
 *
 * A real-world move is a separate operation (residence.change: end the
 * current residence, create a new one) and is not handled here.
 */
class UpdateFamilyResidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('residence.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'governorate' => ['sometimes', 'required', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'area' => ['sometimes', 'nullable', 'string', 'max:255'],
            'neighborhood' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_text' => ['sometimes', 'nullable', 'string'],

            'original_residence_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Nullable: NULL = not collected, distinct from NOT_DISPLACED.
            'displacement_status' => ['sometimes', 'nullable', Rule::enum(DisplacementStatus::class)],
            'displacement_location_text' => [
                'sometimes', 'nullable', 'string', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (blank($value)) {
                        return;
                    }

                    if ($this->effectiveDisplacementStatus() !== DisplacementStatus::DISPLACED) {
                        $fail('لا يمكن تحديد مكان النزوح إلا إذا كانت الأسرة نازحة حاليًا.');
                    }
                },
            ],
        ];
    }

    /**
     * The status the residence will have after this update: the one sent,
     * or the stored one when this request doesn't change it.
     */
    private function effectiveDisplacementStatus(): ?DisplacementStatus
    {
        if ($this->has('displacement_status')) {
            return DisplacementStatus::tryFrom((string) $this->input('displacement_status'));
        }

        /** @var Family $family */
        $family = $this->route('family');

        return $family->currentResidence?->displacement_status;
    }
}
