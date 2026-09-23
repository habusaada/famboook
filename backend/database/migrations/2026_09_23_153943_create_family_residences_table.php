<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §24-25: family_residences table and the
// one-current-residence-per-family partial unique index.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_residences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained('families')->restrictOnDelete();
            $table->string('residence_type')->nullable();
            $table->string('governorate')->nullable();
            $table->string('city')->nullable();
            $table->string('area')->nullable();
            $table->string('neighborhood')->nullable();
            $table->text('address_text')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('displacement_status')->nullable();
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->boolean('is_current')->default(true);
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // docs/04-DATABASE.md §25: one current residence per Family.
        DB::statement(
            'CREATE UNIQUE INDEX uq_family_current_residence '
            .'ON family_residences (family_id) WHERE is_current = true'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('family_residences');
    }
};
