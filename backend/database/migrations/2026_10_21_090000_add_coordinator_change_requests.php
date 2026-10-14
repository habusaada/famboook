<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Coordinator-submitted requests — schema foundation (CO-02.3a; docs/04
// DB-ADR-062, docs/adr/CO-02-COORDINATOR-REQUESTS.md §5). Schema only: no
// route, handler or permission uses it yet.
//
// change_requests
// - submission_channel: FAMILY (every request so far — the column default
//   fills existing rows, no backfill statement) or COORDINATOR;
// - REGISTER_FAMILY (product name FAMILY_ADD) proposes a Family that does not
//   exist yet: family_id is NULL for that type ONLY, and its placement
//   (proposed_clan_id, optional proposed_branch_id — a Branch of that Clan
//   through the composite key on branches (id, clan_id)) is present for that
//   type ONLY; created_family_id is set exactly when it is APPLIED;
// - REGISTER_FAMILY is COORDINATOR-channel only (V1).
//
// workflow_events
// - actor_side gains COORDINATOR; the column widens from varchar(10) to
//   varchar(20) because 'COORDINATOR' is eleven characters (a length
//   increase: no table rewrite, the append-only trigger is not fired).
//
// Every existing CHECK is kept verbatim, including the CONTACT_UPDATE
// relaxations of 2026_10_18_090000; only chk_change_request_type and
// chk_workflow_event_actor_side are replaced, each by a strict superset.
// No row changes. CHECKs exist on PostgreSQL only (repository convention);
// on SQLite the enums and the Domain Actions carry the same rules.
//
// down() refuses while any row depends on the new values (REGISTER_FAMILY,
// a COORDINATOR channel or a COORDINATOR workflow actor) — history is never
// rewritten; otherwise it restores the previous schema exactly.
return new class extends Migration
{
    private const OLD_TYPES = "'CONTACT_UPDATE', 'RESIDENCE_UPDATE', 'PERSON_CORRECTION', 'ADD_FAMILY_MEMBER', "
        ."'MEMBERSHIP_CHANGE', 'HOUSEHOLD_HEAD_CHANGE', 'BIRTH_REPORT', 'DEATH_REPORT', 'MARRIAGE_UPDATE', "
        ."'DOCUMENT_UPDATE', 'OTHER'";

    private const TYPES = self::OLD_TYPES.", 'REGISTER_FAMILY'";

    private const OLD_ACTOR_SIDES = "'FAMILY', 'STAFF', 'SYSTEM'";

    private const ACTOR_SIDES = self::OLD_ACTOR_SIDES.", 'COORDINATOR'";

    private const NEW_CHECKS = [
        'chk_change_request_channel',
        'chk_change_request_family_subject',
        'chk_change_request_placement',
        'chk_change_request_created_family',
        'chk_change_request_channel_type',
    ];

    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';
        if ($pgsql) {
            // Fail fast behind a long transaction instead of queueing every
            // request behind this migration (DB-ADR-061 pattern).
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('ALTER TABLE change_requests ALTER COLUMN family_id DROP NOT NULL');
            DB::statement('ALTER TABLE workflow_events ALTER COLUMN actor_side TYPE varchar(20)');
        } else {
            Schema::table('change_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('family_id')->nullable()->change();
            });
        }

        Schema::table('change_requests', function (Blueprint $table) {
            $table->string('submission_channel', 20)->default('FAMILY')->after('family_id');
            $table->foreignId('proposed_clan_id')->nullable()->after('target_membership_id')
                ->constrained('clans')->restrictOnDelete();
            $table->unsignedBigInteger('proposed_branch_id')->nullable()->after('proposed_clan_id');
            $table->foreignId('created_family_id')->nullable()->after('proposed_branch_id')
                ->constrained('families')->restrictOnDelete();

            $table->foreign(['proposed_branch_id', 'proposed_clan_id'], 'fk_change_request_proposed_branch_clan')
                ->references(['id', 'clan_id'])->on('branches')->restrictOnDelete();

            $table->index(['submission_channel', 'status', 'submitted_at']);
            $table->index(['submitted_by', 'status']);
            $table->index(['proposed_clan_id', 'proposed_branch_id']);
            $table->index('created_family_id');
        });

        if (! $pgsql) {
            return;
        }

        $this->replace('change_requests', 'chk_change_request_type', 'type IN ('.self::TYPES.')');
        $checks = [
            'chk_change_request_channel' => "submission_channel IN ('FAMILY', 'COORDINATOR')",
            // A proposed Family has no Family yet; every other type names one.
            'chk_change_request_family_subject' => "(type = 'REGISTER_FAMILY') = (family_id IS NULL)",
            // The placement belongs to a proposed Family only; a Branch never
            // stands without its Clan (consistency: the composite key).
            'chk_change_request_placement' => "((type = 'REGISTER_FAMILY') = (proposed_clan_id IS NOT NULL)) "
                .'AND (proposed_branch_id IS NULL OR proposed_clan_id IS NOT NULL)',
            // The created Family is recorded by the APPLY of a proposal, and only then.
            'chk_change_request_created_family' => "(created_family_id IS NOT NULL) = (type = 'REGISTER_FAMILY' AND status = 'APPLIED')",
            // V1: only Coordinators propose a new Family by request.
            'chk_change_request_channel_type' => "type <> 'REGISTER_FAMILY' OR submission_channel = 'COORDINATOR'",
        ];
        foreach ($checks as $name => $check) {
            DB::statement("ALTER TABLE change_requests ADD CONSTRAINT {$name} CHECK ({$check})");
        }

        $this->replace('workflow_events', 'chk_workflow_event_actor_side', 'actor_side IN ('.self::ACTOR_SIDES.')');
    }

    public function down(): void
    {
        // Never rewrite history: refuse while rows rely on the new values.
        if (DB::table('change_requests')->where('type', 'REGISTER_FAMILY')->exists()
            || DB::table('change_requests')->where('submission_channel', '<>', 'FAMILY')->exists()
            || DB::table('change_requests')->whereNull('family_id')->exists()
            || DB::table('workflow_events')->where('actor_side', 'COORDINATOR')->exists()) {
            throw new RuntimeException('Cannot roll back coordinator change requests: coordinator request records exist.');
        }

        $pgsql = DB::getDriverName() === 'pgsql';
        if ($pgsql) {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $this->replace('workflow_events', 'chk_workflow_event_actor_side', 'actor_side IN ('.self::OLD_ACTOR_SIDES.')');
            foreach (array_reverse(self::NEW_CHECKS) as $name) {
                DB::statement("ALTER TABLE change_requests DROP CONSTRAINT {$name}");
            }
            $this->replace('change_requests', 'chk_change_request_type', 'type IN ('.self::OLD_TYPES.')');
        }

        Schema::table('change_requests', function (Blueprint $table) use ($pgsql) {
            $table->dropIndex(['submission_channel', 'status', 'submitted_at']);
            $table->dropIndex(['submitted_by', 'status']);
            $table->dropIndex(['proposed_clan_id', 'proposed_branch_id']);
            $table->dropIndex(['created_family_id']);
            // SQLite drops a foreign key by its columns only (table rebuild).
            $pgsql
                ? $table->dropForeign('fk_change_request_proposed_branch_clan')
                : $table->dropForeign(['proposed_branch_id', 'proposed_clan_id']);
            $table->dropConstrainedForeignId('created_family_id');
            $table->dropConstrainedForeignId('proposed_clan_id');
            $table->dropColumn(['proposed_branch_id', 'submission_channel']);
        });

        if ($pgsql) {
            // Safe: every remaining value is one of the three old sides.
            DB::statement('ALTER TABLE workflow_events ALTER COLUMN actor_side TYPE varchar(10)');
            DB::statement('ALTER TABLE change_requests ALTER COLUMN family_id SET NOT NULL');
        } else {
            Schema::table('change_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('family_id')->nullable(false)->change();
            });
        }
    }

    private function replace(string $table, string $name, string $check): void
    {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$check})");
    }
};
