<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §13-16: persons table structure, indexes, and the
// death/birth chronological CHECK constraint.
//
// marital_status_id is intentionally omitted: it references the
// marital_statuses reference table, which is out of scope for this
// vertical slice (docs/07-ROADMAP.md Phase 7 — Reference Data). The
// column is nullable per docs/02 §9, so omitting it does not violate
// any documented invariant; it will be added when reference data is
// implemented.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table) {
            $table->id();
            $table->string('person_code')->unique();
            $table->string('full_name');
            $table->string('national_id')->nullable();
            $table->string('gender')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('life_status');
            $table->date('death_date')->nullable();
            $table->string('mobile')->nullable();
            $table->string('alternate_mobile')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('person_code');
            $table->index('national_id');
            $table->index('mobile');
            $table->index('birth_date');
            $table->index('life_status');
        });

        // docs/04-DATABASE.md §14: "PostgreSQL should protect the basic
        // chronological invariant" — death_date cannot precede birth_date.
        // Postgres-only: SQLite (used by the test suite) cannot add CHECK
        // constraints via ALTER TABLE after table creation. The equivalent
        // application-level rule is enforced in RegisterFamilyRequest.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE persons ADD CONSTRAINT chk_persons_death_after_birth '
                .'CHECK (death_date IS NULL OR birth_date IS NULL OR death_date >= birth_date)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('persons');
    }
};
