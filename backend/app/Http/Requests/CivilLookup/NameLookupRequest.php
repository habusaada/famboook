<?php

namespace App\Http\Requests\CivilLookup;

use App\Support\CivilLookup\ArabicNameNormalizer;
use Illuminate\Validation\Validator;

/** A full-name search: 2–6 normalized tokens, each at least 2 letters, one at least 3 (ADR §L-5). */
class NameLookupRequest extends CivilLookupRequest
{
    public const MESSAGE = 'أدخل كلمتين على الأقل من الاسم (حرفان على الأقل لكل كلمة، وثلاثة أحرف لإحداها)، وست كلمات كحد أقصى.';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['fullname' => ['required', 'string', 'max:'.ArabicNameNormalizer::MAX_LENGTH, 'regex:'.self::NAME_PATTERN]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['fullname.*' => self::MESSAGE];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->has('fullname') && ! ArabicNameNormalizer::acceptable($this->tokens(), 2)) {
                $validator->errors()->add('fullname', self::MESSAGE);
            }
        }];
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return ArabicNameNormalizer::tokens((string) $this->input('fullname'));
    }
}
