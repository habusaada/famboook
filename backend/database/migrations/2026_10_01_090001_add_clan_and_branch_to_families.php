<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// docs/04-DATABASE.md §6 (families) / §15a. Every Family belongs to a Clan
// (required); its Branch is optional (NULL = not yet known). Existing
// families — all Al-Breem households — are assigned to the Clan
// AL_BREEM with no Branch. No Branch is inferred and no other family data
// (codes, ids, dates, members) is touched.
return new class extends Migration
{
    public function up(): void
    {
        // The first Clan. Idempotent: reuse it if it already exists.
        $clanId = DB::table('clans')->where('code', 'AL_BREEM')->value('id');
        if ($clanId === null) {
            $clanId = DB::table('clans')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'code' => 'AL_BREEM',
                'name' => 'عائلة البريم',
                'is_active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        Schema::table('families', function (Blueprint $table) {
            $table->unsignedBigInteger('clan_id')->nullable()->after('family_code');
            $table->unsignedBigInteger('branch_id')->nullable()->after('clan_id');
        });

        // Backfill every existing family, including soft-deleted ones.
        DB::table('families')->whereNull('clan_id')->update(['clan_id' => $clanId]);

        Schema::table('families', function (Blueprint $table) {
            $table->unsignedBigInteger('clan_id')->nullable(false)->change();

            $table->foreign('clan_id')->references('id')->on('clans')->restrictOnDelete();
            // A Branch, when set, must belong to the family's Clan. MATCH
            // SIMPLE: a NULL branch_id is not checked.
            $table->foreign(['branch_id', 'clan_id'], 'fk_families_branch_clan')
                ->references(['id', 'clan_id'])->on('branches')->restrictOnDelete();
            $table->index(['clan_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            // SQLite (tests) can only drop a foreign key by its columns.
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['branch_id', 'clan_id'] : 'fk_families_branch_clan');
            $table->dropForeign(['clan_id']);
            $table->dropIndex(['clan_id', 'branch_id']);
            $table->dropColumn(['branch_id', 'clan_id']);
        });
    }
};
