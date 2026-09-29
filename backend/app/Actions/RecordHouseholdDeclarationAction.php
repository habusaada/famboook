<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Enums\RegistrationSource;
use App\Models\Family;
use App\Models\FamilyHouseholdDeclaration;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Records a new Declared Household Statistics entry for a Family (docs/03
 * §55c) and makes it the current one. The previous current declaration is
 * kept as history (is_current = false), never edited or deleted, so the
 * values behind past eligibility/assistance decisions survive.
 *
 * Declared values are accepted as declared: consistency with the
 * registered members (e.g. size below 1 + spouses) is a review flag for
 * the caller, not a refusal here. They never create Persons and never
 * replace the derived Registered Household Size.
 *
 * HOUSEHOLD_DECLARATION_RECORDED is recorded with no metadata (never the
 * declared counts).
 */
class RecordHouseholdDeclarationAction
{
    private const COUNT_RULES = ['nullable', 'integer', 'min:0', 'max:32767'];

    private const COUNTS = ['declared_household_size', 'declared_living_sons', 'declared_living_daughters'];

    /**
     * @param  array{
     *     declared_household_size?: int|null,
     *     declared_living_sons?: int|null,
     *     declared_living_daughters?: int|null,
     *     declared_at?: string|null,
     *     source: string,
     *     notes?: string|null,
     * }  $data
     */
    public function handle(Family $family, array $data, ?int $actingUserId): FamilyHouseholdDeclaration
    {
        // Mirrors the Postgres CHECK constraints for every write path.
        $data = Validator::make($data, [
            'declared_household_size' => self::COUNT_RULES,
            'declared_living_sons' => self::COUNT_RULES,
            'declared_living_daughters' => self::COUNT_RULES,
            'declared_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'source' => ['required', Rule::enum(RegistrationSource::class)],
            'notes' => ['nullable', 'string'],
        ])->validate();

        if (collect(self::COUNTS)->every(fn (string $key) => ($data[$key] ?? null) === null)) {
            throw ValidationException::withMessages([
                'declared_household_size' => 'يجب إدخال قيمة معلنة واحدة على الأقل.',
            ]);
        }

        return DB::transaction(function () use ($family, $data, $actingUserId) {
            // Serializes concurrent declarations for the same Family.
            Family::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();

            FamilyHouseholdDeclaration::query()
                ->where('family_id', $family->id)
                ->where('is_current', true)
                ->update(['is_current' => false, 'updated_by' => $actingUserId, 'updated_at' => now()]);

            $declaration = FamilyHouseholdDeclaration::create([
                'family_id' => $family->id,
                'declared_household_size' => $data['declared_household_size'] ?? null,
                'declared_living_sons' => $data['declared_living_sons'] ?? null,
                'declared_living_daughters' => $data['declared_living_daughters'] ?? null,
                'declared_at' => $data['declared_at'] ?? null,
                'source' => $data['source'],
                'is_current' => true,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actingUserId,
                'updated_by' => $actingUserId,
            ]);

            FamilyActivityLog::record($family->id, FamilyActivityType::HOUSEHOLD_DECLARATION_RECORDED, $family, $actingUserId);

            return $declaration;
        });
    }
}
