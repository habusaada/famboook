<?php

namespace Database\Seeders;

use App\Models\AssistanceCategory;
use Illuminate\Database\Seeder;

/**
 * V1 assistance-category baseline — docs/02-DATA-DICTIONARY.md §36b
 * "Assistance Categories". Technically independent of need_categories
 * even though the initial taxonomy is similar. Deactivated, never deleted.
 */
class AssistanceCategorySeeder extends Seeder
{
    private const CATEGORIES = [
        ['code' => 'SHELTER', 'name' => 'المأوى والسكن', 'sort_order' => 1],
        ['code' => 'FOOD', 'name' => 'الغذاء', 'sort_order' => 2],
        ['code' => 'WATER', 'name' => 'المياه', 'sort_order' => 3],
        ['code' => 'HYGIENE', 'name' => 'النظافة والصرف الصحي', 'sort_order' => 4],
        ['code' => 'HEALTHCARE', 'name' => 'الرعاية الصحية', 'sort_order' => 5],
        ['code' => 'MEDICATION', 'name' => 'الأدوية', 'sort_order' => 6],
        ['code' => 'ASSISTIVE_DEVICE', 'name' => 'الأجهزة والمستلزمات المساعدة', 'sort_order' => 7],
        ['code' => 'EDUCATION', 'name' => 'التعليم', 'sort_order' => 8],
        ['code' => 'CASH', 'name' => 'المساعدة النقدية', 'sort_order' => 9],
        ['code' => 'CLOTHING', 'name' => 'الملابس', 'sort_order' => 10],
        ['code' => 'CHILDCARE', 'name' => 'احتياجات الأطفال', 'sort_order' => 11],
        ['code' => 'PROTECTION', 'name' => 'الحماية', 'sort_order' => 12],
        ['code' => 'LIVELIHOOD', 'name' => 'سبل العيش', 'sort_order' => 13],
        ['code' => 'OTHER', 'name' => 'أخرى', 'sort_order' => 99],
    ];

    public function run(): void
    {
        // Re-running never reactivates a category an administrator deactivated.
        foreach (self::CATEGORIES as $category) {
            AssistanceCategory::firstOrCreate(
                ['code' => $category['code']],
                [...$category, 'is_active' => true]
            );
        }
    }
}
