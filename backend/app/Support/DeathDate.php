<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;

/**
 * The one definition of a valid death date (docs/03 §28-§30), shared by
 * RecordPersonDeathAction (an existing Person's death is recorded) and
 * CreatePersonAction (a Person is created already DECEASED): a real Y-m-d
 * date, not in the future and not before the birth date (also a Postgres
 * CHECK). NULL means "date unknown" — never invented.
 */
final class DeathDate
{
    public static function validate(?string $deathDate, ?string $birthDate, string $field = 'death_date'): void
    {
        $rules = ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'];
        if ($birthDate !== null) {
            $rules[] = 'after_or_equal:'.$birthDate;
        }

        Validator::make([$field => $deathDate], [$field => $rules], [
            "{$field}.date_format" => 'تاريخ الوفاة غير صالح.',
            "{$field}.before_or_equal" => 'تاريخ الوفاة لا يمكن أن يكون في المستقبل.',
            "{$field}.after_or_equal" => 'تاريخ الوفاة لا يمكن أن يسبق تاريخ الميلاد.',
        ])->validate();
    }
}
