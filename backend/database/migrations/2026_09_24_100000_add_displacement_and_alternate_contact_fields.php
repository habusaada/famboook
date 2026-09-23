<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/02-DATA-DICTIONARY.md §9-10, §19 and docs/04-DATABASE.md §24:
// paper-form fields for residence before displacement, current
// displacement location, and the alternate phone's owner/relation.
//
// Additive and nullable only: existing families, residences and persons
// are preserved untouched. Legacy displacement_status stays NULL ("not
// collected") — it is never back-filled to NOT_DISPLACED.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_residences', function (Blueprint $table) {
            // Family residence BEFORE displacement — not a birthplace.
            $table->string('original_residence_text')->nullable()->after('address_text');
            $table->string('displacement_location_text')->nullable()->after('displacement_status');
        });

        Schema::table('persons', function (Blueprint $table) {
            // Descriptive contact metadata only (e.g. "أحمد محمد – أخ");
            // never resolved into a Person or a relationship.
            $table->string('alternate_mobile_owner_relation')->nullable()->after('alternate_mobile');
        });

        // Postgres-only, same reason as the existing CHECK constraints
        // (see 2026_09_23_153941): SQLite cannot add CHECK constraints to
        // an existing table. Enforced in RegisterFamilyRequest /
        // RegisterFamilyAction on SQLite.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE family_residences ADD CONSTRAINT chk_residence_displacement_status '
                ."CHECK (displacement_status IS NULL OR displacement_status IN ('DISPLACED', 'NOT_DISPLACED'))"
            );
            DB::statement(
                'ALTER TABLE family_residences ADD CONSTRAINT chk_residence_displacement_location '
                ."CHECK (displacement_location_text IS NULL OR displacement_status = 'DISPLACED')"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE family_residences DROP CONSTRAINT IF EXISTS chk_residence_displacement_location');
            DB::statement('ALTER TABLE family_residences DROP CONSTRAINT IF EXISTS chk_residence_displacement_status');
        }

        Schema::table('persons', function (Blueprint $table) {
            $table->dropColumn('alternate_mobile_owner_relation');
        });

        Schema::table('family_residences', function (Blueprint $table) {
            $table->dropColumn(['original_residence_text', 'displacement_location_text']);
        });
    }
};
