<?php

namespace App\Http\Requests\CivilLookup;

use App\Actions\CivilLookup\LookupCivilRegistryAction;
use Illuminate\Validation\Rule;

/** A request carrying an opaque lookup reference (ADR §L-7), with a page or a relation where the route needs one. */
class ReferenceRequest extends CivilLookupRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ref' => ['required', 'string', 'max:4096'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'relation' => ['sometimes', 'string', Rule::in(LookupCivilRegistryAction::REVERSE_LINKS)],
        ];
    }
}
