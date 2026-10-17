<?php

namespace Tests\Feature\Families;

use App\Enums\DisplacementStatus;
use App\Models\FamilyResidence;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * FB-GEO-02 (ADR G2-6): migration 2026_10_23_090000 drops the PostgreSQL CHECK
 * chk_residence_displacement_location so a historical displacement_location_text
 * survives a status change. The status CHECK stays; the rollback refuses while
 * history exists. PostgreSQL only (SQLite never had either CHECK). Synthetic data.
 */
class ResidenceDisplacementHistoryMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_23_090000_drop_residence_displacement_location_check.php';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The residence displacement CHECK constraints are PostgreSQL-only.');
        }
    }

    private function constraintExists(string $name): bool
    {
        return DB::table('pg_constraint')
            ->where('conname', $name)
            ->where('conrelid', DB::raw("'family_residences'::regclass"))
            ->exists();
    }

    private function assertRejected(callable $write): void
    {
        try {
            DB::transaction($write); // a savepoint: the test transaction stays usable
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Expected the database to reject the write.');
    }

    public function test_the_location_check_is_dropped_and_the_status_check_kept(): void
    {
        $this->assertFalse($this->constraintExists('chk_residence_displacement_location'));
        $this->assertTrue($this->constraintExists('chk_residence_displacement_status'));

        $residence = FamilyResidence::factory()->create([
            'displacement_status' => DisplacementStatus::DISPLACED, 'displacement_location_text' => 'مواصي خانيونس',
        ]);

        // The database itself now keeps the historical text under NOT_DISPLACED / unknown.
        DB::table('family_residences')->where('id', $residence->id)->update(['displacement_status' => 'NOT_DISPLACED']);
        DB::table('family_residences')->where('id', $residence->id)->update(['displacement_status' => null]);
        $this->assertSame('مواصي خانيونس', $residence->fresh()->displacement_location_text);

        // The status domain is still enforced.
        $this->assertRejected(fn () => DB::table('family_residences')->where('id', $residence->id)->update(['displacement_status' => 'MOVED']));
    }

    public function test_the_rollback_refuses_while_history_exists_and_round_trips_otherwise(): void
    {
        $migration = require database_path(self::MIGRATION);
        $residence = FamilyResidence::factory()->create([
            'displacement_status' => DisplacementStatus::NOT_DISPLACED, 'displacement_location_text' => 'مواصي خانيونس',
        ]);

        try {
            $migration->down();
            $this->fail('Expected the rollback to refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 non-displaced residence(s)', $e->getMessage());
        }
        $this->assertFalse($this->constraintExists('chk_residence_displacement_location'));
        $this->assertSame('مواصي خانيونس', $residence->fresh()->displacement_location_text, 'nothing deleted');

        // Once no history remains (a deliberate, explicit decision), down() restores the CHECK …
        DB::table('family_residences')->where('id', $residence->id)->update(['displacement_location_text' => null]);
        $migration->down();
        $this->assertTrue($this->constraintExists('chk_residence_displacement_location'));
        $this->assertRejected(fn () => DB::table('family_residences')->where('id', $residence->id)->update(['displacement_location_text' => 'نص']));

        // … and up() drops it again.
        $migration->up();
        $this->assertFalse($this->constraintExists('chk_residence_displacement_location'));
    }
}
