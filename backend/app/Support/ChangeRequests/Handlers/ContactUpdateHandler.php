<?php

namespace App\Support\ChangeRequests\Handlers;

use App\Actions\UpdatePersonAction;
use App\Enums\LifeStatus;
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
use App\Support\FamilyAuth\CurrentTrustedMobile;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyAuth\FamilyMobile;
use App\Support\FamilyPortal\HouseholdMemberReference;
use App\Support\MobileMask;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * CONTACT_UPDATE (docs/11 FP-ADR-079): the household head changes the
 * PRIMARY mobile of a member of their Family — or their own. A
 * system-verified type: an OTP to the proposed number (and, for the head's
 * own TRUSTED number, the current password and an OTP to the old number)
 * replaces Staff review; the flow actions approve and apply it as the
 * SYSTEM. Applied by the canonical UpdatePersonAction, whose Person hook
 * makes the previous number's trust STALE.
 *
 * - The member is named by member_ref among the context Family's ACTIVE
 *   memberships (FP-ADR-063); a deceased member's number is not changed.
 * - Input: member_ref and mobile only (alternate_mobile is out of V1). The
 *   number must be a valid portal mobile (05 + 8 digits) and differ from
 *   the current one; it is stored normalized. Shared numbers are allowed:
 *   nothing looks the number up.
 * - Proposal: `mobile`, `self` (the head's own number) and
 *   `trust_transfer` (self AND the current number TRUSTED at submission —
 *   the R2 path). A member's number never carries trust.
 * - Base: the membership's active and head state, the Person's normalized
 *   mobile and life status; any change refuses the completion.
 * - One open CONTACT_UPDATE per member (a newer start closes the older).
 * - Presentation: numbers MASKED for every viewer.
 */
final class ContactUpdateHandler implements ChangeRequestHandler
{
    public const INVALID_MOBILE = 'رقم الجوال يجب أن يتكون من 10 أرقام ويبدأ بـ 05.';

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
            'member_ref' => ['required', 'string', 'size:64', new OnlyKeys(['member_ref', 'mobile'])],
            'mobile' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail): void {
                if (FamilyMobile::normalize($value) === null) {
                    $fail(self::INVALID_MOBILE);
                }
            }],
        ];
    }

    public function dataRules(Family $family): array
    {
        return [
            'mobile' => ['required', 'string', 'regex:/\A05[0-9]{8}\z/'],
            'self' => ['required', 'boolean'],
            'trust_transfer' => ['required', 'boolean'],
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
        $person = self::person($target);
        $mobile = (string) FamilyMobile::normalize($validated['mobile']);
        if ($mobile === FamilyMobile::normalize($person->mobile)) {
            throw ValidationException::withMessages(['mobile' => 'رقم الجوال الجديد هو نفسه الرقم المسجّل حاليًا.']);
        }
        $self = (bool) self::membership($target)?->is_household_head;

        return [
            'mobile' => $mobile,
            'self' => $self,
            'trust_transfer' => $self && app(CurrentTrustedMobile::class)->for($person)->isTrusted(),
        ];
    }

    public function baseValues(ChangeRequestTarget $target, array $data): array
    {
        $person = self::person($target);
        $membership = self::membership($target);

        return [
            'membership' => (int) $target->membership->getKey(),
            'active' => (bool) $membership?->is_active,
            'head' => (bool) $membership?->is_household_head,
            'mobile' => FamilyMobile::normalize($person->mobile),
            'life_status' => $person->life_status?->value,
        ];
    }

    public function preconditions(ChangeRequestTarget $target, array $data): void
    {
        $membership = self::membership($target);
        $person = Person::query()->find($target->person->getKey());
        if ($membership === null || ! $membership->is_active || $person === null
            || (bool) $membership->is_household_head !== (bool) ($data['self'] ?? false)) {
            throw new ChangeRequestException(ChangeRequestException::NOT_APPLICABLE);
        }
        if ($person->life_status === LifeStatus::DECEASED) {
            throw ValidationException::withMessages(['member_ref' => 'لا يمكن تحديث رقم جوال فرد متوفى.']);
        }
    }

    public function apply(ChangeRequest $request, ChangeRequestTarget $target, int $actingUserId): void
    {
        app(UpdatePersonAction::class)->handle(self::person($target), ['mobile' => $request->submitted_data['mobile']], $actingUserId);
    }

    public function present(ChangeRequest $request, ChangeRequestPresentationContext $context): array
    {
        $person = $request->person_id === null ? null : Person::withTrashed()->find($request->person_id);

        return ChangeRequestPresentation::make()
            ->row('الفرد المعني', $person?->full_name, $person?->full_name)
            ->row('رقم الجوال', MobileMask::mask($person?->mobile), MobileMask::mask($request->submitted_data['mobile'] ?? null))
            ->toArray();
    }

    public function profileSections(): array
    {
        return [];
    }

    public function openConflictKey(ChangeRequestTarget $target, array $data): ?string
    {
        return 'member:'.$target->membership->getKey();
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
