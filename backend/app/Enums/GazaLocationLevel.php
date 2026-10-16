<?php

namespace App\Enums;

// ADR-FB-GEO-01 §3.1: Governorate → City → Area → Neighborhood → Landmark.
// Each level's parent is the level directly above; a governorate has none.
enum GazaLocationLevel: string
{
    case GOVERNORATE = 'GOVERNORATE';
    case CITY = 'CITY';
    case AREA = 'AREA';
    case NEIGHBORHOOD = 'NEIGHBORHOOD';
    case LANDMARK = 'LANDMARK';

    /** @return list<self> parent-first order */
    public static function ordered(): array
    {
        return [self::GOVERNORATE, self::CITY, self::AREA, self::NEIGHBORHOOD, self::LANDMARK];
    }

    public function parent(): ?self
    {
        return match ($this) {
            self::GOVERNORATE => null,
            self::CITY => self::GOVERNORATE,
            self::AREA => self::CITY,
            self::NEIGHBORHOOD => self::AREA,
            self::LANDMARK => self::NEIGHBORHOOD,
        };
    }
}
