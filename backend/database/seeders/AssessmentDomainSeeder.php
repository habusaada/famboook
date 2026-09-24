<?php

namespace Database\Seeders;

use App\Models\AssessmentDomain;
use Illuminate\Database\Seeder;

/**
 * V1 assessment-domain baseline — docs/02-DATA-DICTIONARY.md §27a
 * "Assessment Domains". Values are deactivated, never deleted, once in use.
 */
class AssessmentDomainSeeder extends Seeder
{
    private const DOMAINS = [
        ['code' => 'SHELTER', 'name' => 'السكن والمأوى', 'sort_order' => 1],
        ['code' => 'FOOD', 'name' => 'الغذاء', 'sort_order' => 2],
        ['code' => 'WASH', 'name' => 'المياه والصرف الصحي والنظافة', 'sort_order' => 3],
        ['code' => 'HEALTH', 'name' => 'الصحة', 'sort_order' => 4],
        ['code' => 'EDUCATION', 'name' => 'التعليم', 'sort_order' => 5],
        ['code' => 'ECONOMIC', 'name' => 'الوضع الاقتصادي', 'sort_order' => 6],
        ['code' => 'PROTECTION', 'name' => 'الحماية', 'sort_order' => 7],
        ['code' => 'SPECIAL_NEEDS', 'name' => 'الاحتياجات الخاصة', 'sort_order' => 8],
    ];

    public function run(): void
    {
        // Re-running never reactivates a domain an administrator deactivated.
        foreach (self::DOMAINS as $domain) {
            AssessmentDomain::firstOrCreate(
                ['code' => $domain['code']],
                [...$domain, 'is_active' => true]
            );
        }
    }
}
