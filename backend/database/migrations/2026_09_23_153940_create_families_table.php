<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §11-12: families table structure and indexes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('families', function (Blueprint $table) {
            $table->id();
            $table->string('family_code')->unique();
            $table->string('status');
            $table->date('registration_date')->nullable();
            $table->string('registration_source')->nullable();
            $table->string('paper_form_no')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('paper_form_no');
            $table->index('registration_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('families');
    }
};
