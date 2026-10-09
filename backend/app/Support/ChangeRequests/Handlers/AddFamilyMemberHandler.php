<?php

namespace App\Support\ChangeRequests\Handlers;

use App\Actions\AddFamilyMemberAction;
use App\Actions\AttachFamilyMemberAction;
use App\Enums\ChangeRequestAttestation;
use App\Enums\ChangeRequestStatus;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ProfileReviewSection;
use App\Exceptions\ChangeRequestException;
use App\Exceptions\DuplicateNationalIdException;
use App\Exceptions\MissingRelationshipTypeException;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Rules\OnlyKeys;
use App\Support\ChangeRequests\ChangeRequestApprovalEvidence;
use App\Support\ChangeRequests\ChangeRequestHandler;
use App\Support\ChangeRequests\ChangeRequestPresentation;
use App\Support\ChangeRequests\ChangeRequestPresentationContext;
use App\Support\ChangeRequests\ChangeRequestTarget;
use App\Support\ChangeRequests\RequiresApprovalAttestation;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyAuth\FamilyMobile;
use App\Support\FamilyAuth\FamilyNationalId;
use App\Support\MobileMask;
use App\Support\NationalId;
use App\Support\NationalIdGuard;
use App\Support\NationalIdMask;
use App\Support\RelationshipTypes;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ADD_FAMILY_MEMBER (docs/11 FP-ADR-076): the household head asks to add a
 * person to their OWN Family. Nothing changes until Staff approve (with two
 * attestations) and APPLY. Not BIRTH_REPORT: newborns keep their own future
 * type (docs/05 §74).
 *
 * - National ID REQUIRED (an exception to docs/03 §19 for this type only):
 *   accepted and stored as exactly nine ASCII digits (FamilyNationalId);
 *   never a placeholder. Legacy registry values are compared, never changed.
 * - The family never learns whether the ID matches someone (no enumeration
 *   oracle): nothing identity-dependent is checked or answered at
 *   submission. Staff see a safe match summary; approval and APPLY refuse
 *   every unsafe case.
 * - At approval and APPLY, under the National ID advisory lock, the
 *   equivalent registry matches (NationalIdGuard::equivalentMatches) decide:
 *     none                                  → new Person + membership
 *                                             (AddFamilyMemberAction)
 *     exactly one, no active membership     → attach that Person
 *                                             (AttachFamilyMemberAction)
 *     one with an active membership, or several (ambiguous legacy data)
 *                                           → PRECONDITION_FAILED — no
 *                                             transfer, no merge, no choice.
 * - Base: who holds the ID (Person ids and their active memberships), so a
 *   registry change while the request waits blocks approval and APPLY.
 * - One open request per Family and National ID.
 */
final class AddFamilyMemberHandler implements ChangeRequestHandler, RequiresApprovalAttestation
{
    public const FIELDS = ['full_name', 'national_id', 'gender', 'relationship', 'birth_date', 'marital_status', 'mobile'];

