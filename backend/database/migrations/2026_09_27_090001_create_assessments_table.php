<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §31 "Assessments (V1)" — a family-level, dated
// snapshot with at most one result per assessment domain. DRAFT →
// COMPLETED only; COMPLETED is immutable. No hard delete in V1.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            // Public route key; internal ids are never exposed by the API.
            $table->uuid('uuid')->unique();
            $table->foreignId('family_id')->constrained('families')->restrictOnDelete();
            // Business date: when the family was assessed — NOT created_at.
            // Deliberately not unique per family: several assessments may
            // share a date.
            $table->date('assessment_date');
            $table->string('status');
            $table->text('general_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['family_id', 'assessment_date', 'created_at', 'id']);
        });

        Schema::create('assessment_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->restrictOnDelete();
            // Reference values are deactivated, never deleted (docs/06 §56).
            $table->foreignId('assessment_domain_id')->constrained('assessment_domains')->restrictOnDelete();
            // NONE | LOW | MEDIUM | HIGH | CRITICAL. There is no
            // NOT_ASSESSED value: a missing row means "not assessed".
            $table->string('rating');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'assessment_domain_id']);
        });

        // Postgres-only, same reason as the existing CHECK constraints
        // (SQLite cannot add them post-creation); enforced by the
        // assessment requests/actions on SQLite.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE assessments ADD CONSTRAINT chk_assessment_status '
                ."CHECK (status IN ('DRAFT', 'COMPLETED'))"
            );
            DB::statement(
                'ALTER TABLE assessments ADD CONSTRAINT chk_assessment_completion CHECK ('
                ."(status = 'DRAFT' AND completed_at IS NULL) OR "
                ."(status = 'COMPLETED' AND completed_at IS NOT NULL))"
            );
            DB::statement(
                'ALTER TABLE assessment_results ADD CONSTRAINT chk_assessment_result_rating '
                ."CHECK (rating IN ('NONE', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_results');
        Schema::dropIfExists('assessments');
    }
};
