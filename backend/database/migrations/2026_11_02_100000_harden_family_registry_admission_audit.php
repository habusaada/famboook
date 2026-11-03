<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Direct admission of civil-verified children — audit integrity (FB-FAMILY-ADD-01
// ADD-WP-07B-1; docs/adr/FB-FAMILY-ADD-01-DIRECT-ADMISSION.md §9). Additive and
// PostgreSQL-only (repository convention); nothing writes admission rows yet.
//
// - Admission events: a success or a replay names the child (reference fingerprint)
//   and the release; each event type has its own outcome codes; a release id is
//   positive. Discovery rows are not constrained further.
// - TRUNCATE (direct or through CASCADE) is refused on both tables whenever the table
//   holds a row, for every role and every database: no name, setting or role opens it
//   (ADD-WP-07B-1R). The row triggers only cover UPDATE and DELETE. An EMPTY table may
//   be truncated, since there is no history to destroy; that is what keeps the
//   PostgreSQL test harness (TRUNCATE ... RESTART IDENTITY CASCADE through users)
//   working, because no test commits an admission row. Emptiness is read only under
//   READ COMMITTED: TRUNCATE holds ACCESS EXCLUSIVE before this trigger runs, and a
//   fresh snapshot then sees every committed row. Under REPEATABLE READ or SERIALIZABLE
//   an old snapshot could miss rows committed since, so TRUNCATE is refused there.
//   Plain SECURITY INVOKER: the check needs SELECT on the table, and a role without it
//   is refused as well. The function pins search_path = pg_catalog, pg_temp and
//   qualifies every built-in it calls (ADD-WP-07B-1R2): a caller's search_path, own
//   schema or temporary objects cannot replace current_setting(), format(), the <>
//   operator or the table it reads. A superuser or the table owner can still disable or drop any
//   trigger: the application role must be neither (ADR §9a).
//
// The constraints are validated when added: an existing row that breaks one stops
// the migration instead of being kept unchecked. down() refuses once an admission
// event exists.
return new class extends Migration
{
    private const TABLES = ['family_registry_admission_events', 'family_registry_admissions'];

    private const ADMISSION_EVENTS = "'ADMISSION_SUCCEEDED', 'ADMISSION_REPLAYED', 'ADMISSION_REFUSED'";

    private const CHECKS = [
        'chk_registry_admission_event_release' => 'release_id IS NULL OR release_id >= 1',
        'chk_registry_admission_event_admission_reference' => "event_type NOT IN ('ADMISSION_SUCCEEDED', 'ADMISSION_REPLAYED') "
            .'OR (reference_fingerprint IS NOT NULL AND release_id IS NOT NULL)',
        'chk_registry_admission_event_succeeded_outcome' => "event_type <> 'ADMISSION_SUCCEEDED' "
            ."OR outcome_code IN ('PERSON_CREATED', 'PERSON_ATTACHED')",
        'chk_registry_admission_event_replayed_outcome' => "event_type <> 'ADMISSION_REPLAYED' OR outcome_code = 'REPLAYED'",
        'chk_registry_admission_event_refused_outcome' => "event_type <> 'ADMISSION_REFUSED' "
            ."OR outcome_code NOT IN ('PERSON_CREATED', 'PERSON_ATTACHED', 'REPLAYED', 'LISTED')",
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::CHECKS as $name => $check) {
            DB::statement("ALTER TABLE family_registry_admission_events ADD CONSTRAINT {$name} CHECK ({$check})");
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION famboook_family_registry_admission_no_truncate() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = pg_catalog, pg_temp
            AS $$
            DECLARE
                has_rows boolean;
            BEGIN
                IF pg_catalog.current_setting('transaction_isolation') OPERATOR(pg_catalog.<>) 'read committed' THEN
                    RAISE EXCEPTION '% is append-only: TRUNCATE is allowed only on an empty table under READ COMMITTED', TG_TABLE_NAME
                        USING ERRCODE = 'restrict_violation';
                END IF;
                -- TG_RELID::regclass prints schema-qualified under this search_path.
                EXECUTE pg_catalog.format('SELECT EXISTS (SELECT 1 FROM %s)', TG_RELID::pg_catalog.regclass) INTO has_rows;
                IF has_rows THEN
                    RAISE EXCEPTION '% is append-only: TRUNCATE is not allowed', TG_TABLE_NAME
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NULL;
            END
            $$;
        SQL);
        foreach (self::TABLES as $table) {
            DB::unprepared("CREATE TRIGGER trg_{$table}_no_truncate BEFORE TRUNCATE ON {$table} "
                .'FOR EACH STATEMENT EXECUTE FUNCTION famboook_family_registry_admission_no_truncate()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('family_registry_admission_events')->whereRaw('event_type IN ('.self::ADMISSION_EVENTS.')')->exists()
            || DB::table('family_registry_admissions')->exists()) {
            throw new RuntimeException('Cannot remove the admission audit guards: admission history exists.');
        }

        foreach (self::TABLES as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_{$table}_no_truncate ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS famboook_family_registry_admission_no_truncate()');
        foreach (array_keys(self::CHECKS) as $name) {
            DB::statement("ALTER TABLE family_registry_admission_events DROP CONSTRAINT IF EXISTS {$name}");
        }
    }
};
