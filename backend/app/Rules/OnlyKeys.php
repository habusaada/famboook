<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;

/**
 * A strict allowlist for a whole input map: every top-level key that is not
 * allowed is reported under its own name — the input is refused, never
 * silently trimmed. Attach it to one always-validated field of the map; it
 * is implicit, so it runs even when that field is null or missing.
 */
class OnlyKeys implements ValidationRule, ValidatorAwareRule
{
    public bool $implicit = true;

    private Validator $validator;

    /** @param list<string> $allowed */
    public function __construct(private readonly array $allowed) {}

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (array_keys($this->validator->getData()) as $key) {
            if (! in_array((string) $key, $this->allowed, true)) {
                $this->validator->errors()->add((string) $key, 'هذا الحقل غير مسموح به في هذا الطلب.');
            }
        }
    }
}
