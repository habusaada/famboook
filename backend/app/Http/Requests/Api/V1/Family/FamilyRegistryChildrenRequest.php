<?php

namespace App\Http\Requests\Api\V1\Family;

use App\Support\CivilLookup\CivilRegistrySource;
use App\Support\FamilyPortal\RegistryAdmission\SuggestedChildrenDiscovery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * GET /api/v1/family/household/registry-children (FB-FAMILY-ADD-01 ADD-WP-06B-3):
 * `page` only. The household and its head come from family.context; any other
 * parameter (a National ID, a Family, a page size …), in the query or the body, is
 * refused, not ignored. The page size is fixed, so the page is bounded by the cap.
 */
class FamilyRegistryChildrenRequest extends FormRequest
{
    public const MAX_PAGE = CivilRegistrySource::LINKS_CAP / SuggestedChildrenDiscovery::PER_PAGE;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE]];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->query->all() + $this->request->all()), ['page']) !== []) {
                $validator->errors()->add('parameters', 'لا يُقبل هذا المعامل في هذا الطلب.');
            }
        }];
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
