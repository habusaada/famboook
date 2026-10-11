<?php

namespace App\Http\Requests\Api\V1\Family;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Complete a mobile CONTACT_UPDATE (docs/11 FP-ADR-079): the code sent to the
 * proposed number and — when the head's own trusted number moves (R2) — the
 * code sent to the current number. Codes are never flashed or logged; a
 * code with the wrong shape is simply wrong (the OTP service decides).
 */
class CompleteContactUpdateRequest extends FormRequest
{
    /** @var list<string> */
    protected $dontFlash = ['code', 'old_code'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:12'],
            'old_code' => ['sometimes', 'nullable', 'string', 'max:12'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => 'أدخل رمز التحقق المرسل إلى الرقم الجديد.',
            'code.max' => 'رمز التحقق غير صحيح.',
            'old_code.max' => 'رمز التحقق غير صحيح.',
        ];
    }
}
