<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §47 "Family Needs (V1)". A Need always belongs to a
// Family, optionally targets one Person of that family and optionally
// cites a completed Assessment of that family as its source.
// OPEN → FULFILLED | CLOSED; resolved Needs are read-only. No hard delete.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_needs', function (Blueprint $table) {
            $table->id();
            // Public route key; internal ids are never exposed by the API.
            $table->uuid('uuid')->unique();
            $table->foreignId('family_id')->constrained('families')->restrictOnDelete();
            // NULL = family-level need.
            $table->foreignId('person_id')->nullable()->constrained('persons')->restrictOnDelete();
            $table->foreignId('source_assessment_id')->nullable()->constrained('assessments')->restrictOnDelete();
            // Reference values are deactivated, never deleted (docs/06 §56).
            $table->foreignId('need_category_id')->constrained('need_categories')->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('priority');
            // Requested quantity only; delivered quantities belong to the
            // future Assistance domain.
            $table->decimal('quantity', 12, 2)->nullable();
            $table->string('unit', 30)->nullable();
            $table->string('status');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closure_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['family_id', 'status']);
            $table->index(['status', 'priority', 'created_at']);
        });

        // Postgres-only, same reason as the existing CHECK constraints
        // (SQLite cannot add them post-creation); enforced by the Need
        // requests/actions on SQLite.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE family_needs ADD CONSTRAINT chk_need_status '
                ."CHECK (status IN ('OPEN', 'FULFILLED', 'CLOSED'))"
            );
            DB::statement(
                'ALTER TABLE family_needs ADD CONSTRAINT chk_need_priority '
                ."CHECK (priority IN ('LOW', 'MEDIUM', 'HIGH', 'URGENT'))"
            );
            DB::statement(
                'ALTER TABLE family_needs ADD CONSTRAINT chk_need_quantity '
                .'CHECK ((quantity IS NULL OR quantity > 0) AND (unit IS NULL OR quantity IS NOT NULL))'
            );
            // resolved_by is not part of the check: it is SET NULL if the
            // user row is ever removed.
            DB::statement(
                'ALTER TABLE family_needs ADD CONSTRAINT chk_need_resolution CHECK ('
                ."(status = 'OPEN' AND resolved_at IS NULL AND resolved_by IS NULL AND closure_reason IS NULL) OR "
                ."(status = 'FULFILLED' AND resolved_at IS NOT NULL AND closure_reason IS NULL) OR "
                ."(status = 'CLOSED' AND resolved_at IS NOT NULL AND closure_reason IS NOT NULL))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('family_needs');
    }
};
