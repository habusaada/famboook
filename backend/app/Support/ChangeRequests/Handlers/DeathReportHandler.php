<?php

namespace App\Support\ChangeRequests\Handlers;

use App\Actions\RecordPersonDeathAction;
use App\Enums\LifeStatus;
use App\Enums\LifeStatusVerificationMethod;
use App\Enums\ProfileReviewSection;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Exceptions\PersonLifeStatusException;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Rules\OnlyKeys;
use App\Support\ChangeRequests\ChangeRequestHandler;
use App\Support\ChangeRequests\ChangeRequestPresentation;
use App\Support\ChangeRequests\ChangeRequestPresentationContext;
use App\Support\ChangeRequests\ChangeRequestTarget;
use App\Support\ChangeRequests\ChangeRequestWorkflow;
use App\Support\ChangeRequests\RequiresApprovalVerificationMethod;
use App\Support\DeathDate;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\HouseholdMemberReference;
use Closure;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * DEATH_REPORT (docs/03 §31, docs/05 §75; docs/11 FP-ADR-059, FP-ADR-061,
 * FP-ADR-077): the household head reports the death of a household member.
 * Applied by the canonical RecordPersonDeathAction — the same action and the
 * same rules as the Staff death recording; the reported date is not
 * canonical until APPLY succeeds.
 *
 * - The member is named by member_ref and resolved inside the context Family
 *   among its ACTIVE memberships only (FP-ADR-063). Never the household head
 *   (FP-ADR-059): a head's death is recorded by Staff (docs/03 §30). The
 *   Person must not be DECEASED already (ALIVE or UNKNOWN, as the action).
 * - Input: death_date (Y-m-d) or death_date_unknown = true — exactly one; the
 *   proposal (submitted_data) is only `death_date`, NULL when unknown (docs/03
 *   §29: never invented). DeathDate rules: not in the future, not before the
 *   birth date — at submission, approval and APPLY.
 * - Evidence (FP-ADR-077): no upload in V1. The approving reviewer states how
 *   the death was verified (RequiresApprovalVerificationMethod); apply()
 *   reads that code from the APPROVED event and passes it to the action.
 * - Base: the membership's active and head state and the Person's life
 *   status, death date and birth date, read under the engine's Family →
 *   Person locks; any change (a death recorded by Staff, the member made
 *   head) blocks approve / APPLY.
 * - One open DEATH_REPORT per member.
 * - Nothing else changes (RecordPersonDeathAction): memberships, the head
 *   flag, other Persons, is_active.
 */
final class DeathReportHandler implements ChangeRequestHandler, RequiresApprovalVerificationMethod
{
    private const LIFE_STATUS_LABELS = [
        LifeStatus::ALIVE->value => 'على قيد الحياة',
        LifeStatus::DECEASED->value => 'متوفى/ة',
        LifeStatus::UNKNOWN->value => 'غير معروفة',
    ];

