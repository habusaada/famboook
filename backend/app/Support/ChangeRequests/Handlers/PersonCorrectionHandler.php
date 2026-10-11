<?php

namespace App\Support\ChangeRequests\Handlers;

use App\Actions\UpdatePersonAction;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ProfileReviewSection;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Rules\OnlyKeys;
use App\Support\ChangeRequests\ChangeRequestHandler;
use App\Support\ChangeRequests\ChangeRequestPresentation;
use App\Support\ChangeRequests\ChangeRequestPresentationContext;
use App\Support\ChangeRequests\ChangeRequestTarget;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\HouseholdMemberReference;
use App\Support\HealthRecordRules;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * PERSON_CORRECTION (docs/11 §14, FP-ADR-059; docs/05 §72): the household
 * head asks to correct a household member's basic data — full name, birth
 * date, gender, marital status. Applied by the canonical UpdatePersonAction,
 * the same action and the same rules as the Staff edit (UpdatePersonRequest).
 *
 * - Never the National ID (CorrectNationalIdAction, its own audited flow),
 *   mobiles (CONTACT_UPDATE), life status / death (ConfirmPersonAliveAction,
 *   RecordPersonDeathAction) or membership data: any other key is refused.
 * - The member is named by member_ref and resolved inside the context Family
 *   among its ACTIVE memberships only (FP-ADR-063); an unknown or foreign
 *   reference is not found.
 * - Input: all four fields present (the form is prefilled); the proposal
 *   (submitted_data) keeps the member reference out and only the fields that
 *   differ from the Person now. Nothing changed → refused.
 * - Gender: the existing health rule applies (HealthRecordRules: no change
 *   away from FEMALE while a pregnancy / breastfeeding record is active) at
 *   submission, approval and APPLY.
 * - Base: the membership's active state and the Person's four fields, read
 *   under the engine's Family → Person locks; any change blocks approve / APPLY.
 * - One open PERSON_CORRECTION per member.
 */
final class PersonCorrectionHandler implements ChangeRequestHandler
{
    /** The V1 field set, in presentation order, with its Arabic labels. */
    public const FIELDS = [
        'full_name' => 'الاسم الكامل',
        'birth_date' => 'تاريخ الميلاد',
        'gender' => 'الجنس',
        'marital_status' => 'الحالة الاجتماعية',
    ];

    private const GENDER_LABELS = [Gender::MALE->value => 'ذكر', Gender::FEMALE->value => 'أنثى'];

    private const MARITAL_LABELS = [
        MaritalStatus::SINGLE->value => 'أعزب/عزباء',
        MaritalStatus::MARRIED->value => 'متزوج/ة',
        MaritalStatus::DIVORCED->value => 'مطلق/ة',
        MaritalStatus::WIDOWED->value => 'أرمل/ة',
        MaritalStatus::UNKNOWN->value => 'غير معروف',
    ];

    public function familySubmittable(): bool
    {
        return true;
    }

    public function payloadVersion(): int
    {
        return 1;
    }

    public function inputRules(Family $family): array
    {
        return [
            'member_ref' => ['required', 'string', 'size:64', new OnlyKeys(['member_ref', ...array_keys(self::FIELDS)])],
            ...self::fieldRules('present'),
        ];
    }

    public function dataRules(Family $family): array
    {
        return self::fieldRules('sometimes');
    }

    public function resolveTarget(FamilyAccessResult $context, array $validated): ChangeRequestTarget
    {
        $membership = HouseholdMemberReference::resolve($context, (string) $validated['member_ref'])
            ?? throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);

