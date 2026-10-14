<?php

namespace App\Support\ChangeRequests\Handlers;

use App\Actions\RegisterFamilyAction;
use App\Enums\ChangeRequestAttestation;
use App\Enums\ChangeRequestStatus;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ProfileReviewSection;
use App\Enums\RegistrationSource;
use App\Exceptions\ChangeRequestException;
use App\Exceptions\DuplicateNationalIdException;
use App\Exceptions\MissingRelationshipTypeException;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\Person;
use App\Rules\OnlyKeys;
use App\Support\ChangeRequests\ChangeRequestApprovalEvidence;
use App\Support\ChangeRequests\ChangeRequestHandler;
use App\Support\ChangeRequests\ChangeRequestPresentation;
use App\Support\ChangeRequests\ChangeRequestPresentationContext;
use App\Support\ChangeRequests\ChangeRequestTarget;
use App\Support\ChangeRequests\ProposesNewFamily;
use App\Support\ChangeRequests\RequiresApprovalAttestation;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyAuth\FamilyMobile;
use App\Support\MobileMask;
use App\Support\NationalId;
use App\Support\NationalIdGuard;
use App\Support\NationalIdMask;
use App\Support\RegistryNationalId;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * REGISTER_FAMILY — a Coordinator's proposal of a NEW Family (FAMILY_ADD;
 * CO-02.5, docs/adr/CO-02-COORDINATOR-REQUESTS.md §3). The request has no
 * Family: the proposed Clan / Branch are its columns (checked against the
 * Coordinator's scope by the engine) and the proposal is the head's
 * identity and the ORIGINAL residence only — never a current residence,
 * health, assessment or assistance value.
 *
 * - Submission: nothing identity-dependent (no answer about whether the
 *   National ID exists); one open proposal per submitting Coordinator and
 *   National ID (never across Coordinators).
 * - Base: who holds the head's National ID (under its advisory lock), so a
 *   registry change while the request waits blocks approval and APPLY.
 * - Approval (Staff): both attestation codes — IDENTITY_VERIFIED (the
 *   reviewer typed the National ID from the document; it must match) and
 *   RELATIONSHIP_VERIFIED (the reviewer verified that the person is
 *   genuinely the household head and that the Family belongs to the
 *   proposed Clan / Branch — Clan membership without a Branch; nothing
 *   social, health or assistance — docs/adr/CO-02 §21) — and the ID must
 *   be free: no Person holds it, a soft-deleted one included (DB-ADR-061).
 * - APPLY: RegisterFamilyAction (Family, head Person, HEAD membership,
 *   residence) with registration_source and residence source
 *   COORDINATOR_REQUEST and registration date = the APPLY date. Its
 *   National ID guard and uq_persons_national_id are the final word; any
 *   refusal is PRECONDITION_FAILED and rolls everything back.
 *
 * Opt-in to the coordinator proposal path through ProposesNewFamily; it is
 * never family-submittable and has no target before APPLY.
 */
final class RegisterFamilyHandler implements ChangeRequestHandler, ProposesNewFamily, RequiresApprovalAttestation
{
    private const NAME_MAX = 255;

    private const RESIDENCE_MAX = 255;

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
        return false;
    }

    public function payloadVersion(): int
    {
        return 1;
    }

    // ---------------------------------------------- no target before APPLY

    public function inputRules(Family $family): array
    {
        throw new LogicException('A proposed Family is validated by proposalRules().');
    }

    public function dataRules(Family $family): array
    {
        throw new LogicException('A proposed Family is validated by proposalRules().');
    }

    public function resolveTarget(FamilyAccessResult $context, array $validated): ChangeRequestTarget
    {
        throw new LogicException('A proposed Family has no target before APPLY.');
    }

    public function normalize(array $validated, ChangeRequestTarget $target): array
    {
        throw new LogicException('A proposed Family is normalized by normalizeProposal().');
    }

    public function baseValues(ChangeRequestTarget $target, array $data): array
    {
        throw new LogicException('A proposed Family has no target before APPLY.');
    }

    public function preconditions(ChangeRequestTarget $target, array $data): void
    {
        throw new LogicException('A proposed Family has no target before APPLY.');
    }

    public function apply(ChangeRequest $request, ChangeRequestTarget $target, int $actingUserId): void
    {
        throw new LogicException('A proposed Family is applied by applyProposal().');
    }

    public function openConflictKey(ChangeRequestTarget $target, array $data): ?string
    {
        return null;
    }

    // ------------------------------------------------------------ proposal

    public function proposalRules(): array
    {
        return [
            'household_head' => ['required', 'array', new OnlyKeys(['household_head', 'residence'])],
            'household_head.full_name' => ['required', 'string', 'max:'.self::NAME_MAX, function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && trim($value) === '') {
                    $fail('الاسم الكامل مطلوب.');
                }
            }],
            // CO-01D.4 / DB-ADR-061: required, nine digits, never a placeholder.
            'household_head.national_id' => ['required', 'string', 'max:32', RegistryNationalId::rule()],
            'household_head.gender' => ['required', Rule::enum(Gender::class)],
            'household_head.marital_status' => ['nullable', Rule::enum(MaritalStatus::class)],
            'household_head.birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'household_head.mobile' => ['nullable', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && trim((string) $value) !== '' && FamilyMobile::normalize($value) === null) {
                    $fail('رقم الجوال يجب أن يتكون من 10 أرقام ويبدأ بـ 05.');
                }
            }],
            // The ORIGINAL residence only (docs/03 CO-02): never the current one.
            'residence' => ['required', 'array'],
            'residence.original_residence_text' => ['required', 'string', 'max:'.self::RESIDENCE_MAX, function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && trim($value) === '') {
                    $fail('مكان السكن الأصلي مطلوب.');
                }
            }],
        ];
    }

    public function normalizeProposal(array $validated): array
    {
        $head = $validated['household_head'];
        $mobile = $head['mobile'] ?? null;

        return [
            'household_head' => [
                'full_name' => trim((string) $head['full_name']),
                'national_id' => (string) RegistryNationalId::canonical($head['national_id']),
                'gender' => $head['gender'],
                'marital_status' => $head['marital_status'] ?? null,
                'birth_date' => $head['birth_date'] ?? null,
                'mobile' => $mobile === null || trim((string) $mobile) === '' ? null : FamilyMobile::normalize($mobile),
            ],
            'residence' => [
                'original_residence_text' => trim((string) $validated['residence']['original_residence_text']),
            ],
        ];
    }

    public function proposalOpenConflictKey(array $data): ?string
    {
        return 'national_id:'.($data['household_head']['national_id'] ?? '');
    }

    /** The stored proposal still validates; identity is decided at approval and APPLY only. */
    public function proposalPreconditions(ChangeRequest $request): void
    {
        $data = $request->submitted_data;
        if (! is_array($data) || Validator::make($data, $this->proposalRules())->fails()
            || RegistryNationalId::canonical($data['household_head']['national_id'] ?? null) !== ($data['household_head']['national_id'] ?? null)) {
            throw new ChangeRequestException(ChangeRequestException::PRECONDITION_FAILED);
        }
    }

    public function proposalBaseValues(ChangeRequest $request): array
    {
        $nationalId = (string) ($request->submitted_data['household_head']['national_id'] ?? '');
        NationalIdGuard::lock($nationalId);

        return [
            'holders' => NationalIdGuard::matches($nationalId)->map(fn (Person $person) => (int) $person->getKey())->values()->all(),
            'deleted_holder' => NationalIdGuard::heldByDeleted($nationalId),
        ];
    }

    public function requiredAttestations(): array
    {
        return [ChangeRequestAttestation::IDENTITY_VERIFIED, ChangeRequestAttestation::RELATIONSHIP_VERIFIED];
    }

    public function assertApprovable(ChangeRequest $request, ChangeRequestTarget $target, ChangeRequestApprovalEvidence $evidence): void
    {
        throw new LogicException('A proposed Family is approved through assertProposalApprovable().');
    }

    public function assertProposalApprovable(ChangeRequest $request, ChangeRequestApprovalEvidence $evidence): void
    {
        $nationalId = (string) ($request->submitted_data['household_head']['national_id'] ?? '');
        // The reviewer types the number from the document; compared only,
        // never stored, returned or logged (FP-ADR-076 pattern).
        if (! NationalId::matches($nationalId, $evidence->verifiedNationalId)) {
            throw ValidationException::withMessages(['verified_national_id' => 'رقم الهوية المدخل من الوثيقة لا يطابق رقم الهوية في الطلب.']);
        }
        // A new head only: no Person may hold the ID (soft-deleted included).
        NationalIdGuard::lock($nationalId);
        if (NationalIdGuard::matches($nationalId)->isNotEmpty() || NationalIdGuard::heldByDeleted($nationalId)) {
            throw new ChangeRequestException(ChangeRequestException::PRECONDITION_FAILED);
        }
    }

    public function applyProposal(ChangeRequest $request, int $actingUserId): Family
    {
        $data = $request->submitted_data;
        $head = $data['household_head'];

        try {
            return app(RegisterFamilyAction::class)->handle([
                'clan_code' => $request->proposedClan->code,
                'branch_code' => $request->proposedBranch?->code,
                'registration_date' => now()->toDateString(),
                'registration_source' => RegistrationSource::COORDINATOR_REQUEST->value,
                'household_head' => [
                    'full_name' => $head['full_name'],
                    'national_id' => $head['national_id'],
                    'gender' => $head['gender'],
                    'marital_status' => $head['marital_status'] ?? MaritalStatus::UNKNOWN->value,
                    'birth_date' => $head['birth_date'] ?? null,
                    'mobile' => $head['mobile'] ?? null,
                ],
                'residence' => ['original_residence_text' => $data['residence']['original_residence_text']],
            ], $actingUserId, RegistrationSource::COORDINATOR_REQUEST);
        } catch (DuplicateNationalIdException|MissingRelationshipTypeException) {
            throw new ChangeRequestException(ChangeRequestException::PRECONDITION_FAILED);
        }
    }

    // ---------------------------------------------------------- presentation

    public function present(ChangeRequest $request, ChangeRequestPresentationContext $context): array
    {
        $head = $request->submitted_data['household_head'] ?? [];
        $presentation = ChangeRequestPresentation::make()->row('اسم رب الأسرة', null, $head['full_name'] ?? null);
        if ($context->canViewMaskedIdentity) {
            $presentation->row('رقم هوية رب الأسرة', null, NationalIdMask::mask($head['national_id'] ?? null));
        }
        $presentation
            ->row('الجنس', null, self::GENDER_LABELS[$head['gender'] ?? ''] ?? null)
            ->row('تاريخ الميلاد', null, $head['birth_date'] ?? null)
            ->row('الحالة الاجتماعية', null, self::MARITAL_LABELS[$head['marital_status'] ?? ''] ?? null)
            ->row('رقم الجوال', null, MobileMask::mask($head['mobile'] ?? null))
            ->row('العائلة', null, $request->proposedClan?->name)
            ->row('الفرع', null, $request->proposedBranch?->name)
            ->row('مكان السكن الأصلي', null, $request->submitted_data['residence']['original_residence_text'] ?? null);

        // Staff only, while a decision is still to be made: whether the ID is
        // free. Never to a Coordinator or a family (no existence oracle).
        if ($context->isStaff() && in_array($request->status, ChangeRequestStatus::open(), true)) {
            $presentation->row('مطابقة رقم الهوية في السجل', self::matchSummary($request, $context), null);
        }

        return $presentation->toArray();
    }

    public function profileSections(): array
    {
        return [ProfileReviewSection::FAMILY, ProfileReviewSection::HEAD];
    }

    /** A safe, Staff-only description: a person code (and the name with person.view), never an ID value. */
    private static function matchSummary(ChangeRequest $request, ChangeRequestPresentationContext $context): string
    {
        $nationalId = (string) ($request->submitted_data['household_head']['national_id'] ?? '');
        $match = NationalIdGuard::matches($nationalId)->first();
        if ($match !== null) {
            $who = $match->person_code.($context->canViewPersonNames ? ' — '.$match->full_name : '');

            return "رقم الهوية مسجّل لشخص في السجل ({$who}) — لا يمكن اعتماد الطلب كأسرة جديدة.";
        }
        if (NationalIdGuard::heldByDeleted($nationalId)) {
            return 'رقم الهوية محجوز لسجل محذوف — لا يمكن اعتماد الطلب.';
        }

        return 'لا يوجد شخص مسجّل بهذا الرقم — تُنشأ أسرة جديدة ورب أسرة جديد عند التطبيق.';
    }
}
