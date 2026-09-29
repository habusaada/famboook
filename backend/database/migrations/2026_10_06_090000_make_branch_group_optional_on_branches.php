<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/02 §7b-§7c, docs/04 §12a: a Branch Group is an optional
// organizational classification of Branches. A Branch belongs to its Clan
// (branches.clan_id stays NOT NULL) and optionally to one Group.
//
// Only the NOT NULL on branch_group_id is dropped. The composite foreign key
// fk_branches_group_clan (branch_group_id, clan_id) → branch_groups (id,
// clan_id) is kept unchanged: it is MATCH SIMPLE, so a NULL branch_group_id
// is not checked, while a non-NULL one must still name a Group of the same
// Clan (cross-Clan assignment stays impossible). fk_families_branch_clan
// (Family's Branch in the Family's Clan) is not touched. No row changes.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE branches ALTER COLUMN branch_group_id DROP NOT NULL');

            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_group_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Never invent a Group for ungrouped Branches: refuse instead.
        if (DB::table('branches')->whereNull('branch_group_id')->exists()) {
            throw new RuntimeException(
                'Cannot restore branches.branch_group_id NOT NULL: ungrouped Branches exist. '
                .'Assign them to a Branch Group first.'
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE branches ALTER COLUMN branch_group_id SET NOT NULL');

            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_group_id')->nullable(false)->change();
        });
    }
};
