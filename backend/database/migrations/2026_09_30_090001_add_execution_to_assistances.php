<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §48a/§48c (Assistance V1-B): execution mode, export
// configuration and completion on the program; approval, rejection and
// non-delivery on the beneficiary. Existing V1-A Assistances conceptually
// assumed execution inside Famboook, so they become INTERNAL.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistances', function (Blueprint $table) {
            $table->string('execution_mode')->default('INTERNAL')->after('assistance_type');
            // EXTERNAL only: ordered [{field_key, column_label, sort_order}].
            $table->json('export_fields')->nullable()->after('targeting_criteria');
            $table->timestamp('completed_at')->nullable()->after('opened_by');
            $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('assistance_beneficiaries', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('nominated_by');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('rejected_by');
            $table->timestamp('not_delivered_at')->nullable()->after('rejection_reason');
            $table->foreignId('not_delivered_by')->nullable()->after('not_delivered_at')->constrained('users')->nullOnDelete();
            $table->text('not_delivered_reason')->nullable()->after('not_delivered_by');
        });

        // Adding foreign-key columns makes SQLite rebuild the table, which
        // loses the WHERE clause of the V1-A partial unique indexes. Recreate
        // them explicitly (harmless on PostgreSQL).
        DB::statement('DROP INDEX IF EXISTS uq_assistance_family_nominee');
        DB::statement('DROP INDEX IF EXISTS uq_assistance_person_nominee');
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
                'ALTER TABLE assistances ADD CONSTRAINT chk_assistance_execution_mode '
                ."CHECK (execution_mode IN ('INTERNAL', 'EXTERNAL'))"
            );
            DB::statement(
                'ALTER TABLE assistances ADD CONSTRAINT chk_assistance_completion '
                ."CHECK ((status = 'COMPLETED') = (completed_at IS NOT NULL))"
            );

            DB::statement('ALTER TABLE assistance_beneficiaries DROP CONSTRAINT IF EXISTS chk_beneficiary_status');
            DB::statement(
                'ALTER TABLE assistance_beneficiaries ADD CONSTRAINT chk_beneficiary_status CHECK ('
                ."status IN ('NOMINATED', 'REMOVED', 'APPROVED', 'REJECTED', 'NOT_DELIVERED') AND "
                ."((status = 'REMOVED') = (removed_at IS NOT NULL)) AND "
                ."((status IN ('APPROVED', 'NOT_DELIVERED')) = (approved_at IS NOT NULL)) AND "
                ."((status = 'REJECTED') = (rejected_at IS NOT NULL AND rejection_reason IS NOT NULL)) AND "
                ."((status = 'NOT_DELIVERED') = (not_delivered_at IS NOT NULL AND not_delivered_reason IS NOT NULL)))"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE assistances DROP CONSTRAINT IF EXISTS chk_assistance_execution_mode');
            DB::statement('ALTER TABLE assistances DROP CONSTRAINT IF EXISTS chk_assistance_completion');
            DB::statement('ALTER TABLE assistance_beneficiaries DROP CONSTRAINT IF EXISTS chk_beneficiary_status');
            DB::statement(
                'ALTER TABLE assistance_beneficiaries ADD CONSTRAINT chk_beneficiary_status CHECK ('
                ."(status = 'NOMINATED' AND removed_at IS NULL) OR (status = 'REMOVED' AND removed_at IS NOT NULL))"
            );
        }

        Schema::table('assistance_beneficiaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropConstrainedForeignId('not_delivered_by');
            $table->dropColumn(['approved_at', 'rejected_at', 'rejection_reason', 'not_delivered_at', 'not_delivered_reason']);
        });

        Schema::table('assistances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['execution_mode', 'export_fields', 'completed_at']);
        });
    }
};
