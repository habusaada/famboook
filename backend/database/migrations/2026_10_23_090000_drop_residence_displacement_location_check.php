<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// FB-GEO-02 (ADR FB-GEO-02 G2-6 as amended 2026-10-17; docs/04 §24):
// displacement_location_text is HISTORICAL data — it is kept when the
// displacement status changes away from DISPLACED. The CHECK
// chk_residence_displacement_location (text only while DISPLACED, migration
// 2026_09_24_100000) would refuse that, so it is dropped.
//
// - Schema only: no row is read, changed or backfilled.
// - Entering a NEW location text still requires DISPLACED (application
//   validation: UpdateFamilyResidenceRequest, RegisterFamilyRequest).
// - chk_residence_displacement_status is unchanged.
// - PostgreSQL only, like the CHECK itself (SQLite never had it).
// - down() restores the CHECK and refuses while any non-displaced residence
//   keeps a historical text, so a rollback never silently deletes it.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Fail fast rather than queue behind a long transaction (DB-ADR-061).
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE family_residences DROP CONSTRAINT IF EXISTS chk_residence_displacement_location');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $kept = DB::table('family_residences')
            ->whereNotNull('displacement_location_text')
            ->where(fn ($q) => $q->whereNull('displacement_status')->orWhere('displacement_status', '!=', 'DISPLACED'))
            ->count();
        if ($kept > 0) {
            throw new RuntimeException("Refusing to restore chk_residence_displacement_location: {$kept} non-displaced residence(s) keep a historical displacement_location_text.");
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement(
            'ALTER TABLE family_residences ADD CONSTRAINT chk_residence_displacement_location '
            ."CHECK (displacement_location_text IS NULL OR displacement_status = 'DISPLACED')"
        );
    }
};
