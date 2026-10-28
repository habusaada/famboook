<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FB-CIVIL-02A (docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md §L-3, §L-6, §L-10):
// Civil Registry Lookup policies, per-user access, daily usage and audit, in
// the APPLICATION schema. Nothing here references identity_ref by foreign key
// (the application role cannot see it): release and record ids are values.
// No table holds a National ID, a name, a search term or a payload.
//
// civil_lookup_policy_changes and civil_lookup_audit_events are append-only:
// the models refuse update and delete and, on PostgreSQL, a row trigger
// rejects every UPDATE and DELETE. down() refuses once history exists.
return new class extends Migration
{
    /** §L-6 defaults: [enabled, daily limit (NULL = unlimited), unlimited allowed]. COORDINATOR / FAMILY_USER: 02B, disabled. */
    private const DEFAULT_POLICIES = [
        'SUPER_ADMIN' => [true, null, true],
        'ADMINISTRATOR' => [true, 200, false],
        'DATA_ENTRY' => [true, 50, false],
        'REVIEWER' => [false, 50, false],
        'SOCIAL_WORKER' => [false, 50, false],
        'REPORTS_VIEWER' => [false, 50, false],
        'COORDINATOR' => [false, 5, false],
        'FAMILY_USER' => [false, 5, false],
    ];

    private const EVENT_TYPES = "'SEARCH_BY_ID', 'SEARCH_BY_NAME', 'SEARCH_BY_PARTS', 'SEARCH_PAGE', 'RECORD_OPENED', "
        ."'LINK_OPENED', 'LINKS_LISTED', 'LOOKUP_REFUSED'";

    public function up(): void
    {
        Schema::create('civil_lookup_role_policies', function (Blueprint $table) {
            $table->id();
            $table->string('role_name', 40)->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('daily_limit')->nullable();
            $table->boolean('unlimited_allowed')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        // The individual override (§L-3): REVOKED blocks a user whatever their role; GRANTED is reserved for 02B.
        Schema::create('civil_lookup_user_access', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            $table->string('access', 10);
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at')->useCurrent();
        });

        Schema::create('civil_lookup_policy_changes', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 10);
            $table->string('role_name', 40)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('old_enabled')->nullable();
            $table->boolean('new_enabled')->nullable();
            $table->unsignedInteger('old_daily_limit')->nullable();
            $table->unsignedInteger('new_daily_limit')->nullable();
            $table->string('old_access', 10)->nullable();
            $table->string('new_access', 10)->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['subject_type', 'changed_at']);
        });

        // One counter per user per Asia/Gaza day (§L-6).
        Schema::create('civil_lookup_daily_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('usage_date');
            $table->unsignedInteger('used')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['user_id', 'usage_date']);
            $table->index('usage_date');
        });

        Schema::create('civil_lookup_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 40);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_side', 10);
            $table->string('outcome_code', 64);
            // identity_ref ids by value only (no foreign key).
            $table->unsignedBigInteger('release_id')->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            // KeyedFingerprint (civil-lookup.query.v1) of the normalized criteria; never the criteria.
            $table->string('query_fingerprint', 64)->nullable();
            $table->unsignedInteger('result_count')->nullable();
            $table->unsignedInteger('quota_used_after')->nullable();
            $table->string('ip_hash', 64)->nullable();
            // Immutable: no updated_at.
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['actor_user_id', 'occurred_at']);
            $table->index('event_type');
            $table->index('occurred_at');
            $table->index('record_id');
        });

        $now = now();
        DB::table('civil_lookup_role_policies')->insert(array_map(fn (string $role, array $p) => [
            'role_name' => $role, 'enabled' => $p[0], 'daily_limit' => $p[1], 'unlimited_allowed' => $p[2],
            'created_at' => $now, 'updated_at' => $now,
        ], array_keys(self::DEFAULT_POLICIES), self::DEFAULT_POLICIES));

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $checks = [
            'civil_lookup_role_policies' => [
                'chk_civil_lookup_policy_limit' => 'daily_limit IS NULL OR daily_limit BETWEEN 1 AND 100000',
                'chk_civil_lookup_policy_unlimited' => 'daily_limit IS NOT NULL OR unlimited_allowed',
                'chk_civil_lookup_policy_super_admin' => "role_name <> 'SUPER_ADMIN' OR (daily_limit IS NULL AND unlimited_allowed)",
                'chk_civil_lookup_policy_unlimited_role' => "NOT unlimited_allowed OR role_name = 'SUPER_ADMIN'",
            ],
            'civil_lookup_user_access' => [
                'chk_civil_lookup_user_access' => "access IN ('GRANTED', 'REVOKED')",
            ],
            'civil_lookup_policy_changes' => [
                'chk_civil_lookup_change_subject' => "(subject_type = 'ROLE' AND role_name IS NOT NULL AND user_id IS NULL)
                    OR (subject_type = 'USER' AND user_id IS NOT NULL AND role_name IS NULL)",
                'chk_civil_lookup_change_access' => "(old_access IS NULL OR old_access IN ('GRANTED', 'REVOKED'))
                    AND (new_access IS NULL OR new_access IN ('GRANTED', 'REVOKED'))",
            ],
            'civil_lookup_audit_events' => [
                'chk_civil_lookup_audit_event_type' => 'event_type IN ('.self::EVENT_TYPES.')',
                'chk_civil_lookup_audit_actor_side' => "actor_side IN ('STAFF', 'FAMILY')",
                'chk_civil_lookup_audit_outcome' => "outcome_code ~ '^[A-Z][A-Z0-9_]{1,63}$'",
                'chk_civil_lookup_audit_fingerprint' => "query_fingerprint IS NULL OR query_fingerprint ~ '^[0-9a-f]{64}$'",
                'chk_civil_lookup_audit_ip_hash' => "ip_hash IS NULL OR ip_hash ~ '^[0-9a-f]{64}$'",
            ],
        ];
        foreach ($checks as $table => $constraints) {
            foreach ($constraints as $name => $check) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$check})");
            }
        }

        // OR REPLACE: migrate:fresh drops tables (and so the triggers) but not functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION famboook_civil_lookup_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only: % is not allowed', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;
            SQL);
        foreach (['civil_lookup_policy_changes', 'civil_lookup_audit_events'] as $table) {
            DB::unprepared("CREATE TRIGGER trg_{$table}_append_only BEFORE UPDATE OR DELETE ON {$table} "
                .'FOR EACH ROW EXECUTE FUNCTION famboook_civil_lookup_append_only()');
        }
    }

    public function down(): void
    {
        foreach (['civil_lookup_audit_events', 'civil_lookup_policy_changes'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Cannot drop {$table}: history exists.");
            }
        }
        Schema::dropIfExists('civil_lookup_audit_events');
        Schema::dropIfExists('civil_lookup_daily_usage');
        Schema::dropIfExists('civil_lookup_policy_changes');
        Schema::dropIfExists('civil_lookup_user_access');
        Schema::dropIfExists('civil_lookup_role_policies');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS famboook_civil_lookup_append_only()');
        }
    }
};
