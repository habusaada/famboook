<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §15a "Clan Structure": Clan → Branch Group →
// Branch. A Clan is the large extended family (e.g. عائلة البريم); a
// Family remains a household. Branch groups may be unnamed administrative
// containers. Structures are deactivated, never hard-deleted.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Stable machine code (e.g. AL_BREEM); immutable after creation.
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branch_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('clan_id')->constrained('clans')->restrictOnDelete();
            $table->string('code', 50);
            // Nullable on purpose: some groups have no family name of their own.
            $table->string('name', 150)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['clan_id', 'code']);
            // Target of the branches (branch_group_id, clan_id) FK.
            $table->unique(['id', 'clan_id']);
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('branch_group_id');
            // Kept equal to the group's clan by the composite FK below, so
            // families can enforce "branch belongs to the family's clan" in
            // the database.
            $table->unsignedBigInteger('clan_id');
            $table->string('code', 50);
            $table->string('name', 150);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('clan_id')->references('id')->on('clans')->restrictOnDelete();
            $table->foreign(['branch_group_id', 'clan_id'], 'fk_branches_group_clan')
                ->references(['id', 'clan_id'])->on('branch_groups')->restrictOnDelete();
            // Branch codes are unique within a Clan (families select by code).
            $table->unique(['clan_id', 'code']);
            // Target of the families (branch_id, clan_id) FK.
            $table->unique(['id', 'clan_id']);
            $table->index('branch_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
        Schema::dropIfExists('branch_groups');
        Schema::dropIfExists('clans');
    }
};