    private const UNKNOWN_DATE = 'غير معروف';

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
            'member_ref' => ['required', 'string', 'size:64', new OnlyKeys(['member_ref', 'death_date', 'death_date_unknown'])],
            'death_date_unknown' => ['required', 'boolean'],
            // The DeathDate format and future rules, with their Arabic messages;
            // the birth-date rule needs the Person (preconditions), and
            // "date XOR unknown" is decided by normalize().
            'death_date' => ['present', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    DeathDate::validate(is_string($value) ? $value : null, null);
                } catch (ValidationException $e) {
                    $fail((string) $e->validator->errors()->first());
                }
            }],
        ];
    }

    public function dataRules(Family $family): array
    {
        return [
            'death_date' => ['present', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    public function resolveTarget(FamilyAccessResult $context, array $validated): ChangeRequestTarget
    {
        $membership = HouseholdMemberReference::resolve($context, (string) $validated['member_ref'])
            ?? throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);

        return ChangeRequestTarget::member($context->family, $membership);
    }

    public function normalize(array $validated, ChangeRequestTarget $target): array
    {
        $unknown = (bool) $validated['death_date_unknown'];
        $date = $validated['death_date'] ?? null;
        if ($unknown && $date !== null) {
            throw ValidationException::withMessages(['death_date' => 'لا يمكن إدخال تاريخ الوفاة مع اختيار «تاريخ الوفاة غير معروف».']);
        }
        if (! $unknown && $date === null) {
            throw ValidationException::withMessages(['death_date' => 'أدخل تاريخ الوفاة، أو اختر «تاريخ الوفاة غير معروف».']);
        }

        return ['death_date' => $unknown ? null : (string) $date];
    }

    public function baseValues(ChangeRequestTarget $target, array $data): array
    {
        $person = self::person($target);
        $membership = self::membership($target);

        return [
            'membership' => (int) $target->membership->getKey(),
            'active' => (bool) $membership?->is_active,
            'head' => (bool) $membership?->is_household_head,
            'life_status' => $person->life_status?->value,
            'death_date' => $person->death_date?->toDateString(),
            'birth_date' => $person->birth_date?->toDateString(),
        ];
    }

    public function preconditions(ChangeRequestTarget $target, array $data): void
    {
        // Still an ACTIVE member of this Family, and an existing Person.
        $membership = self::membership($target);
        $person = Person::query()->find($target->person->getKey());
        if ($membership === null || ! $membership->is_active || $person === null) {
            throw new ChangeRequestException(ChangeRequestException::NOT_APPLICABLE);
        }

        // FP-ADR-059: never the household head through the portal.
        if ($membership->is_household_head) {
            throw ValidationException::withMessages([
                'member_ref' => 'لا يمكن الإبلاغ عن وفاة رب الأسرة من خلال البوابة. تواصل مع فريق السجل.',
            ]);
        }

        // A recorded death is irreversible and never recorded twice.
        if ($person->life_status === LifeStatus::DECEASED) {
            throw ValidationException::withMessages(['member_ref' => 'وفاة هذا الفرد مسجّلة مسبقًا في السجل.']);
        }

        // The DeathDate rules of RecordPersonDeathAction, before anything is stored.
        DeathDate::validate($data['death_date'] ?? null, $person->birth_date?->toDateString());
    }

    public function apply(ChangeRequest $request, ChangeRequestTarget $target, int $actingUserId): void
    {
        try {
            app(RecordPersonDeathAction::class)->handle(self::person($target), $request->submitted_data['death_date'] ?? null,
                self::approvedVerificationMethod($request), $actingUserId);
        } catch (PersonLifeStatusException) {
            throw new ChangeRequestException(ChangeRequestException::NOT_APPLICABLE);
        }
    }

    public function present(ChangeRequest $request, ChangeRequestPresentationContext $context): array
    {
        $person = $request->person_id === null ? null : Person::withTrashed()->find($request->person_id);
        $reported = $request->submitted_data['death_date'] ?? null;

        return ChangeRequestPresentation::make()
            ->row('الفرد المعني', $person?->full_name, $person?->full_name)
            ->row('تاريخ الميلاد', $person?->birth_date?->toDateString(), $person?->birth_date?->toDateString())
            ->row('الحالة الحياتية', self::LIFE_STATUS_LABELS[$person?->life_status?->value] ?? null, self::LIFE_STATUS_LABELS[LifeStatus::DECEASED->value])
            ->row('تاريخ الوفاة', $person?->life_status === LifeStatus::DECEASED ? ($person->death_date?->toDateString() ?? self::UNKNOWN_DATE) : null,
                $reported ?? self::UNKNOWN_DATE)
            ->toArray();
    }

    public function profileSections(): array
    {
        return [ProfileReviewSection::MEMBERS];
    }

    public function openConflictKey(ChangeRequestTarget $target, array $data): ?string
    {
        return 'member:'.$target->membership->getKey();
    }

    /**
     * The method the approving reviewer recorded (FP-ADR-077). Every approved
     * DEATH_REPORT has one; a missing or unknown code is an integrity fault,
     * never a default.
     */
    private static function approvedVerificationMethod(ChangeRequest $request): LifeStatusVerificationMethod
    {
        $code = ChangeRequestWorkflow::lastEvent($request, WorkflowEventType::APPROVED)?->metadata['verification_method'] ?? null;

        return LifeStatusVerificationMethod::tryFrom((string) $code)
            ?? throw new LogicException('An approved death report has no verification method.');
    }

    private static function person(ChangeRequestTarget $target): Person
    {
        return Person::query()->find($target->person->getKey())
            ?? throw new ChangeRequestException(ChangeRequestException::NOT_APPLICABLE);
    }

    private static function membership(ChangeRequestTarget $target): ?FamilyMembership
    {
        return FamilyMembership::query()->find($target->membership->getKey());
    }
}
