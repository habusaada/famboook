<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ReceiptMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistance.deliver') ?? false;
    }

    public function rules(): array
    {
        return [
            'receipt_mode' => ['required', Rule::enum(ReceiptMode::class)],
            // Typed at the counter; compared, never stored or returned.
            'beneficiary_national_id' => ['required', 'string', 'max:50'],
            'delegate_national_id' => ['exclude_unless:receipt_mode,DELEGATE', 'required', 'string', 'max:50'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'receipt_mode.required' => 'اختر طريقة الاستلام.',
            'beneficiary_national_id.required' => 'رقم هوية المستفيد مطلوب.',
            'delegate_national_id.required' => 'رقم هوية المستلم بالنيابة مطلوب مع رقم هوية المستفيد.',
        ];
    }
}