        return ChangeRequestTarget::member($context->family, $membership);
    }

    public function normalize(array $validated, ChangeRequestTarget $target): array
    {
        $person = self::person($target);
        $proposed = [
            'full_name' => trim((string) $validated['full_name']),
            'birth_date' => $validated['birth_date'] ?? null,
            'gender' => $validated['gender'],
            'marital_status' => $validated['marital_status'],
        ];

        $current = self::values($person);
        $changed = array_filter($proposed, fn ($value, string $field) => $value !== $current[$field], ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            throw ValidationException::withMessages(['data' => 'لم تغيّر أي بيانات لهذا الفرد.']);
        }

        return $changed;
    }

    public function baseValues(ChangeRequestTarget $target, array $data): array
    {
        $person = self::person($target);

        return [
            'membership' => (int) $target->membership->getKey(),
            'active' => (bool) FamilyMembership::query()->whereKey($target->membership->getKey())->value('is_active'),
            ...self::values($person),
        ];
    }

    public function preconditions(ChangeRequestTarget $target, array $data): void
    {
        // Still an ACTIVE member of this Family, and an existing Person.
        $active = FamilyMembership::query()->whereKey($target->membership->getKey())->value('is_active');
        $person = Person::query()->find($target->person->getKey());
        if (! $active || $person === null) {
            throw new ChangeRequestException(ChangeRequestException::NOT_APPLICABLE);
        }

        // The health rule of the Staff edit (UpdatePersonRequest), in family wording.
        if (isset($data['gender']) && ($gender = Gender::tryFrom((string) $data['gender'])) !== null) {
            try {
                HealthRecordRules::assertGenderChangeAllowed($person, $gender);
            } catch (ValidationException) {
                throw ValidationException::withMessages([
                    'gender' => 'لا يمكن تصحيح الجنس حاليًا لوجود سجل حمل أو رضاعة نشط لهذا الفرد. تواصل مع فريق السجل.',
                ]);
            }
        }
    }

    public function apply(ChangeRequest $request, ChangeRequestTarget $target, int $actingUserId): void
    {
        app(UpdatePersonAction::class)->handle(self::person($target), $request->submitted_data, $actingUserId);
    }

    public function present(ChangeRequest $request, ChangeRequestPresentationContext $context): array
    {
        $person = $request->person_id === null ? null : Person::withTrashed()->find($request->person_id);
        $current = $person === null ? [] : self::values($person);

        // Who the request is about (unchanged), then each proposed field.
        $presentation = ChangeRequestPresentation::make()->row('الفرد المعني', $person?->full_name, $person?->full_name);
        foreach (self::FIELDS as $field => $label) {
            if (array_key_exists($field, $request->submitted_data)) {
                $presentation->row($label, self::display($field, $current[$field] ?? null), self::display($field, $request->submitted_data[$field]));
            }
        }

        return $presentation->toArray();
    }

    public function profileSections(): array
    {
        return [ProfileReviewSection::HEAD, ProfileReviewSection::MEMBERS];
    }

    public function openConflictKey(ChangeRequestTarget $target, array $data): ?string
    {
        return 'member:'.$target->membership->getKey();
    }

    /** @return array<string, list<mixed>> the rules of UpdatePersonRequest for the four fields */
    private static function fieldRules(string $presence): array
    {
        return [
            'full_name' => [$presence, 'required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && trim($value) === '') {
                    $fail('الاسم الكامل مطلوب.');
                }
            }],
            'birth_date' => [$presence, 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gender' => [$presence, 'required', Rule::enum(Gender::class)],
            'marital_status' => [$presence, 'required', Rule::enum(MaritalStatus::class)],
        ];
    }

    private static function person(ChangeRequestTarget $target): Person
    {
        return Person::query()->find($target->person->getKey())
            ?? throw new ChangeRequestException(ChangeRequestException::NOT_APPLICABLE);
    }

    /** @return array<string, ?string> the four fields as stored (dates as Y-m-d, enums as codes) */
    private static function values(Person $person): array
    {
        return [
            'full_name' => $person->full_name,
            'birth_date' => $person->birth_date?->toDateString(),
            'gender' => $person->gender?->value,
            'marital_status' => $person->marital_status?->value,
        ];
    }

    private static function display(string $field, ?string $value): ?string
    {
        return match ($field) {
            'gender' => $value === null ? null : (self::GENDER_LABELS[$value] ?? null),
            'marital_status' => $value === null ? null : (self::MARITAL_LABELS[$value] ?? null),
            default => $value,
        };
    }
}
