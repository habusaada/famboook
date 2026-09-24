<?php

namespace Database\Seeders;

use App\Models\DisabilityType;
use Illuminate\Database\Seeder;

/**
 * V1 operational disability-type baseline — docs/02-DATA-DICTIONARY.md
 * §22 "Disability Types". PDD-008 (final disability taxonomy) remains open;
 * values are deactivated, never deleted, once in use.
 */
class DisabilityTypeSeeder extends Seeder
{
    private const TYPES = [
        ['code' => 'MOTOR', 'name' => 'حركية', 'sort_order' => 1],
        ['code' => 'VISUAL', 'name' => 'بصرية', 'sort_order' => 2],
        ['code' => 'HEARING', 'name' => 'سمعية', 'sort_order' => 3],
        ['code' => 'SPEECH_COMMUNICATION', 'name' => 'نطق / تواصل', 'sort_order' => 4],
        ['code' => 'INTELLECTUAL', 'name' => 'ذهنية / عقلية', 'sort_order' => 5],
        ['code' => 'MULTIPLE', 'name' => 'متعددة', 'sort_order' => 6],
        ['code' => 'OTHER', 'name' => 'أخرى', 'sort_order' => 99],
    ];

    public function run(): void
    {
        // Re-running never reactivates a type an administrator deactivated.
        foreach (self::TYPES as $type) {
            DisabilityType::firstOrCreate(
                ['code' => $type['code']],
                [...$type, 'is_active' => true]
            );
        }
    }
}
