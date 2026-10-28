<?php

namespace App\Http\Requests\CivilLookup;

use App\Support\CivilLookup\ArabicNameNormalizer;
use Illuminate\Validation\Validator;

/** A detailed search: 2–4 of name1 / father / grandfath / family, each at least 2 letters, one at least 3 (ADR §L-5). */
class PartsLookupRequest extends CivilLookupRequest
{
    public const FIELDS = ['name1', 'father', 'grandfath', 'family'];

    public const MESSAGE = 'أدخل حقلين على الأقل من الاسم الأول واسم الأب واسم الجد واسم العائلة (حرفان على الأقل لكل حقل، وثلاثة أحرف لأحدها).';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_fill_keys(self::FIELDS, ['nullable', 'string', 'max:50', 'regex:'.self::NAME_PATTERN]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_fill_keys(array_map(fn ($f) => $f.'.*', self::FIELDS), self::MESSAGE);
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && ! ArabicNameNormalizer::acceptable(array_values(array_filter($this->parts())), 2)) {
                $validator->errors()->add('parts', self::MESSAGE);
            }
        }];
    }

    /** @return array{name1: ?string, father: ?string, grandfath: ?string, family: ?string} normalized; NULL = not given */
    public function parts(): array
    {
        $parts = [];
        foreach (self::FIELDS as $field) {
            $value = ArabicNameNormalizer::normalize((string) $this->input($field, ''));
            $parts[$field] = $value === '' ? null : $value;
        }

        return $parts;
    }
}
