<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Household membership reconciliation — schema foundation (FB-FAMILY-RECON-01
// WP-03; docs/04 §22b, docs/adr/FB-FAMILY-RECON-01-MEMBERSHIP-RECONCILIATION.md).
// Schema only: no route, handler or role uses it, and nothing can apply one.
//
// change_requests
// - HOUSEHOLD_RECONCILIATION proposes a NEW Family for an existing Person:
//   family_id is NULL (so it never appears in the source household's request
//   history, P-07), the placement is present, created_family_id is set exactly
//   when it is APPLIED. The source Family is target_membership_id's Family.
// - submission_channel gains STAFF, for this type only, and this type is STAFF
//   only. submitted_by_person_id may be NULL for STAFF only (User ≠ Person).
// - One open reconciliation per Person, across submitters. DRAFT is deferred
//   for this type (P-02), so ChangeRequestStatus::open() is every non-terminal
//   state it can have.
//
// Every other CHECK is kept verbatim, and the five replaced ones keep their
// existing meaning for every existing type. CHECKs exist on PostgreSQL only
// (repository convention); the partial unique index exists on both drivers.
//
// down() refuses while any row depends on the new values: history is never
// rewritten. Otherwise it restores the previous schema exactly.
return new class extends Migration
{
    private const OLD_TYPES = "'CONTACT_UPDATE', 'RESIDENCE_UPDATE', 'PERSON_CORRECTION', 'ADD_FAMILY_MEMBER', "
        ."'MEMBERSHIP_CHANGE', 'HOUSEHOLD_HEAD_CHANGE', 'BIRTH_REPORT', 'DEATH_REPORT', 'MARRIAGE_UPDATE', "
        ."'DOCUMENT_UPDATE', 'OTHER', 'REGISTER_FAMILY'";

    private const TYPES = self::OLD_TYPES.", 'HOUSEHOLD_RECONCILIATION'";

    private const PROPOSED_FAMILY = "('REGISTER_FAMILY', 'HOUSEHOLD_RECONCILIATION')";

    private const OPEN_STATUSES = "'SUBMITTED', 'UNDER_REVIEW', 'RETURNED_FOR_CLARIFICATION', 'RESUBMITTED', 'APPROVED'";

    private const INDEX = 'uq_change_request_open_reconciliation_person';

    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';
        if ($pgsql) {
            // Fail fast behind a long transaction instead of queueing every
            // request behind this migration (DB-ADR-061 pattern).
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('ALTER TABLE change_requests ALTER COLUMN submitted_by_person_id DROP NOT NULL');

            $this->replace('chk_change_request_type', 'type IN ('.self::TYPES.')');
            $this->replace('chk_change_request_channel', "submission_channel IN ('FAMILY', 'COORDINATOR', 'STAFF')");
            $this->replace('chk_change_request_family_subject', '(type IN '.self::PROPOSED_FAMILY.') = (family_id IS NULL)');
            $this->replace('chk_change_request_placement', '((type IN '.self::PROPOSED_FAMILY.') = (proposed_clan_id IS NOT NULL)) '
                .'AND (proposed_branch_id IS NULL OR proposed_clan_id IS NOT NULL)');
            $this->replace('chk_change_request_created_family', '(created_family_id IS NOT NULL) = (type IN '.self::PROPOSED_FAMILY." AND status = 'APPLIED')");
            $this->replace('chk_change_request_channel_type', "(type <> 'REGISTER_FAMILY' OR submission_channel = 'COORDINATOR') "
                ."AND ((type = 'HOUSEHOLD_RECONCILIATION') = (submission_channel = 'STAFF'))");

            DB::statement('ALTER TABLE change_requests ADD CONSTRAINT chk_change_request_submitter_person '
                ."CHECK (submitted_by_person_id IS NOT NULL OR submission_channel = 'STAFF')");
            // The Person and the membership the review is about; never a DRAFT (P-02).
            DB::statement('ALTER TABLE change_requests ADD CONSTRAINT chk_change_request_reconciliation_subject '
                ."CHECK (type <> 'HOUSEHOLD_RECONCILIATION' OR (person_id IS NOT NULL AND target_membership_id IS NOT NULL AND status <> 'DRAFT'))");
        } else {
            Schema::table('change_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('submitted_by_person_id')->nullable()->change();
            });
        }

        DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON change_requests (person_id) '
            ."WHERE type = 'HOUSEHOLD_RECONCILIATION' AND status IN (".self::OPEN_STATUSES.')');
    }

    public function down(): void
    {
        // Never rewrite history: refuse while rows rely on the new values.
        if (DB::table('change_requests')->where('type', 'HOUSEHOLD_RECONCILIATION')->exists()
            || DB::table('change_requests')->where('submission_channel', 'STAFF')->exists()
            || DB::table('change_requests')->whereNull('submitted_by_person_id')->exists()) {
            throw new RuntimeException('Cannot roll back the household reconciliation foundation: reconciliation records exist.');
        }

        $pgsql = DB::getDriverName() === 'pgsql';
        if ($pgsql) {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        DB::statement('DROP INDEX '.self::INDEX);

        if ($pgsql) {
            DB::statement('ALTER TABLE change_requests DROP CONSTRAINT chk_change_request_reconciliation_subject');
            DB::statement('ALTER TABLE change_requests DROP CONSTRAINT chk_change_request_submitter_person');

            $this->replace('chk_change_request_channel_type', "type <> 'REGISTER_FAMILY' OR submission_channel = 'COORDINATOR'");
            $this->replace('chk_change_request_created_family', "(created_family_id IS NOT NULL) = (type = 'REGISTER_FAMILY' AND status = 'APPLIED')");
            $this->replace('chk_change_request_placement', "((type = 'REGISTER_FAMILY') = (proposed_clan_id IS NOT NULL)) "
                .'AND (proposed_branch_id IS NULL OR proposed_clan_id IS NOT NULL)');
            $this->replace('chk_change_request_family_subject', "(type = 'REGISTER_FAMILY') = (family_id IS NULL)");
            $this->replace('chk_change_request_channel', "submission_channel IN ('FAMILY', 'COORDINATOR')");
            $this->replace('chk_change_request_type', 'type IN ('.self::OLD_TYPES.')');

            DB::statement('ALTER TABLE change_requests ALTER COLUMN submitted_by_person_id SET NOT NULL');
        } else {
            Schema::table('change_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('submitted_by_person_id')->nullable(false)->change();
            });
        }
    }

    private function replace(string $name, string $check): void
    {
        DB::statement("ALTER TABLE change_requests DROP CONSTRAINT {$name}");
        DB::statement("ALTER TABLE change_requests ADD CONSTRAINT {$name} CHECK ({$check})");
    }
};
