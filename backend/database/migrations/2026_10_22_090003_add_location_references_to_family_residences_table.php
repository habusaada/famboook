<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FB-GEO-01 Stage 3.2 (ADR-FB-GEO-01 §4.2, Stage 3.1 audit D-1, D-6, D-8;
// docs/04 §24, DB-ADR-066): the residence's structured references to the
// Gaza geographic reference, beside the free text they describe.
//
// - original_location_id: the residence BEFORE displacement.
// - current_location_id: where the Family lives now — the displacement
//   location when DISPLACED. Valid for every displacement status; there is
//   no displacement_location_id.
// - Both nullable UUID, FK gaza_locations RESTRICT, one index each. No
//   CHECK: any level may be selected and "active" is application-validated.
// - Additive only: no existing column, CHECK, index or row changes; NO
//   backfill (nothing is inferred from text, ADR D10).
// - down() refuses while any residence references a location, so a
//   rollback never silently drops linkage data.
return new class extends Migration
{
    private const COLUMNS = ['original_location_id', 'current_location_id'];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Fail fast rather than queue behind a long transaction (DB-ADR-061).
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        $indexes = $this->sqliteIndexes();
        Schema::table('family_residences', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->foreignUuid($column)->nullable()->constrained('gaza_locations')->restrictOnDelete();
                $table->index($column);
            }
        });
        $this->restoreSqliteIndexes($indexes);
    }

    /**
     * SQLite (tests) cannot add a foreign key to an existing table, so the
     * schema builder rebuilds the table — and recreates its indexes WITHOUT
     * their WHERE clause, turning the partial uq_family_current_residence
     * ("one current residence per Family") into a plain unique index on
     * family_id. The original definitions are captured before the rebuild
     * and restored exactly afterwards. PostgreSQL alters in place (no-op).
     *
     * @return array<string, string> index name => CREATE statement
     */
    private function sqliteIndexes(): array
    {
        if (DB::getDriverName() !== 'sqlite') {
            return [];
        }

        return DB::table('sqlite_master')
            ->where('type', 'index')
            ->where('tbl_name', 'family_residences')
            ->whereNotNull('sql')
            ->pluck('sql', 'name')
            ->all();
    }

    /** @param array<string, string> $indexes */
    private function restoreSqliteIndexes(array $indexes): void
    {
        foreach ($indexes as $name => $sql) {
            DB::statement('DROP INDEX IF EXISTS "'.str_replace('"', '""', $name).'"');
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        $linked = (int) DB::table('family_residences')
            ->where(fn ($q) => $q->whereNotNull('original_location_id')->orWhereNotNull('current_location_id'))
            ->count();
        if ($linked > 0) {
            throw new RuntimeException(sprintf(
                'Cannot roll back: %d residence(s) reference a Gaza location. Clear the references deliberately first. No data was changed.',
                $linked,
            ));
        }

        // The two location indexes go away with their columns; every other
        // index is restored exactly (SQLite rebuild, see sqliteIndexes()).
        $indexes = array_filter(
            $this->sqliteIndexes(),
            fn (string $name) => ! in_array($name, array_map(fn ($c) => "family_residences_{$c}_index", self::COLUMNS), true),
            ARRAY_FILTER_USE_KEY,
        );
        Schema::table('family_residences', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->dropForeign([$column]);
                $table->dropIndex([$column]);
            }
            $table->dropColumn(self::COLUMNS);
        });
        $this->restoreSqliteIndexes($indexes);
    }
};
