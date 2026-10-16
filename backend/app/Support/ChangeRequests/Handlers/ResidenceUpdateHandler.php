<?php

namespace App\Support\ChangeRequests\Handlers;

use App\Actions\UpdateFamilyResidenceAction;
use App\Enums\DisplacementStatus;
use App\Enums\ProfileReviewSection;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyResidence;
use App\Models\GazaLocation;
use App\Rules\OnlyKeys;
use App\Rules\SelectableGazaLocation;
use App\Support\ChangeRequests\ChangeRequestHandler;
use App\Support\ChangeRequests\ChangeRequestPresentation;
use App\Support\ChangeRequests\ChangeRequestPresentationContext;
use App\Support\ChangeRequests\ChangeRequestTarget;
use App\Support\ChangeRequests\ReadsEarlierPayloadVersions;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\Geography\GazaLocationPaths;
use App\Support\Geography\GazaLocationSelection;
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
 *
 * Payload v2 (FB-GEO-01 ADR §8.5, owner-approved 2026-10-16) adds the two
 * optional structured references original_location_id / current_location_id:
 * - input: optional keys (omitted = unchanged, so a client still sending the
 *   eight v1 fields keeps working); null clears; a UUID must exist, and a NEW
 *   one must be active — the stored value is accepted unchanged even if
 *   inactive (SelectableGazaLocation); re-checked at approve and apply;
 * - base: the eight fields plus both ids, so a Staff change of either after
 *   submission is CHANGE_REQUEST_BASE_CHANGED;
 * - presentation: names and full paths with «غير مفعّلة», never a UUID.
 * v1 requests submitted before v2 are read unchanged (never migrated): their
 * data holds only the eight fields and their base is recomputed over exactly
 * those (ReadsEarlierPayloadVersions).
 */
final class ResidenceUpdateHandler implements ChangeRequestHandler, ReadsEarlierPayloadVersions
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

    /** v2: the structured references (any level), with their Arabic labels. */
    public const LOCATION_FIELDS = [
        'original_location_id' => 'الموقع الجغرافي للسكن الأصلي',
        'current_location_id' => 'الموقع الجغرافي الحالي / مكان النزوح',
    ];

    private const SHORT_TEXT_MAX = 255;

    private const ADDRESS_TEXT_MAX = 1000;

    public function familySubmittable(): bool
    {
        return true;
    }

    public function payloadVersion(): int
    {
        return 2;
    }

    public function acceptedPayloadVersions(): array
    {
        return [1, 2];
    }

    public function inputRules(Family $family): array
    {
        $current = self::currentResidence($family);
        $statusKnown = $current?->displacement_status !== null;

        $rules = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $rules[$field] = ['present', 'nullable', 'string', 'max:'.self::maxLength($field)];
        }
        $rules['governorate'][] = new OnlyKeys([...array_keys(self::FIELDS), ...array_keys(self::LOCATION_FIELDS)]);
        foreach (array_keys(self::LOCATION_FIELDS) as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'bail', 'uuid', new SelectableGazaLocation($current?->getAttribute($field))];
        }
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
        // v2 references (absent from v1 data); activity: preconditions().
        foreach (array_keys(self::LOCATION_FIELDS) as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'uuid'];
        }

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

        // v2 references: only when sent (omitted = unchanged).
        foreach (array_keys(self::LOCATION_FIELDS) as $field) {
            if (array_key_exists($field, $validated)) {
                $proposed[$field] = GazaLocationSelection::normalize($validated[$field]);
            }
        }

        $currentValues = self::values($current, withLocations: true);
        $changed = array_filter($proposed, fn ($value, string $field) => $value !== $currentValues[$field], ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            throw ValidationException::withMessages(['data' => 'لم تغيّر أي بيانات في السكن الحالي.']);
        }

        return $changed;
    }

    public function baseValues(ChangeRequestTarget $target, array $data): array
    {
        return $this->baseValuesForVersion($this->payloadVersion(), $target, $data);
    }

    /** v1: the residence and its eight fields (as taken at v1 submission); v2: plus both ids. */
    public function baseValuesForVersion(int $payloadVersion, ChangeRequestTarget $target, array $data): array
    {
        $current = FamilyResidence::query()
            ->where('family_id', $target->family->getKey())
            ->where('is_current', true)
            ->lockForUpdate()
            ->first();

        return ['residence' => $current?->getKey(), ...($current === null ? [] : self::values($current, withLocations: $payloadVersion >= 2))];
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

        // A NEW reference must still exist and be active (it may have been
        // deactivated since submission); an unchanged one is kept as is.
        foreach (array_keys(self::LOCATION_FIELDS) as $field) {
            $proposed = array_key_exists($field, $data) ? GazaLocationSelection::normalize($data[$field]) : null;
            if ($proposed !== null && $proposed !== $current->getAttribute($field)
                && ! GazaLocation::query()->whereKey($proposed)->where('is_active', true)->exists()) {
                throw new ChangeRequestException(ChangeRequestException::PRECONDITION_FAILED);
            }
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
        $currentValues = $current === null ? [] : self::values($current, withLocations: true);

        $presentation = ChangeRequestPresentation::make();
        foreach (self::FIELDS as $field => $label) {
            if (! array_key_exists($field, $request->submitted_data)) {
                continue;
            }
            $presentation->row($label, self::display($field, $currentValues[$field] ?? null), self::display($field, $request->submitted_data[$field]));
        }
        // v2 references: names and full paths, never a UUID.
        foreach (self::LOCATION_FIELDS as $field => $label) {
            if (! array_key_exists($field, $request->submitted_data)) {
                continue;
            }
            $presentation->row($label, self::locationText($currentValues[$field] ?? null), self::locationText($request->submitted_data[$field]));
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

    /** @return array<string, ?string> the eight fields as stored (status as its code), plus the v2 references when asked */
    private static function values(FamilyResidence $residence, bool $withLocations = false): array
    {
        $fields = array_keys(self::FIELDS);
        if ($withLocations) {
            $fields = [...$fields, ...array_keys(self::LOCATION_FIELDS)];
        }
        $values = [];
        foreach ($fields as $field) {
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

    /** «المحافظة ‹ … ‹ الموقع», inactive items marked; null when not recorded. Never the UUID. */
    private static function locationText(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $location = GazaLocation::query()->find(GazaLocationSelection::normalize($id));
        if ($location === null) {
            return 'موقع غير متاح';
        }
        GazaLocationPaths::attach(collect([$location]));

        return collect([...$location->path, $location])
            ->map(fn (GazaLocation $item) => $item->displayName().($item->is_active ? '' : ' (غير مفعّلة)'))
            ->implode(' ‹ ');
    }

    private static function maxLength(string $field): int
    {
        return $field === 'address_text' ? self::ADDRESS_TEXT_MAX : self::SHORT_TEXT_MAX;
    }
}
