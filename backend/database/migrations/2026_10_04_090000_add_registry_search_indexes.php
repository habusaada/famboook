<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registry search (docs/03 §93a, docs/04 §19): trigram GIN indexes for the
 * plain case-insensitive "contains" (ILIKE '%term%') searches on Person
 * name / code and Family code. Measured on 100,000 synthetic names: a
 * selective search fell from ~400–600 ms (sequential scan) to ~5–8 ms.
 * pg_trgm is a trusted contrib extension; it does not normalize Arabic
 * (PDD-003 stays open). PostgreSQL only; SQLite (tests) scans.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_persons_full_name_trgm ON persons USING gin (full_name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_persons_person_code_trgm ON persons USING gin (person_code gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_families_family_code_trgm ON families USING gin (family_code gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS ix_families_family_code_trgm');
        DB::statement('DROP INDEX IF EXISTS ix_persons_person_code_trgm');
        DB::statement('DROP INDEX IF EXISTS ix_persons_full_name_trgm');
        // The extension is left installed: dropping it could break other objects.
    }
};
