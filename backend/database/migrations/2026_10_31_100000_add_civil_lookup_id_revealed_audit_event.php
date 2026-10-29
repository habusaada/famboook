<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// FB-CIVIL-02G (docs/adr/FB-CIVIL-02B-FAMILY-COORDINATOR-LOOKUP.md §B-5b): the
// eye-icon reveal of one complete National ID is audited as ID_REVEALED (the
// event, actor, context, release and record ids — never the value). The audit
// table's PostgreSQL CHECK lists the event types, so it is replaced with the
// same list plus ID_REVEALED. The table stays append-only (its trigger is
// untouched); no row is changed.
return new class extends Migration
{
    private const BEFORE = "'SEARCH_BY_ID', 'SEARCH_BY_NAME', 'SEARCH_BY_PARTS', 'SEARCH_PAGE', 'RECORD_OPENED', "
        ."'LINK_OPENED', 'LINKS_LISTED', 'LOOKUP_REFUSED'";

    public function up(): void
    {
        $this->replaceCheck(self::BEFORE.", 'ID_REVEALED'");
    }

    /** Refused by PostgreSQL while ID_REVEALED rows exist (the audit is append-only: they are never deleted). */
    public function down(): void
    {
        $this->replaceCheck(self::BEFORE);
    }

    private function replaceCheck(string $types): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE civil_lookup_audit_events DROP CONSTRAINT chk_civil_lookup_audit_event_type');
        DB::statement("ALTER TABLE civil_lookup_audit_events ADD CONSTRAINT chk_civil_lookup_audit_event_type CHECK (event_type IN ({$types}))");
    }
};
