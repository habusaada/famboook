<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §19-22: family_memberships table, partial unique
// indexes, and date-integrity constraint.
//
// relationship_type_id is intentionally omitted: it references the
// relationship_types reference table, out of scope for this slice
// (docs/07-ROADMAP.md Phase 7 — Reference Data). Nullable per docs/02
// §14, so omitting it does not violate any documented invariant.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained('families')->restrictOnDelete();
            $table->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            $table->boolean('is_household_head')->default(false);
            $table->integer('paper_sequence_no')->nullable();
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('end_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // docs/04-DATABASE.md §20: one active primary Family membership per Person.
        DB::statement(
            'CREATE UNIQUE INDEX uq_person_active_family_membership '
            .'ON family_memberships (person_id) WHERE is_active = true'
        );

        // docs/04-DATABASE.md §21: one active Household Head per Family.
        DB::statement(
            'CREATE UNIQUE INDEX uq_family_active_household_head '
            .'ON family_memberships (family_id) WHERE is_active = true AND is_household_head = true'
        );

        // docs/04-DATABASE.md §22: membership date integrity. Postgres-only
        // for the same reason as the persons death/birth check — SQLite
        // (test suite) cannot add CHECK constraints post-creation. Enforced
        // at application level in RegisterFamilyAction/RegisterFamilyRequest.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE family_memberships ADD CONSTRAINT chk_membership_dates '
                .'CHECK (ended_at IS NULL OR started_at IS NULL OR ended_at >= started_at)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('family_memberships');
    }
};
