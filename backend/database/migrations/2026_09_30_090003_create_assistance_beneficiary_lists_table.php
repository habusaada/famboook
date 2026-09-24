<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §48e "Issued Beneficiary Lists (V1-B, EXTERNAL)".
// An immutable record of exactly what was issued to an external
// organization: the column configuration and, per row, the values sent.
// snapshot_data is highly sensitive (it may hold National IDs/mobiles),
// is encrypted at rest with the application key, and is only readable
// through the authorized external-list endpoints. Never updated or deleted;
// a correction is a NEW list.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistance_beneficiary_lists', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('assistance_id')->constrained('assistances')->restrictOnDelete();
            // ABL-000001 (BusinessIdentifier convention).
            $table->string('list_number')->unique();
            $table->string('recipient_organization', 150);
            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            // [{field_key, column_label, classification}] in column order.
            $table->json('configuration_snapshot');
            $table->boolean('contains_sensitive');
            $table->unsignedInteger('row_count');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['assistance_id', 'issued_at']);
        });

        Schema::create('assistance_beneficiary_list_entries', function (Blueprint $table) {
            $table->id();
            // Explicit short names: PostgreSQL truncates identifiers to 63
            // characters and the generated names would collide.
            $table->foreignId('assistance_beneficiary_list_id')
                ->constrained('assistance_beneficiary_lists', 'id', 'fk_abl_entries_list')->restrictOnDelete();
            $table->foreignId('assistance_beneficiary_id')
                ->constrained('assistance_beneficiaries', 'id', 'fk_abl_entries_beneficiary')->restrictOnDelete();
            $table->unsignedInteger('row_number');
            // Encrypted JSON {field_key: value} exactly as issued.
            $table->text('snapshot_data');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['assistance_beneficiary_list_id', 'row_number'], 'uq_abl_entries_row');
            $table->unique(['assistance_beneficiary_list_id', 'assistance_beneficiary_id'], 'uq_abl_entries_beneficiary');
            $table->index('assistance_beneficiary_id', 'ix_abl_entries_beneficiary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistance_beneficiary_list_entries');
        Schema::dropIfExists('assistance_beneficiary_lists');
    }
};
