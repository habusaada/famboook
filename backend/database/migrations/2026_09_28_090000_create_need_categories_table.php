<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §47a "Need Categories" — same reference-data shape
// as relationship_types (§18), disability_types (§28) and
// assessment_domains (§31a).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('need_categories', function (Blueprint $table) {
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
        Schema::dropIfExists('need_categories');
    }
};
