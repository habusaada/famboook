<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-FB-GEO-01 §4.1 / docs/04 §24a (FB-GEO-01 Stage 1C, DB-ADR-064): the
// unified Gaza geographic reference, one self-referencing table for
// GOVERNORATE → CITY → AREA → NEIGHBORHOOD → LANDMARK.
//
// - UUID primary key (Stage 1B D-7); the official MoSD code is kept verbatim
//   in source_code and is unique per level only (Stage 1A: 134 codes repeat
//   across levels), hence UNIQUE (level, source_code).
// - Parent integrity: (parent_id, parent_level) references (id, level), so
//   the stored parent level is the parent's real level; the PostgreSQL CHECK
//   requires it to be the level directly above. SQLite enforces the
//   composite FK; the level rules are enforced by ImportGazaGeographyAction.
// - Names are stored exactly as supplied (leading/trailing spaces included);
//   display trimming is a presentation concern (Stage 1B G5).
// - Additive only: no existing table, family or residence is touched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gaza_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('level', 20);
            // Official source code as text (the workbook stores integers).
            $table->string('source_code', 20);
            $table->string('name', 255);
            $table->uuid('parent_id')->nullable();
            $table->string('parent_level', 20)->nullable();
            // LANDMARK only (LAN_TYPE / LAN_TYPE_TITLE), kept as supplied.
            $table->string('landmark_type_code', 20)->nullable();
            $table->string('landmark_type_name', 255)->nullable();
            // Imported active; never reactivated by a re-import.
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->nullable();
            $table->timestamps();

            $table->unique(['level', 'source_code']);
            // Target of the parent composite key below.
            $table->unique(['id', 'level']);
            $table->foreign(['parent_id', 'parent_level'], 'fk_gaza_locations_parent')
                ->references(['id', 'level'])->on('gaza_locations')->restrictOnDelete();
            $table->index('parent_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE gaza_locations ADD CONSTRAINT chk_gaza_location_level '
                ."CHECK (level IN ('GOVERNORATE', 'CITY', 'AREA', 'NEIGHBORHOOD', 'LANDMARK'))"
            );
            DB::statement(
                'ALTER TABLE gaza_locations ADD CONSTRAINT chk_gaza_location_parent CHECK ('
                ."(level = 'GOVERNORATE' AND parent_id IS NULL AND parent_level IS NULL)"
                ." OR (level = 'CITY' AND parent_id IS NOT NULL AND parent_level = 'GOVERNORATE')"
                ." OR (level = 'AREA' AND parent_id IS NOT NULL AND parent_level = 'CITY')"
                ." OR (level = 'NEIGHBORHOOD' AND parent_id IS NOT NULL AND parent_level = 'AREA')"
                ." OR (level = 'LANDMARK' AND parent_id IS NOT NULL AND parent_level = 'NEIGHBORHOOD'))"
            );
            DB::statement(
                'ALTER TABLE gaza_locations ADD CONSTRAINT chk_gaza_location_landmark_type CHECK ('
                ."(level = 'LANDMARK' AND landmark_type_code IS NOT NULL AND landmark_type_name IS NOT NULL)"
                ." OR (level <> 'LANDMARK' AND landmark_type_code IS NULL AND landmark_type_name IS NULL))"
            );
            DB::statement(
                'ALTER TABLE gaza_locations ADD CONSTRAINT chk_gaza_location_source_code '
                ."CHECK (source_code <> '' AND name <> '')"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gaza_locations');
    }
};