    private const NAME_MAX = 255;

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
            'full_name' => ['required', 'string', 'max:'.self::NAME_MAX, new OnlyKeys(self::FIELDS),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && trim($value) === '') {
                        $fail('الاسم الكامل مطلوب.');
                    }
                }],
            'national_id' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail): void {
                $digits = FamilyNationalId::normalize($value);
                if ($digits === null) {
                    $fail('رقم الهوية يجب أن يتكون من 9 أرقام.');
                } elseif (self::isPlaceholder($digits)) {
                    $fail('أدخل رقم الهوية الحقيقي كما في الوثيقة.');
                }
            }],
            'gender' => ['required', Rule::enum(Gender::class)],
            'relationship' => self::relationshipRules(),
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'marital_status' => ['nullable', Rule::enum(MaritalStatus::class)],
            'mobile' => ['nullable', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && trim((string) $value) !== '' && FamilyMobile::normalize($value) === null) {
                    $fail('رقم الجوال يجب أن يتكون من 10 أرقام ويبدأ بـ 05.');
                }
            }],
        ];
    }

    public function dataRules(Family $family): array
    {
        return [
            'full_name' => ['required', 'string', 'max:'.self::NAME_MAX],
            'national_id' => ['required', 'string', 'regex:/\A[0-9]{9}\z/', 'not_regex:/\A([0-9])\1{8}\z/'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'relationship' => self::relationshipRules(),
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'marital_status' => ['nullable', Rule::enum(MaritalStatus::class)],
            'mobile' => ['nullable', 'string', 'regex:/\A05[0-9]{8}\z/'],
        ];
    }

    public function resolveTarget(FamilyAccessResult $context, array $validated): ChangeRequestTarget
    {
        // Always the context Family: no Family, Person or membership id is read.
        return ChangeRequestTarget::family($context->family);
    }

    public function normalize(array $validated, ChangeRequestTarget $target): array
    {
        $mobile = $validated['mobile'] ?? null;

        return [
            'full_name' => trim((string) $validated['full_name']),
            'national_id' => (string) FamilyNationalId::normalize($validated['national_id']),
            'gender' => $validated['gender'],
            'relationship' => $validated['relationship'],
            'birth_date' => $validated['birth_date'] ?? null,
            'marital_status' => $validated['marital_status'] ?? null,
            'mobile' => $mobile === null || trim((string) $mobile) === '' ? null : FamilyMobile::normalize($mobile),
        ];
    }

    public function baseValues(ChangeRequestTarget $target, array $data): array
    {
        $nationalId = (string) ($data['national_id'] ?? '');
        NationalIdGuard::lock($nationalId);

        return ['holders' => self::matches($nationalId)->map(fn (Person $person) => [
            'person' => (int) $person->getKey(),
            'membership' => $person->activeMembership?->getKey(),
        ])->values()->all()];
    }

    /**
     * Nothing identity-dependent here: preconditions also run at SUBMISSION,
     * where any identity answer would tell the family whether the ID exists
     * (FP-ADR-076). Identity is decided at approval (assertApprovable) and
     * APPLY (resolve). The proposal's own validity is dataRules().
     */
    public function preconditions(ChangeRequestTarget $target, array $data): void {}

    public function apply(ChangeRequest $request, ChangeRequestTarget $target, int $actingUserId): void
    {
        $data = $request->submitted_data;
        $existing = self::resolve($data['national_id']);

        try {
            if ($existing === null) {
                app(AddFamilyMemberAction::class)->handle($target->family, [
                    'full_name' => $data['full_name'],
                    'national_id' => $data['national_id'],
                    'gender' => $data['gender'],
                    'birth_date' => $data['birth_date'] ?? null,
                    'marital_status' => $data['marital_status'] ?? MaritalStatus::UNKNOWN->value,
                    'mobile' => $data['mobile'] ?? null,
                    'relationship_type_id' => RelationshipTypes::required($data['relationship']),
                ], $actingUserId);
            } else {
                // The registry Person is attached as recorded: the family's
                // name / gender / dates are for review only and never written.
                app(AttachFamilyMemberAction::class)->handle($target->family, $existing, $data['relationship'], $actingUserId);
            }
        } catch (DuplicateNationalIdException|MissingRelationshipTypeException) {
            throw new ChangeRequestException(ChangeRequestException::PRECONDITION_FAILED);
        }
    }

    public function present(ChangeRequest $request, ChangeRequestPresentationContext $context): array
    {
        $data = $request->submitted_data;
        $presentation = ChangeRequestPresentation::make()->row('الاسم الكامل', null, $data['full_name'] ?? null);
        if ($context->canViewMaskedIdentity) {
            $presentation->row('رقم الهوية', null, NationalIdMask::mask($data['national_id'] ?? null));
        }
        $presentation
            ->row('الجنس', null, self::GENDER_LABELS[$data['gender'] ?? ''] ?? null)
            ->row('صلة القرابة برب الأسرة', null, RelationshipType::query()->where('code', $data['relationship'] ?? '')->value('name'))
            ->row('تاريخ الميلاد', null, $data['birth_date'] ?? null)
            ->row('الحالة الاجتماعية', null, self::MARITAL_LABELS[$data['marital_status'] ?? ''] ?? null)
            ->row('رقم الجوال', null, MobileMask::mask($data['mobile'] ?? null));

        // Staff only, and only while a decision is still to be made: the
        // registry match that approval and APPLY will act on. Never to a family.
        if ($context->isStaff() && in_array($request->status, ChangeRequestStatus::open(), true)) {
            $presentation->row('مطابقة رقم الهوية في السجل', self::matchSummary($request, $context), null);
        }

        return $presentation->toArray();
    }

    public function profileSections(): array
    {
        return [ProfileReviewSection::MEMBERS];
    }

    public function openConflictKey(ChangeRequestTarget $target, array $data): ?string
    {
        return 'national_id:'.($data['national_id'] ?? '');
    }

    public function requiredAttestations(): array
    {
        return [ChangeRequestAttestation::IDENTITY_VERIFIED, ChangeRequestAttestation::RELATIONSHIP_VERIFIED];
    }

    public function assertApprovable(ChangeRequest $request, ChangeRequestTarget $target, ChangeRequestApprovalEvidence $evidence): void
    {
        // Identity: the reviewer types the number from the person's document;
        // it is compared with the proposal only (docs/03 §47e pattern) and
        // never stored, returned or logged.
        if (! NationalId::matches($request->submitted_data['national_id'] ?? null, $evidence->verifiedNationalId)) {
            throw ValidationException::withMessages(['verified_national_id' => 'رقم الهوية المدخل من الوثيقة لا يطابق رقم الهوية في الطلب.']);
        }
        self::resolve($request->submitted_data['national_id']);
    }

    /**
     * The registry Person to attach, NULL for a new Person, or a refusal for
     * every unsafe case (active membership anywhere — a transfer is not this
     * type; several equivalent holders — ambiguous legacy data). Called with
     * the National ID lock held (baseValues ran first in the same transaction).
     */
    private static function resolve(string $nationalId): ?Person
    {
        NationalIdGuard::lock($nationalId);
        $matches = self::matches($nationalId);
        if ($matches->count() > 1) {
            throw new ChangeRequestException(ChangeRequestException::PRECONDITION_FAILED);
        }
        $person = $matches->first();
        if ($person !== null && $person->activeMembership !== null) {
            throw new ChangeRequestException(ChangeRequestException::PRECONDITION_FAILED);
        }

        return $person;
    }

    /** @return Collection<int, Person> */
    private static function matches(string $nationalId): Collection
    {
        return NationalIdGuard::equivalentMatches($nationalId);
    }

    /** A safe, Staff-only description: codes (and names with person.view), never an ID value. */
    private static function matchSummary(ChangeRequest $request, ChangeRequestPresentationContext $context): string
    {
        $matches = self::matches((string) ($request->submitted_data['national_id'] ?? ''));
        if ($matches->isEmpty()) {
            return 'لا يوجد شخص مسجّل بهذا الرقم — يُنشأ شخص جديد عند التطبيق.';
        }
        if ($matches->count() > 1) {
            return 'أكثر من سجل مطابق لهذا الرقم — يتطلب معالجة يدوية؛ لا يمكن اعتماد الطلب.';
        }

        $person = $matches->first();
        $who = $person->person_code.($context->canViewPersonNames ? ' — '.$person->full_name : '');
        $membership = $person->activeMembership;
        if ($membership === null) {
            return "شخص مسجّل دون عضوية نشطة ({$who}) — يُربط بالأسرة كما هو مسجّل، دون تعديل بياناته.";
        }
        if ((int) $membership->family_id === (int) $request->family_id) {
            return "عضو نشط في هذه الأسرة بالفعل ({$who}) — لا يمكن اعتماد الطلب.";
        }

        return "عضو نشط في أسرة أخرى ({$membership->family->family_code}، {$who}) — النقل غير مدعوم؛ لا يمكن اعتماد الطلب.";
    }

    /** Nine identical digits (000000000, 999999999 …) — a fabricated value (docs/03 §19). */
    private static function isPlaceholder(string $digits): bool
    {
        return preg_match('/\A([0-9])\1{8}\z/', $digits) === 1;
    }

    /** @return list<mixed> an active, non-HEAD relationship type code */
    private static function relationshipRules(): array
    {
        return ['required', 'string',
            Rule::exists('relationship_types', 'code')->where('is_active', true),
            Rule::notIn([RelationshipTypes::HEAD]),
        ];
    }
}
