<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Family-key resolution (Import Wizard step 4, docs/03 §96a, docs/04 §83b).
//
// One administrator decision per (import batch, EXACT source family key):
// MATCH_EXISTING_BRANCH | CREATE_NEW_BRANCH | SAME_BRANCH_AS_KEY | NO_BRANCH.
// No row = UNRESOLVED (distinct from an explicit NO_BRANCH). The staged
// import_rows.source_family_key is never rewritten; this is a separate layer.
//
// clan_id mirrors the batch's Clan so the database itself guarantees that a
// resolved Branch belongs to the batch's target Clan (same composite-FK
// pattern as families → branches). Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            // Target of the resolutions (import_batch_id, clan_id) FK.
            $table->unique(['id', 'clan_id']);
        });

        Schema::create('import_family_key_resolutions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('import_batch_id');
            $table->unsignedBigInteger('clan_id');
            // Exact staged source value (whitespace-normalized at staging only).
            $table->string('source_family_key', 150);
            $table->string('decision', 30);
            // Final target Branch (NULL only for NO_BRANCH).
            $table->unsignedBigInteger('branch_id')->nullable();
            // SAME_BRANCH_AS_KEY: the other key whose Branch was reused (audit only;
            // branch_id always holds the final Branch, so there are no chains).
            $table->string('reference_source_key', 150)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at');
            $table->timestamps();

            $table->foreign('clan_id')->references('id')->on('clans')->restrictOnDelete();
            $table->foreign(['import_batch_id', 'clan_id'], 'fk_key_resolution_batch_clan')
                ->references(['id', 'clan_id'])->on('import_batches')->restrictOnDelete();
            // MATCH SIMPLE: a NULL branch_id (NO_BRANCH) is not checked.
            $table->foreign(['branch_id', 'clan_id'], 'fk_key_resolution_branch_clan')
                ->references(['id', 'clan_id'])->on('branches')->restrictOnDelete();

            $table->unique(['import_batch_id', 'source_family_key'], 'uq_key_resolution_batch_key');
            $table->index('branch_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE import_family_key_resolutions ADD CONSTRAINT chk_key_resolution_decision '
                ."CHECK (decision IN ('MATCH_EXISTING_BRANCH', 'CREATE_NEW_BRANCH', 'SAME_BRANCH_AS_KEY', 'NO_BRANCH'))"
            );
            DB::statement(
                'ALTER TABLE import_family_key_resolutions ADD CONSTRAINT chk_key_resolution_branch '
                ."CHECK ((decision = 'NO_BRANCH') = (branch_id IS NULL))"
            );
            DB::statement(
                'ALTER TABLE import_family_key_resolutions ADD CONSTRAINT chk_key_resolution_reference '
                ."CHECK ((decision = 'SAME_BRANCH_AS_KEY') = (reference_source_key IS NOT NULL))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_family_key_resolutions');

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropUnique(['id', 'clan_id']);
        });
    }
};
