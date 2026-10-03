<?php

namespace App\Http\Requests\Api\V1\Family;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Search and filters of the Coordinator family list (docs/06 §22b). Every
 * value only NARROWS the already authorized query: a Clan, Group or Branch
 * outside the Coordinator's scope simply matches nothing.
 */
class CoordinatorFamilyIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'clan' => ['nullable', 'string', 'max:50'],
            'branch_group' => ['nullable', 'string', 'max:50'],
            'branch' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function search(): ?string
    {
        $q = trim((string) $this->input('q', ''));

        return $q === '' ? null : $q;
    }
}
