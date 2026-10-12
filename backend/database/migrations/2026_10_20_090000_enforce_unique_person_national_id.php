<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// National ID uniqueness (CO-01D.4, docs/04 DB-ADR-061, docs/03 "National ID
// uniqueness"). On PostgreSQL, persons.national_id becomes:
//
//   NOT NULL                        every Person has one
//   chk_person_national_id_format   exactly nine ASCII digits, never nine
//                                   identical digits (a placeholder)
//   uq_persons_national_id          UNIQUE over EVERY row — no deleted_at
//                                   predicate: a soft-deleted Person keeps its
//                                   National ID
//
// The format CHECK is what makes a direct UNIQUE index sufficient: only the
// canonical form can be stored, so an equivalently formatted value (Arabic
// digits, spaces, dashes) can never sit beside its canonical twin. The plain
// persons_national_id_index is replaced by the unique index.
//
// SAFE BY DESIGN: this migration never changes a Person. A pre-flight counts
// missing, non-canonical, placeholder and duplicate values (soft-deleted rows
// included) and refuses — counts only, never a value — until
// `php artisan famboook:national-id-audit` is clean. The table is locked
// (ACCESS EXCLUSIVE, lock_timeout 5s) for the few statements, so nothing can
// slip in between the check and the constraints; PostgreSQL validates every
// row again when the constraints are added. One transaction: all or nothing.
//
// SQLite (test suite) is skipped, as for every CHECK in this repository; the
// application enforces the same rule (RegistryNationalId, NationalIdGuard).
return new class extends Migration
{
    private const FORMAT = "national_id ~ '^[0-9]{9}$' AND national_id !~ '^([0-9])\\1{8}$'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Fail fast rather than queue: a lock that cannot be had within 5s
        // (a long transaction on persons) aborts the migration — nothing
        // changed — instead of stalling every request queued behind it.
        DB::statement("SET LOCAL lock_timeout = '5s'");
        // ACCESS EXCLUSIVE once, up front: the ALTERs need it anyway, and a
        // weaker lock upgraded later could deadlock with a reader turned
        // writer. Held for milliseconds on a registry of this size.
        DB::statement('LOCK TABLE persons IN ACCESS EXCLUSIVE MODE');

        $missing = (int) DB::scalar('SELECT count(*) FROM persons WHERE national_id IS NULL');
        $invalid = (int) DB::scalar('SELECT count(*) FROM persons WHERE national_id IS NOT NULL AND NOT ('.self::FORMAT.')');
        $duplicateGroups = (int) DB::scalar(
            'SELECT count(*) FROM (SELECT national_id FROM persons WHERE national_id IS NOT NULL GROUP BY national_id HAVING count(*) > 1) d'
        );
        if ($missing + $invalid + $duplicateGroups > 0) {
            throw new RuntimeException(sprintf(
                'Cannot enforce National ID uniqueness: %d missing, %d not stored as nine digits (or placeholder), %d duplicate group(s), soft-deleted Persons included. '
                .'Run `php artisan famboook:national-id-audit` and resolve every finding first. No data was changed.',
                $missing, $invalid, $duplicateGroups,
            ));
        }

        DB::statement('ALTER TABLE persons ALTER COLUMN national_id SET NOT NULL');
        DB::statement('ALTER TABLE persons ADD CONSTRAINT chk_person_national_id_format CHECK ('.self::FORMAT.')');
        DB::statement('ALTER TABLE persons ADD CONSTRAINT uq_persons_national_id UNIQUE (national_id)');
        DB::statement('DROP INDEX IF EXISTS persons_national_id_index');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Restores the previous schema exactly; no row is touched.
        DB::statement('CREATE INDEX IF NOT EXISTS persons_national_id_index ON persons (national_id)');
        DB::statement('ALTER TABLE persons DROP CONSTRAINT IF EXISTS uq_persons_national_id');
        DB::statement('ALTER TABLE persons DROP CONSTRAINT IF EXISTS chk_person_national_id_format');
        DB::statement('ALTER TABLE persons ALTER COLUMN national_id DROP NOT NULL');
    }
};
