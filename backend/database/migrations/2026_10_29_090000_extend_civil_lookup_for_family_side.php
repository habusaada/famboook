<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FB-CIVIL-02B — Civil Registry Lookup for the Family Portal and Coordinator
// Space (docs/adr/FB-CIVIL-02B-FAMILY-COORDINATOR-LOOKUP.md). Extends the
// 02A tables; no new lookup table, no second quota:
//
//   civil_lookup_user_access.sides      which family-side contexts a GRANTED row opens
//                                       (FAMILY, COORDINATOR or FAMILY,COORDINATOR); NULL for REVOKED
//   civil_lookup_policy_changes.*_sides the same, in the append-only history
//   civil_lookup_audit_events.lookup_context  STAFF | FAMILY | COORDINATOR (existing rows: STAFF,
//                                       the only context that could exist before)
//
// Adding columns is DDL, not an UPDATE: the append-only triggers are untouched.
// down() refuses once any family-side grant, history or audit row exists.
return new class extends Migration
{
    private const SIDES = "'FAMILY', 'COORDINATOR', 'FAMILY,COORDINATOR'";

    public function up(): void
    {
        Schema::table('civil_lookup_user_access', function (Blueprint $table) {
            $table->string('sides', 24)->nullable();
        });
        Schema::table('civil_lookup_policy_changes', function (Blueprint $table) {
            $table->string('old_sides', 24)->nullable();
            $table->string('new_sides', 24)->nullable();
        });
        Schema::table('civil_lookup_audit_events', function (Blueprint $table) {
            $table->string('lookup_context', 12)->default('STAFF');
            $table->index('lookup_context');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $checks = [
            // GRANTED always names its sides; REVOKED never does. IS NOT NULL is explicit: `NULL IN (…)`
            // is NULL, which a CHECK would accept.
            'civil_lookup_user_access' => [
                'chk_civil_lookup_user_access_sides' => "(access = 'GRANTED' AND sides IS NOT NULL AND sides IN (".self::SIDES."))
                    OR (access = 'REVOKED' AND sides IS NULL)",
            ],
            'civil_lookup_policy_changes' => [
                'chk_civil_lookup_change_sides' => '(old_sides IS NULL OR old_sides IN ('.self::SIDES.'))
                    AND (new_sides IS NULL OR new_sides IN ('.self::SIDES.'))',
            ],
            'civil_lookup_audit_events' => [
                'chk_civil_lookup_audit_context' => "lookup_context IN ('STAFF', 'FAMILY', 'COORDINATOR')",
                // A family-side context is only ever recorded for a family-side actor.
                'chk_civil_lookup_audit_context_side' => "lookup_context = 'STAFF' OR actor_side = 'FAMILY'",
            ],
        ];
        foreach ($checks as $table => $constraints) {
            foreach ($constraints as $name => $check) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$check})");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('civil_lookup_user_access')->whereNotNull('sides')->exists()
            || DB::table('civil_lookup_policy_changes')->where(fn ($q) => $q->whereNotNull('old_sides')->orWhereNotNull('new_sides'))->exists()
            || DB::table('civil_lookup_audit_events')->where('lookup_context', '<>', 'STAFF')->exists()) {
            throw new RuntimeException('Cannot roll back FB-CIVIL-02B: family-side lookup history exists.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE civil_lookup_user_access DROP CONSTRAINT IF EXISTS chk_civil_lookup_user_access_sides');
            DB::statement('ALTER TABLE civil_lookup_policy_changes DROP CONSTRAINT IF EXISTS chk_civil_lookup_change_sides');
            DB::statement('ALTER TABLE civil_lookup_audit_events DROP CONSTRAINT IF EXISTS chk_civil_lookup_audit_context');
            DB::statement('ALTER TABLE civil_lookup_audit_events DROP CONSTRAINT IF EXISTS chk_civil_lookup_audit_context_side');
        }
        Schema::table('civil_lookup_audit_events', function (Blueprint $table) {
            $table->dropIndex(['lookup_context']);
            $table->dropColumn('lookup_context');
        });
        Schema::table('civil_lookup_policy_changes', function (Blueprint $table) {
            $table->dropColumn(['old_sides', 'new_sides']);
        });
        Schema::table('civil_lookup_user_access', function (Blueprint $table) {
            $table->dropColumn('sides');
        });
    }
};
