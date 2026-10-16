<?php

namespace App\Rules;

use App\Models\GazaLocation;
use App\Support\Geography\GazaLocationSelection;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * A Gaza location reference on a residence (FB-GEO-01 Stage 3.2, Stage 3.1
 * audit §5.2). Use after `nullable`, `bail` and `uuid`, so a malformed value
 * never reaches the PostgreSQL uuid column:
 *
 *   ['sometimes', 'nullable', 'bail', 'uuid', new SelectableGazaLocation($stored)]
 *
 * - The location must exist.
 * - A NEW selection must be active; the currently stored value ($stored) is
 *   accepted unchanged even if it has since become inactive.
 * - Any level is accepted (no required depth in Stage 3).
 *
 * The residence Actions re-check inside their write transaction.
 */
class SelectableGazaLocation implements ValidationRule
{
    public function __construct(private readonly ?string $stored = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }
        if (! is_string($value) || ! Str::isUuid($value)) {
            $fail('معرّف الموقع الجغرافي غير صالح.');

            return;
        }

        $id = GazaLocationSelection::normalize($value);
        $location = GazaLocation::query()->whereKey($id)->first(['id', 'is_active']);
        if ($location === null) {
            $fail('الموقع الجغرافي المحدد غير موجود.');

            return;
        }
        if (! $location->is_active && $id !== GazaLocationSelection::normalize($this->stored)) {
            $fail(GazaLocationSelection::UNAVAILABLE);
        }
    }
}
