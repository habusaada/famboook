<?php

namespace App\Support\ChangeRequests\Handlers;

use App\Actions\UpdateFamilyResidenceAction;
use App\Enums\DisplacementStatus;
use App\Enums\ProfileReviewSection;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyResidence;
use App\Rules\OnlyKeys;
use App\Support\ChangeRequests\ChangeRequestHandler;
use App\Support\ChangeRequests\ChangeRequestPresentation;
use App\Support\ChangeRequests\ChangeRequestPresentationContext;
use App\Support\ChangeRequests\ChangeRequestTarget;
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * RESIDENCE_UPDATE (PWA-6.1, docs/11 §14 FP-ADR-059): the household head
 * proposes a CORRECTION of the Family's current residence. Applied in place
 * by the canonical UpdateFamilyResidenceAction — never a move, never a new
 * residence row, never another Family's residence.
 *
 * - Fields (V1): exactly the ones UpdateFamilyResidenceAction corrects — the
 *   current address (governorate, city, area, neighborhood, address_text)
 *   and displacement (original_residence_text, displacement_status,
 *   displacement_location_text). Never residence_type, dates, coordinates,
 *   source, notes or any id.
 * - Input: the whole proposed residence, every field present (null = not
 *   recorded); any other key is refused. A known displacement status cannot
 *   be cleared back to "not collected" by a family.
 * - Proposal (submitted_data): only the fields that differ from the current
 *   residence, so APPLY never rewrites a field the family did not change.
 *   Nothing changed → refused.
 * - Base: the current residence row and all eight fields, read under lock.
 * - One open RESIDENCE_UPDATE per Family.
 */
final class ResidenceUpdateHandler implements ChangeRequestHandler
{
    /** The V1 field set, in presentation order, with its Arabic labels. */
    public const FIELDS = [
        'governorate' => 'المحافظة',
        'city' => 'المدينة',
        'area' => 'المنطقة',
        'neighborhood' => 'الحي',
        'address_text' => 'العنوان التفصيلي',
        'original_residence_text' => 'السكن الأصلي قبل النزوح',
        'displacement_status' => 'حالة النزوح',
        'displacement_location_text' => 'مكان النزوح الحالي',
    ];

    private const SHORT_TEXT_MAX = 255;

    private const ADDRESS_TEXT_MAX = 1000;

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
        $current = self::currentResidence($family);
        $statusKnown = $current?->displacement_status !== null;

        $rules = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $rules[$field] = ['present', 'nullable', 'string', 'max:'.self::maxLength($field)];
        }
        $rules['governorate'][] = new OnlyKeys(array_keys(self::FIELDS));
        $rules['displacement_status'] = [$statusKnown ? 'required' : 'present', 'nullable', Rule::enum(DisplacementStatus::class)];
        $rules['displacement_location_text'][] = 'prohibited_unless:displacement_status,'.DisplacementStatus::DISPLACED->value;

        return $rules;
    }

    public function dataRules(Family $family): array
    {
        $rules = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:'.self::maxLength($field)];
        }
        // The location ↔ status rule needs the current residence: preconditions().
        $rules['displacement_status'] = ['sometimes', 'nullable', Rule::enum(DisplacementStatus::class)];

        return $rules;
    }

    public function resolveTarget(FamilyAccessResult $context, array $validated): ChangeRequestTarget
    {
        // Always the context Family: no id or reference is read from the input.
        return ChangeRequestTarget::family($context->family);
    }

    public function normalize(array $validated, ChangeRequestTarget $target): array
    {
        $current = self::currentResidence($target->family)
            ?? throw new ChangeRequestException(ChangeRequestException::PRECONDITION_FAILED);

        $proposed = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $value = $validated[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            $proposed[$field] = $value === '' ? null : $value;
        }
        // Mirrors UpdateFamilyResidenceAction: no location unless displaced.
        if ($proposed['displacement_status'] !== DisplacementStatus::DISPLACED->value) {
            $proposed['displacement_location_text'] = null;
        }

        $currentValues = self::values($current);
        $changed = array_filter($proposed, fn ($value, string $field) => $value !== $currentValues[$field], ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            throw ValidationException::withMessages(['data' => 'لم تغيّر أي بيانات في السكن الحالي.']);
        }

        return $changed;
    }

    public function baseValues(ChangeRequestTarget $target): array
    {
        $current = FamilyResidence::query()
            ->where('family_id', $target->family->getKey())
            ->where('is_current', true)
            ->lockForUpdate()
            ->first();

        return ['residence' => $current?->getKey(), ...($current === null ? [] : self::values($current))];
    }

    public function preconditions(ChangeRequestTarget $target, array $data): void
    {
        $current = self::currentResidence($target->family)
            ?? throw new ChangeRequestException(ChangeRequestException::PRECONDITION_FAILED);

        // The location rule against the residence as it will be after the change.
        $status = array_key_exists('displacement_status', $data) ? $data['displacement_status'] : $current->displacement_status?->value;
        if (($data['displacement_location_text'] ?? null) !== null && $status !== DisplacementStatus::DISPLACED->value) {
            throw new ChangeRequestException(ChangeRequestException::PRECONDITION_FAILED);
        }
    }

    public function apply(ChangeRequest $request, ChangeRequestTarget $target, int $actingUserId): void
    {
        app(UpdateFamilyResidenceAction::class)->handle($target->family, $request->submitted_data, $actingUserId);
    }

    public function present(ChangeRequest $request, ChangeRequestPresentationContext $context): array
    {
        // Residence data is family-visible (FamilyHouseholdProfileResource):
        // both audiences see the same rows. No identity value is ever shown.
        $current = self::currentResidence($request->family);
        $currentValues = $current === null ? [] : self::values($current);

        $presentation = ChangeRequestPresentation::make();
        foreach (self::FIELDS as $field => $label) {
            if (! array_key_exists($field, $request->submitted_data)) {
                continue;
            }
            $presentation->row($label, self::display($field, $currentValues[$field] ?? null), self::display($field, $request->submitted_data[$field]));
        }

        return $presentation->toArray();
    }

    public function profileSections(): array
    {
        return [ProfileReviewSection::RESIDENCE];
    }

    public function openConflictKey(ChangeRequestTarget $target, array $data): ?string
    {
        return 'residence';
    }

    private static function currentResidence(Family $family): ?FamilyResidence
    {
        return FamilyResidence::query()->where('family_id', $family->getKey())->where('is_current', true)->first();
    }

    /** @return array<string, ?string> the eight fields as stored (status as its code) */
    private static function values(FamilyResidence $residence): array
    {
        $values = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $value = $residence->getAttribute($field);
            $values[$field] = $value instanceof DisplacementStatus ? $value->value : $value;
        }

        return $values;
    }

    private static function display(string $field, ?string $value): ?string
    {
        if ($field === 'displacement_status' && $value !== null) {
            return match (DisplacementStatus::tryFrom($value)) {
                DisplacementStatus::DISPLACED => 'نازحة',
                DisplacementStatus::NOT_DISPLACED => 'غير نازحة',
                null => null,
            };
        }

        return $value;
    }

    private static function maxLength(string $field): int
    {
        return $field === 'address_text' ? self::ADDRESS_TEXT_MAX : self::SHORT_TEXT_MAX;
    }
}
