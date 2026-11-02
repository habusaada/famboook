<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Direct admission of civil-verified children — schema foundation (FB-FAMILY-ADD-01
// ADD-WP-04; docs/adr/FB-FAMILY-ADD-01-DIRECT-ADMISSION.md §9, docs/04 §22a). Schema
// only: nothing reads or writes these tables yet.
//
// family_registry_admissions: evidence, one row per successful admission.
// family_registry_admission_events: attempts (D-05), kept apart from the civil lookup audit.
//
// Never stored: a source record or version id (D-02), a National ID, a registry
// payload, a name or a request body. release_id has no foreign key: identity_ref stays
// outside the application schema. Both tables are append-only: on PostgreSQL a row
// trigger rejects every UPDATE and DELETE. CHECKs and triggers exist on PostgreSQL only
// (repository convention). down() refuses once history exists.
return new class extends Migration
{
    private const TABLES = ['family_registry_admission_events', 'family_registry_admissions'];

    public function up(): void
    {
        Schema::create('family_registry_admissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('family_id')->constrained('families')->restrictOnDelete();
            $table->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            $table->foreignId('family_membership_id')->unique()->constrained('family_memberships')->restrictOnDelete();
            // The account AND the household head it acted as (User ≠ Person).
            $table->foreignId('admitted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('admitted_by_person_id')->constrained('persons')->restrictOnDelete();
            $table->uuid('client_reference');
            $table->string('outcome_type', 20);
            $table->string('parent_link', 10);
            $table->char('reference_fingerprint', 64);
            $table->unsignedSmallInteger('reference_key_version');
            $table->unsignedBigInteger('release_id');
            $table->string('declaration_version', 40);
            $table->timestampTz('declared_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['admitted_by', 'client_reference']);
            $table->index('family_id');
            $table->index('person_id');
        });

        Schema::create('family_registry_admission_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 30);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('family_id')->constrained('families')->restrictOnDelete();
            // May hold the internal reason of a refusal: never sent to a client.
            $table->string('outcome_code', 64);
            $table->unsignedBigInteger('release_id')->nullable();
            $table->char('reference_fingerprint', 64)->nullable();
            $table->unsignedSmallInteger('reference_key_version')->nullable();
            $table->unsignedInteger('result_count')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['family_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $checks = [
            'family_registry_admissions' => [
                'chk_registry_admission_outcome' => "outcome_type IN ('PERSON_CREATED', 'PERSON_ATTACHED')",
                'chk_registry_admission_parent_link' => "parent_link IN ('FATHER', 'MOTHER')",
                'chk_registry_admission_fingerprint' => "reference_fingerprint ~ '^[0-9a-f]{64}$'",
                'chk_registry_admission_key_version' => 'reference_key_version >= 1',
                'chk_registry_admission_release' => 'release_id >= 1',
                'chk_registry_admission_declaration' => "btrim(declaration_version) <> ''",
            ],
            'family_registry_admission_events' => [
                'chk_registry_admission_event_type' => "event_type IN ('DISCOVERY_LISTED', 'ADMISSION_SUCCEEDED', 'ADMISSION_REFUSED', 'ADMISSION_REPLAYED')",
                'chk_registry_admission_event_outcome' => "outcome_code ~ '^[A-Z][A-Z0-9_]{1,63}$'",
                'chk_registry_admission_event_fingerprint' => '(reference_fingerprint IS NULL) = (reference_key_version IS NULL) '
                    ."AND (reference_fingerprint IS NULL OR (reference_fingerprint ~ '^[0-9a-f]{64}$' AND reference_key_version >= 1))",
                'chk_registry_admission_event_discovery' => "event_type <> 'DISCOVERY_LISTED' OR reference_fingerprint IS NULL",
                'chk_registry_admission_event_result_count' => "result_count IS NULL OR event_type = 'DISCOVERY_LISTED'",
                'chk_registry_admission_event_ip_hash' => "ip_hash IS NULL OR ip_hash ~ '^[0-9a-f]{64}$'",
            ],
        ];
        foreach ($checks as $table => $constraints) {
            foreach ($constraints as $name => $check) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$check})");
            }
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION famboook_family_registry_admission_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only: % is not allowed', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'restrict_violation';
            END
            $$;
        SQL);
        foreach (self::TABLES as $table) {
            DB::unprepared("CREATE TRIGGER trg_{$table}_append_only BEFORE UPDATE OR DELETE ON {$table} "
                .'FOR EACH ROW EXECUTE FUNCTION famboook_family_registry_admission_append_only()');
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Cannot drop {$table}: history exists.");
            }
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS famboook_family_registry_admission_append_only()');
        }
    }
};
