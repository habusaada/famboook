<?php

namespace App\Http\Requests\CivilLookup;

use App\Exceptions\CivilLookupException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Base of the Civil Registry Lookup requests (FB-CIVIL-02A, ADR §L-6, §L-11).
 * Authorization is the route's `can:` plus the action's own checks. A request
 * that fails validation is never charged, but counts against a separate
 * per-user limiter; once that is exceeded the user is refused at the door
 * (RATE_LIMITED) before any validation. Validation messages never echo input.
 */
abstract class CivilLookupRequest extends FormRequest
{
    /** Letters (any script), combining marks, spaces, hyphen, apostrophe and dot. */
    public const NAME_PATTERN = "/^[\\p{L}\\p{M}\\s\\-'.]+$/u";

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (RateLimiter::tooManyAttempts($this->invalidKey(), (int) config('civil_lookup.rate_limits.invalid_per_hour'))) {
            throw new CivilLookupException(CivilLookupException::RATE_LIMITED);
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        RateLimiter::hit($this->invalidKey(), 3600);
        parent::failedValidation($validator);
    }

    private function invalidKey(): string
    {
        return 'civil-lookup-invalid|user|'.$this->user()?->getAuthIdentifier();
    }
}
