<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * TEST-ONLY: simulate registry data from BEFORE the National ID uniqueness
 * migration (CO-01D.4, docs/04 DB-ADR-061) — a NULL, formatted ("401-234-567"),
 * placeholder or duplicate persons.national_id — so code that must still
 * tolerate such legacy values (legacy matching, import reconciliation,
 * identity checks) stays tested on PostgreSQL, where the constraints would
 * otherwise refuse the fixture itself.
 *
 * On PostgreSQL the migration's down() drops the constraints for this test
 * only, and they are restored before the application is destroyed: inside a
 * RefreshDatabase transaction the rollback restores them; with truncation
 * (data already removed) up() re-applies them. On SQLite nothing happens —
 * the constraints are PostgreSQL-only.
 */
trait LegacyNationalIds
{
    private const NATIONAL_ID_MIGRATION = 'migrations/2026_10_20_090000_enforce_unique_person_national_id.php';

    protected function allowLegacyNationalIds(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        (require database_path(self::NATIONAL_ID_MIGRATION))->down();

        $this->beforeApplicationDestroyed(function () {
            $present = DB::scalar("SELECT count(*) FROM pg_constraint WHERE conname = 'uq_persons_national_id'") > 0;
            if (! $present) {
                DB::transaction(fn () => (require database_path(self::NATIONAL_ID_MIGRATION))->up());
            }
        });
    }
}
