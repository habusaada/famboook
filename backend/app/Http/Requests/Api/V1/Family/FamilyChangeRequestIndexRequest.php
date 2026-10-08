<?php

namespace App\Http\Requests\Api\V1\Family;

use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the Family's Change Request history (PWA-5e): allow-listed keys
 * only. The Family is never a filter — it is the family.context Family.
 */
class FamilyChangeRequestIndexRequest extends FormRequest
{
    public const MAX_PER_PAGE = 50;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ChangeRequestStatus::class)],
            'type' => ['sometimes', Rule::enum(ChangeRequestType::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }
}
