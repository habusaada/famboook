<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §48c "Assistance Beneficiaries (V1-A: nominees)".
// A family (person_id NULL) or one of its members nominated as a
// potential beneficiary of an Assistance. A nomination is NOT proof of
// receipt. Removal is history-preserving (status REMOVED); V1-B will add
// APPROVED / REJECTED / NOT_DELIVERED on the same rows.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistance_beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('assistance_id')->constrained('assistances')->restrictOnDelete();
            $table->foreignId('family_id')->constrained('families')->restrictOnDelete();
            // NULL = family-level nominee.
            $table->foreignId('person_id')->nullable()->constrained('persons')->restrictOnDelete();
            $table->foreignId('source_need_id')->nullable()->constrained('family_needs')->restrictOnDelete();
            // TARGETING | MANUAL | NEED — recorded at nomination, never inferred.
            $table->string('nomination_source');
            // Criteria snapshot for TARGETING nominations (never the match result).
            $table->json('targeting_criteria')->nullable();
            $table->string('status');
            $table->timestamp('nominated_at');
            $table->foreignId('nominated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['assistance_id', 'status']);
            $table->index(['family_id']);
        });

        // One current (non-removed) nomination per family-level target and
        // per person within an Assistance. Removed rows stay as history and
        // allow a later re-nomination. Partial indexes work on PostgreSQL
        // and SQLite (test suite).
        DB::statement(
            'CREATE UNIQUE INDEX uq_assistance_family_nominee '
            .'ON assistance_beneficiaries (assistance_id, family_id) '
            ."WHERE person_id IS NULL AND status <> 'REMOVED'"
        );
        DB::statement(
            'CREATE UNIQUE INDEX uq_assistance_person_nominee '
            .'ON assistance_beneficiaries (assistance_id, person_id) '
            ."WHERE person_id IS NOT NULL AND status <> 'REMOVED'"
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE assistance_beneficiaries ADD CONSTRAINT chk_beneficiary_source '
                ."CHECK (nomination_source IN ('TARGETING', 'MANUAL', 'NEED'))"
            );
            DB::statement(
                'ALTER TABLE assistance_beneficiaries ADD CONSTRAINT chk_beneficiary_status CHECK ('
                ."(status = 'NOMINATED' AND removed_at IS NULL) OR (status = 'REMOVED' AND removed_at IS NOT NULL))"
            );
            DB::statement(
                'ALTER TABLE assistance_beneficiaries ADD CONSTRAINT chk_beneficiary_need_source '
                ."CHECK ((nomination_source = 'NEED') = (source_need_id IS NOT NULL))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistance_beneficiaries');
    }
};
