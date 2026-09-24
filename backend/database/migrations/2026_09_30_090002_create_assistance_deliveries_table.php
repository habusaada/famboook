<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §48d "Assistance Deliveries (V1-B, INTERNAL only)".
// One row = the full package was received after National ID
// verification. No National ID is ever copied here. Never deleted: a
// mistaken delivery is reversed (reversed_at/by/reason) and a fresh one
// may follow. At most one non-reversed delivery per beneficiary.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistance_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('assistance_beneficiary_id')->constrained('assistance_beneficiaries')->restrictOnDelete();
            // PERSONAL | DELEGATE
            $table->string('receipt_mode');
            $table->foreignId('original_beneficiary_person_id')->constrained('persons')->restrictOnDelete();
            $table->foreignId('recipient_person_id')->constrained('persons')->restrictOnDelete();
            $table->timestamp('delivered_at');
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->index('assistance_beneficiary_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX uq_assistance_active_delivery '
            .'ON assistance_deliveries (assistance_beneficiary_id) WHERE reversed_at IS NULL'
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE assistance_deliveries ADD CONSTRAINT chk_delivery_receipt CHECK ('
                ."(receipt_mode = 'PERSONAL' AND recipient_person_id = original_beneficiary_person_id) OR "
                ."(receipt_mode = 'DELEGATE' AND recipient_person_id <> original_beneficiary_person_id))"
            );
            DB::statement(
                'ALTER TABLE assistance_deliveries ADD CONSTRAINT chk_delivery_reversal '
                .'CHECK ((reversed_at IS NULL) = (reversal_reason IS NULL))'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistance_deliveries');
    }
};
