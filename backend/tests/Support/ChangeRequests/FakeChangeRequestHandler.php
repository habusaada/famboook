<?php

namespace Tests\Support\ChangeRequests;

use App\Actions\UpdateFamilyAction;
use App\Enums\ProfileReviewSection;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Support\ChangeRequests\ChangeRequestHandler;
use App\Support\ChangeRequests\ChangeRequestPresentation;
use App\Support\ChangeRequests\ChangeRequestPresentationContext;
use App\Support\ChangeRequests\ChangeRequestTarget;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\HouseholdMemberReference;
use RuntimeException;

/**
 * TEST-ONLY Change Request handler (PWA-5b). It exists only under tests/ and
 * is registered only through ChangeRequestTypes::fake() for the OTHER code —
 * never in Production. It proposes a Family's paper form number and applies
 * it through the existing canonical UpdateFamilyAction (which records its own
 * FAMILY_UPDATED activity), so the engine can be proven end to end against a
 * real registry write that must roll back.
 *
 * Switches (reset by ChangeRequestFixtures) steer the outcomes: a failed
 * precondition, an unexpected exception or a refusal AFTER the canonical
 * write, the open-conflict key, family-submittable.
 */
final class FakeChangeRequestHandler implements ChangeRequestHandler
{
    public static bool $submittable = true;

    public static bool $preconditionFails = false;

    /** null | 'unexpected' | 'refusal' — thrown after the canonical write. */
    public static ?string $failAfterWrite = null;

    public static bool $conflictKeys = true;

    public static int $applied = 0;

    public static function reset(): void
    {
        self::$submittable = true;
        self::$preconditionFails = false;
        self::$failAfterWrite = null;
        self::$conflictKeys = true;
        self::$applied = 0;
    }

    public function familySubmittable(): bool
    {
        return self::$submittable;
    }

    public function payloadVersion(): int
    {
        return 1;
    }

    public function inputRules(Family $family): array
    {
        return [
            'paper_form_no' => ['required', 'string', 'max:50'],
            'member_ref' => ['nullable', 'string', 'size:64'],
        ];
    }

    public function dataRules(Family $family): array
    {
        return ['paper_form_no' => ['required', 'string', 'max:50']];
    }

    public function resolveTarget(FamilyAccessResult $context, array $validated): ChangeRequestTarget
    {
        if (($validated['member_ref'] ?? null) === null) {
            return ChangeRequestTarget::family($context->family);
        }
        $membership = HouseholdMemberReference::resolve($context, $validated['member_ref'])
            ?? throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);

        return ChangeRequestTarget::member($context->family, $membership);
    }

    public function normalize(array $validated, ChangeRequestTarget $target): array
    {
        return ['paper_form_no' => trim($validated['paper_form_no'])];
    }

    public function baseValues(ChangeRequestTarget $target, array $data): array
    {
        return ['paper_form_no' => Family::withTrashed()->whereKey($target->family->getKey())->value('paper_form_no')];
    }

    public function preconditions(ChangeRequestTarget $target, array $data): void
    {
        if (self::$preconditionFails) {
            throw new ChangeRequestException(ChangeRequestException::PRECONDITION_FAILED);
        }
        if ($target->membership !== null && ! FamilyMembership::query()->whereKey($target->membership->getKey())->value('is_active')) {
            throw new ChangeRequestException(ChangeRequestException::NOT_APPLICABLE);
        }
    }

    public function apply(ChangeRequest $request, ChangeRequestTarget $target, int $actingUserId): void
    {
        app(UpdateFamilyAction::class)->handle($target->family, ['paper_form_no' => $request->submitted_data['paper_form_no']], $actingUserId);
        self::$applied++;

        match (self::$failAfterWrite) {
            // A message that must never surface: the engine reports a class only.
            'unexpected' => throw new RuntimeException('SQLSTATE secret 0590000000 '.$request->submitted_data['paper_form_no']),
            'refusal' => throw new ChangeRequestException(ChangeRequestException::NOT_APPLICABLE),
            default => null,
        };
    }

    public function present(ChangeRequest $request, ChangeRequestPresentationContext $context): array
    {
        // Staff see the live current value; the family sees its proposal only.
        $current = $context->isStaff() ? $this->baseValues(ChangeRequestTarget::of($request, $request->family), $request->submitted_data)['paper_form_no'] : null;

        return ChangeRequestPresentation::make()->row('رقم الاستمارة', $current, $request->submitted_data['paper_form_no'])->toArray();
    }

    public function profileSections(): array
    {
        return [ProfileReviewSection::FAMILY];
    }

    public function openConflictKey(ChangeRequestTarget $target, array $data): ?string
    {
        if (! self::$conflictKeys) {
            return null;
        }

        return $target->isFamily() ? 'family' : 'member:'.$target->membership->getKey();
    }
}
