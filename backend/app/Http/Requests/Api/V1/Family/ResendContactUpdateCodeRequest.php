<?php

namespace App\Http\Requests\Api\V1\Family;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A new code for one destination of a CONTACT_UPDATE (FP-ADR-079): `new` or `old`. */
class ResendContactUpdateCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['target' => ['required', 'string', Rule::in(['new', 'old'])]];
    }
}
