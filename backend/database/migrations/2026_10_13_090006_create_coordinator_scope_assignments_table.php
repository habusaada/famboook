<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// PWA-1C (docs/04 §55b): explicit, audited organizational scope of a
// coordinator — CLAN, BRANCH_GROUP or BRANCH. Schema only: no assignment is
// created here and NOTHING authorizes by it yet (PWA-1H).
//
// clan_id is always set; the composite foreign keys keep a group or branch
// inside that Clan (same targets as fk_branches_group_clan and
// fk_families_branch_clan). A coordinator may hold several active
// assignments; the same scope twice is prevented by three scope-specific
// partial unique indexes, which behave identically on PostgreSQL and SQLite
// (no reliance on NULL semantics in a unique index).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coordinator_scope_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('scope_type', 20);
            $table->unsignedBigInteger('clan_id');
            $table->unsignedBigInteger('branch_group_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 60)->nullable();
            $table->timestamps();

            $table->foreign('clan_id')->references('id')->on('clans')->restrictOnDelete();
            $table->foreign(['branch_group_id', 'clan_id'], 'fk_coordinator_scope_group_clan')
                ->references(['id', 'clan_id'])->on('branch_groups')->restrictOnDelete();
            $table->foreign(['branch_id', 'clan_id'], 'fk_coordinator_scope_branch_clan')
                ->references(['id', 'clan_id'])->on('branches')->restrictOnDelete();

            $table->index(['user_id', 'revoked_at']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX uq_coordinator_scope_active_clan '
            ."ON coordinator_scope_assignments (user_id, clan_id) WHERE scope_type = 'CLAN' AND revoked_at IS NULL"
        );
        DB::statement(
            'CREATE UNIQUE INDEX uq_coordinator_scope_active_group '
            ."ON coordinator_scope_assignments (user_id, branch_group_id) WHERE scope_type = 'BRANCH_GROUP' AND revoked_at IS NULL"
        );
        DB::statement(
            'CREATE UNIQUE INDEX uq_coordinator_scope_active_branch '
            ."ON coordinator_scope_assignments (user_id, branch_id) WHERE scope_type = 'BRANCH' AND revoked_at IS NULL"
        );

        if (DB::getDriverName() === 'pgsql') {
            // Exactly the reference that matches the scope level is set.
            DB::statement(
                'ALTER TABLE coordinator_scope_assignments ADD CONSTRAINT chk_coordinator_scope_shape CHECK ('
                ."(scope_type = 'CLAN' AND branch_group_id IS NULL AND branch_id IS NULL) OR "
                ."(scope_type = 'BRANCH_GROUP' AND branch_group_id IS NOT NULL AND branch_id IS NULL) OR "
                ."(scope_type = 'BRANCH' AND branch_id IS NOT NULL AND branch_group_id IS NULL))"
            );
            DB::statement(
                'ALTER TABLE coordinator_scope_assignments ADD CONSTRAINT chk_coordinator_scope_revoked CHECK ('
                .'(revoked_at IS NULL) = (revoked_by IS NULL) AND '
                .'(revoked_at IS NULL) = (revoke_reason IS NULL) AND '
                ."(revoke_reason IS NULL OR revoke_reason ~ '^[A-Z][A-Z0-9_]{1,59}$'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coordinator_scope_assignments');
    }
};
