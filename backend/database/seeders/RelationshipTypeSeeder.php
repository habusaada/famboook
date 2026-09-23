<?php

namespace Database\Seeders;

use App\Models\FamilyMembership;
use App\Models\RelationshipType;
use Illuminate\Database\Seeder;

/**
 * V1 operational relationship-type baseline, adopted 2026-09-23 —
 * docs/02-DATA-DICTIONARY.md §15 "V1 Operational Baseline". PDD-005
 * (final taxonomy) remains open for future review; values are deactivated,
 * never deleted, once in use.
 *
 * SPOUSE is the single canonical spouse code — no HUSBAND/WIFE codes. The
 * UI may present زوج/زوجة from the Person's gender; persistence stays SPOUSE.
 */
class RelationshipTypeSeeder extends Seeder
{
    private const TYPES = [
        ['code' => 'HEAD', 'name' => 'رب الأسرة', 'sort_order' => 1],
        ['code' => 'SPOUSE', 'name' => 'زوج/زوجة', 'sort_order' => 2],
        ['code' => 'SON', 'name' => 'ابن', 'sort_order' => 3],
        ['code' => 'DAUGHTER', 'name' => 'ابنة', 'sort_order' => 4],
        ['code' => 'FATHER', 'name' => 'أب', 'sort_order' => 5],
        ['code' => 'MOTHER', 'name' => 'أم', 'sort_order' => 6],
        ['code' => 'OTHER', 'name' => 'أخرى', 'sort_order' => 99],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $type) {
            RelationshipType::updateOrCreate(
                ['code' => $type['code']],
                [...$type, 'is_active' => true]
            );
        }

        // docs task J: existing household heads should carry the
        // canonical HEAD relationship; non-head legacy memberships with a
        // NULL relationship_type_id are left untouched — their relationship
        // is not guessed.
        $headTypeId = RelationshipType::where('code', 'HEAD')->value('id');

        if ($headTypeId !== null) {
            FamilyMembership::where('is_household_head', true)
                ->whereNull('relationship_type_id')
                ->update(['relationship_type_id' => $headTypeId]);
        }
    }
}
