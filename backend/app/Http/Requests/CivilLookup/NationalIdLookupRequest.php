<?php

namespace App\Http\Requests\CivilLookup;

use App\Support\RegistryNationalId;

/** An exact National ID lookup: the existing canonical 9-digit rule (Arabic-Indic digits accepted). */
class NationalIdLookupRequest extends CivilLookupRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['national_id' => ['required', 'string', 'max:32', RegistryNationalId::rule()]];
    }

    public function nationalId(): string
    {
        return (string) RegistryNationalId::canonical($this->input('national_id'));
    }
}
