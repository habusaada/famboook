<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §48a "Assistances (V1-A)" — an assistance
// program/campaign (what can be provided), its planned items and the
// targeting criteria last used. NOT a delivery record: actual delivery
// (assistance_records, §48) belongs to V1-B.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistances', function (Blueprint $table) {
            $table->id();
            // Public route key; internal ids are never exposed by the API.
            $table->uuid('uuid')->unique();
            $table->string('title', 150);
            // Reference values are deactivated, never deleted (docs/06 §56).
            $table->foreignId('assistance_category_id')->constrained('assistance_categories')->restrictOnDelete();
            $table->string('assistance_type');
            // Free text in V1-A; no providers/organizations management yet.
            $table->string('provider_name', 150);
            // Planned target only. Nominee/approval/delivery counts are
            // always derived, never stored.
            $table->unsignedInteger('target_beneficiaries')->nullable();
            // Planned period, not delivery dates.
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('description')->nullable();
            $table->string('status');
            // Validated criteria snapshot (App\Support\TargetingCriteria).
            // Preview results are never stored.
            $table->json('targeting_criteria')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('assistance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistance_id')->constrained('assistances')->cascadeOnDelete();
            $table->string('item_name', 150);
            // Planned per-beneficiary definition; never delivered amounts.
            $table->decimal('quantity_per_beneficiary', 12, 2)->nullable();
            $table->string('unit', 30)->nullable();
            $table->decimal('unit_value', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Postgres-only, same reason as the existing CHECK constraints
        // (SQLite cannot add them post-creation); enforced by the
        // assistance requests/actions on SQLite.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE assistances ADD CONSTRAINT chk_assistance_status '
                ."CHECK (status IN ('DRAFT', 'OPEN', 'COMPLETED', 'CANCELLED'))"
            );
            DB::statement(
                'ALTER TABLE assistances ADD CONSTRAINT chk_assistance_type '
                ."CHECK (assistance_type IN ('IN_KIND', 'CASH', 'SERVICE'))"
            );
            DB::statement(
                'ALTER TABLE assistances ADD CONSTRAINT chk_assistance_dates '
                .'CHECK (start_date IS NULL OR end_date IS NULL OR end_date >= start_date)'
            );
            DB::statement(
                'ALTER TABLE assistances ADD CONSTRAINT chk_assistance_target '
                .'CHECK (target_beneficiaries IS NULL OR target_beneficiaries > 0)'
            );
            DB::statement(
                'ALTER TABLE assistance_items ADD CONSTRAINT chk_assistance_item_values CHECK ('
                .'(quantity_per_beneficiary IS NULL OR quantity_per_beneficiary > 0) AND '
                .'(unit_value IS NULL OR unit_value > 0) AND '
                ."((unit_value IS NULL AND currency IS NULL) OR (unit_value IS NOT NULL AND currency IN ('ILS', 'USD', 'JOD', 'EUR'))))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistance_items');
        Schema::dropIfExists('assistances');
    }
};
