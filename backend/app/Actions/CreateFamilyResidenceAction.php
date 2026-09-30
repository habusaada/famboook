<?php

namespace App\Actions;

use App\Enums\DisplacementStatus;
use App\Enums\RegistrationSource;
use App\Models\Family;
use App\Models\FamilyResidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical creation of a Family's FIRST residence record (docs/02 §19,
 * docs/03 §96b) — an internal building block. RegisterFamilyAction uses it;
 * a future import Apply will create the import form with it:
 * original_residence_text only, source IMPORT, every current-location and
 * displacement field NULL (nothing is inferred from the original city).
 *
 * - One current residence per Family: an existing current record is
 *   refused (the partial unique index is the final backstop). Changing an
 *   existing residence stays UpdateFamilyResidenceAction.
 * - A displacement location is kept only for a DISPLACED family.
 * - source, when given, is a RegistrationSource value.
 * - No activity entry: the calling Family action records its own event.
 */
class CreateFamilyResidenceAction
{
    private const FIELDS = [
        'residence_type', 'governorate', 'city', 'area', 'neighborhood', 'address_text',
        'original_residence_text', 'latitude', 'longitude', 'displacement_status',
    ];

    /** @param array<string, mixed> $data */
    public function handle(Family $family, array $data, string $startedAt, ?int $actingUserId): FamilyResidence
    {
        $source = $data['source'] ?? null;
        if ($source !== null && RegistrationSource::tryFrom($source) === null) {
            throw ValidationException::withMessages(['source' => 'مصدر السكن غير صالح.']);
        }

        return DB::transaction(function () use ($family, $data, $startedAt, $actingUserId, $source) {
            Family::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
            if (FamilyResidence::query()->where('family_id', $family->id)->where('is_current', true)->exists()) {
                throw ValidationException::withMessages(['residence' => 'للأسرة سكن حالي مسجّل.']);
            }

            $values = [];
            foreach (self::FIELDS as $field) {
                $values[$field] = $data[$field] ?? null;
            }
            $isDisplaced = $values['displacement_status'] === DisplacementStatus::DISPLACED->value;

            return FamilyResidence::create([
                'family_id' => $family->id,
                ...$values,
                // A displacement location only exists for a displaced family.
                'displacement_location_text' => $isDisplaced ? ($data['displacement_location_text'] ?? null) : null,
                'source' => $source,
                'started_at' => $startedAt,
                'is_current' => true,
                'created_by' => $actingUserId,
                'updated_by' => $actingUserId,
            ]);
        });
    }
}
