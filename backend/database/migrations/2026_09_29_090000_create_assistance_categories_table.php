<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §48b "Assistance Categories" — same reference-data
// shape as need_categories (§47a), but technically independent of it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistance_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistance_categories');
    }
};
