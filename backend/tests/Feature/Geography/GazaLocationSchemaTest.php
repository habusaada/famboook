<?php

namespace Tests\Feature\Geography;

use App\Enums\GazaLocationLevel;
use App\Models\GazaLocation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * gaza_locations / gaza_location_review_flags constraints (FB-GEO-01 Stage
 * 1C, docs/04 §24a). Keys and the composite parent FK hold on SQLite and
 * PostgreSQL; the level CHECKs are PostgreSQL-only. Synthetic values.
 */
class GazaLocationSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function node(GazaLocationLevel $level, string $code, ?GazaLocation $parent = null, array $overrides = []): array
    {
        return array_merge([
            'id' => GazaLocation::idFor($level, $code),
            'level' => $level->value,
            'source_code' => $code,
            'name' => "اسم {$code}",
            'parent_id' => $parent?->id,
            'parent_level' => $parent?->level->value,
            'landmark_type_code' => $level === GazaLocationLevel::LANDMARK ? '76' : null,
            'landmark_type_name' => $level === GazaLocationLevel::LANDMARK ? 'مصلى' : null,
            'is_active' => true,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $overrides);
    }

    private function insert(array $row): GazaLocation
    {
        DB::table('gaza_locations')->insert($row);

        return GazaLocation::findOrFail($row['id']);
    }

    private function assertRejected(callable $write): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Expected the database to reject the write.');
    }

    private function chain(): array
    {
        $gov = $this->insert($this->node(GazaLocationLevel::GOVERNORATE, '1'));
        $city = $this->insert($this->node(GazaLocationLevel::CITY, '10', $gov));
        $area = $this->insert($this->node(GazaLocationLevel::AREA, '10', $city));

        return [$gov, $city, $area];
    }

    public function test_source_codes_are_unique_per_level_only(): void
    {
        [$gov, $city, $area] = $this->chain();
        $this->assertSame('10', $city->source_code);
        $this->assertSame('10', $area->source_code);

        $this->assertRejected(fn () => DB::table('gaza_locations')->insert(
            $this->node(GazaLocationLevel::CITY, '10', $gov, ['id' => (string) Str::uuid()]),
        ));
    }

    public function test_the_parent_level_must_be_the_parents_real_level(): void
    {
        [$gov, $city] = $this->chain();

        // Claims a GOVERNORATE parent but points at a CITY: the composite FK fails.
        $this->assertRejected(fn () => DB::table('gaza_locations')->insert(
            $this->node(GazaLocationLevel::CITY, '11', null, ['parent_id' => $city->id, 'parent_level' => 'GOVERNORATE']),
        ));
    }

    public function test_a_parent_with_children_cannot_be_deleted(): void
    {
        [$gov] = $this->chain();

        $this->assertRejected(fn () => DB::table('gaza_locations')->where('id', $gov->id)->delete());
    }

    public function test_a_location_with_review_flags_cannot_be_deleted_and_flags_are_unique_per_issue(): void
    {
        [, , $area] = $this->chain();
        $flag = [
            'gaza_location_id' => $area->id, 'issue_code' => 'NAME_WHITESPACE', 'severity' => 'INFO',
            'source_reference' => 'test', 'review_status' => 'OPEN', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ];
        DB::table('gaza_location_review_flags')->insert(['id' => (string) Str::uuid7()] + $flag);

        $this->assertRejected(fn () => DB::table('gaza_location_review_flags')->insert(['id' => (string) Str::uuid7()] + $flag));
        $this->assertRejected(fn () => DB::table('gaza_locations')->where('id', $area->id)->delete());
    }

    public function test_postgresql_checks_enforce_levels_parents_and_landmark_types(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The gaza_locations CHECK constraints are PostgreSQL-only.');
        }
        [$gov, $city, $area] = $this->chain();
        $nei = $this->insert($this->node(GazaLocationLevel::NEIGHBORHOOD, '100', $area));

        // A level skipped: a neighborhood directly under a city.
        $this->assertRejected(fn () => DB::table('gaza_locations')->insert(
            $this->node(GazaLocationLevel::NEIGHBORHOOD, '101', $city),
        ));
        // A non-governorate without a parent.
        $this->assertRejected(fn () => DB::table('gaza_locations')->insert($this->node(GazaLocationLevel::CITY, '12')));
        // A governorate with a parent.
        $this->assertRejected(fn () => DB::table('gaza_locations')->insert(
            $this->node(GazaLocationLevel::GOVERNORATE, '2', null, ['parent_id' => $gov->id, 'parent_level' => 'GOVERNORATE']),
        ));
        // Unknown level.
        $this->assertRejected(fn () => DB::table('gaza_locations')->insert(
            $this->node(GazaLocationLevel::GOVERNORATE, '3', null, ['level' => 'REGION']),
        ));
        // A landmark without a type; a neighborhood with one.
        $this->assertRejected(fn () => DB::table('gaza_locations')->insert(
            $this->node(GazaLocationLevel::LANDMARK, '1', $nei, ['landmark_type_code' => null]),
        ));
        $this->assertRejected(fn () => DB::table('gaza_locations')->insert(
            $this->node(GazaLocationLevel::NEIGHBORHOOD, '102', $area, ['landmark_type_code' => '76', 'landmark_type_name' => 'مصلى']),
        ));
        // A valid landmark is accepted.
        $this->insert($this->node(GazaLocationLevel::LANDMARK, '1', $nei));
        $this->assertSame(5, GazaLocation::count());

        // Review flag severity / status CHECKs.
        $this->assertRejected(fn () => DB::table('gaza_location_review_flags')->insert([
            'id' => (string) Str::uuid7(), 'gaza_location_id' => $nei->id, 'issue_code' => 'X', 'severity' => 'BLOCKER',
            'source_reference' => 'test', 'review_status' => 'OPEN', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]));
    }
}
