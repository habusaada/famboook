<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/02-DATA-DICTIONARY.md §20a / docs/04-DATABASE.md §25a "Declared
// Household Statistics". Source-declared, dated values — distinct from the
// Registered Household Size, which stays derived from active memberships
// and is never stored (docs/02 §75). Declared counts never create Persons.
// Additive only: no existing table or row is touched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_household_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained('families')->restrictOnDelete();
            // NULL = not declared by the source (never 0 as a placeholder).
            $table->smallInteger('declared_household_size')->nullable();
            $table->smallInteger('declared_living_sons')->nullable();
            $table->smallInteger('declared_living_daughters')->nullable();
            // When the source made the declaration; NULL when not known.
            $table->date('declared_at')->nullable();
            // PAPER_FORM | MANUAL_ENTRY | IMPORT | VERIFIED_SOURCE
            $table->string('source');
            $table->boolean('is_current')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('family_id');
        });

        // One current declaration per Family; earlier ones stay as history
        // (same pattern as uq_family_current_residence).
        DB::statement(
            'CREATE UNIQUE INDEX uq_family_current_household_declaration '
            .'ON family_household_declarations (family_id) WHERE is_current = true'
        );

        // Postgres-only, as for the existing CHECK constraints: SQLite (test
        // suite) cannot add them after creation. The same rules are enforced
        // in RecordHouseholdDeclarationAction.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE family_household_declarations ADD CONSTRAINT chk_household_declaration_counts CHECK ('
                .'(declared_household_size IS NULL OR declared_household_size >= 0) AND '
                .'(declared_living_sons IS NULL OR declared_living_sons >= 0) AND '
                .'(declared_living_daughters IS NULL OR declared_living_daughters >= 0))'
            );
            DB::statement(
                'ALTER TABLE family_household_declarations ADD CONSTRAINT chk_household_declaration_not_empty CHECK ('
                .'declared_household_size IS NOT NULL OR declared_living_sons IS NOT NULL '
                .'OR declared_living_daughters IS NOT NULL)'
            );
            DB::statement(
                'ALTER TABLE family_household_declarations ADD CONSTRAINT chk_household_declaration_source '
                ."CHECK (source IN ('PAPER_FORM', 'MANUAL_ENTRY', 'IMPORT', 'VERIFIED_SOURCE'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('family_household_declarations');
    }
};
