<?php

namespace App\Http\Requests\Api\V1\Family;

use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionRefs;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionRequest;
use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/family/household/registry-children/admissions (FB-FAMILY-ADD-01
 * ADD-WP-07C; ADR §7 Admission): exactly four body fields, nothing else, nothing in the
 * query. The client never sends a Family, Person, membership, release or National ID:
 * the household comes from family.context and the child from the opaque reference.
 *
 * - `ref`: the opaque admission reference, a string of at most 4,096 characters
 *   (RegistryAdmissionRefs checks everything else and answers REFERENCE_INVALID).
 * - `client_reference`: a lowercase canonical UUID, the idempotency key.
 * - `declaration_version`: the version of the declaration shown (checked by the action).
 * - `declaration_accepted`: the JSON boolean true, nothing else ("true", 1, "on" … refuse).
 *
 * Validation messages name fields, never their values, and the 422 is no-store like every
 * other answer of this route.
 */
class FamilyRegistryAdmissionRequest extends FormRequest
{
    private const FIELDS = ['ref', 'client_reference', 'declaration_version', 'declaration_accepted'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ref' => ['required', 'string', 'max:'.RegistryAdmissionRefs::MAX_LENGTH],
            'client_reference' => ['required', 'string', 'regex:'.RegistryAdmissionRequest::CLIENT_REFERENCE_PATTERN],
            'declaration_version' => ['required', 'string', 'max:'.RegistryAdmissionRequest::DECLARATION_VERSION_MAX],
            'declaration_accepted' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== true) {
                    $fail('يلزم الإقرار بأن الابن أو الابنة من أفراد الأسرة.');
                }
            }],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->query->all() !== [] || array_diff(array_keys($this->request->all()), self::FIELDS) !== []) {
                $validator->errors()->add('parameters', 'لا يُقبل هذا المعامل في هذا الطلب.');
            }
        }];
    }

    /** The usual {message, errors} 422, but no-store: the default handler would allow caching. */
    protected function failedValidation(ValidatorContract $validator): void
    {
        throw new HttpResponseException(response()->json(['message' => 'البيانات المرسلة غير صالحة.', 'errors' => $validator->errors()], 422)
            ->header('Cache-Control', 'no-store, private'));
    }

    public function admission(): RegistryAdmissionRequest
    {
        $valid = $this->validated();

        return new RegistryAdmissionRequest($valid['ref'], $valid['client_reference'], $valid['declaration_version'], true, $this->ip());
    }
}
