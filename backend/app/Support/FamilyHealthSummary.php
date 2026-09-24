<?php

namespace App\Support;

use App\Enums\HealthRecordType;
use App\Enums\LifeStatus;
use App\Models\Family;
use App\Models\Person;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Family-level health indicators (docs/02-DATA-DICTIONARY.md §22
 * "Derived Health Indicators"). All values are derived on read, never
 * stored:
 *
 * - record-based counts are DISTINCT persons with an ACTIVE record of
 *   that type (two chronic diseases = one person);
 * - under_two / recent_births come from persons.birth_date relative to
 *   $referenceDate (today in the Staff App; a future historical form
 *   evaluation may pass the collection date instead).
 *
 * Only ACTIVE family members count. Deceased persons are excluded, and a
 * missing birth date simply does not count toward the DOB indicators.
 */
class FamilyHealthSummary
{
    /** @return array<string, int> */
    public static function for(Family $family, CarbonInterface $referenceDate): array
    {
        $persons = Person::query()
            ->whereHas('activeMembership', fn ($q) => $q->where('family_id', $family->id))
            ->where('life_status', '!=', LifeStatus::DECEASED->value)
            ->with(['healthRecords' => fn ($q) => $q->active()])
            ->get();

        return [
            'disability_persons' => self::withActive($persons, HealthRecordType::DISABILITY),
            'chronic_disease_persons' => self::withActive($persons, HealthRecordType::CHRONIC_DISEASE),
            'pregnant' => self::withActive($persons, HealthRecordType::PREGNANCY),
            'breastfeeding' => self::withActive($persons, HealthRecordType::BREASTFEEDING),
            'under_two' => $persons->filter(fn (Person $p) => self::isUnderTwo($p, $referenceDate))->count(),
            'recent_births' => $persons->filter(fn (Person $p) => self::isRecentBirth($p, $referenceDate))->count(),
        ];
    }

    /** Has not yet reached their second birthday on the reference date. */
    public static function isUnderTwo(Person $person, CarbonInterface $referenceDate): bool
    {
        $birth = $person->birth_date;

        return $birth !== null
            && $birth->lte($referenceDate)
            && $birth->gt($referenceDate->copy()->subYears(2));
    }

    /** Born within the 12 months before the reference date (first birthday excluded). */
    public static function isRecentBirth(Person $person, CarbonInterface $referenceDate): bool
    {
        $birth = $person->birth_date;

        return $birth !== null
            && $birth->lte($referenceDate)
            && $birth->gt($referenceDate->copy()->subYear());
    }

    private static function withActive(Collection $persons, HealthRecordType $type): int
    {
        return $persons
            ->filter(fn (Person $p) => $p->healthRecords->contains('type', $type))
            ->count();
    }
}
