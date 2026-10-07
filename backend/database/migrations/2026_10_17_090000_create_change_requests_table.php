<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Change Requests (PWA-5a; docs/04 §37 as implemented by DB-ADR-058). A
// Change Request is a family's PROPOSAL of one typed change to its canonical
// registry, with its review decision and apply outcome — never canonical data
// itself (docs/03 §67). Canonical data changes only when an APPROVED request
// is APPLIED through a Domain Action (PWA-5b).
//
// Deviations from the docs/04 §37 sketch (DB-ADR-058): `type` is a varchar
// checked against the code registry (no change_request_types table, AE-1);
// `uuid` is the public identifier and `request_code` (CRQ-000001) the human
// reference (AE-6); `target_membership_id` holds the member a request names
// (docs/11 FP-ADR-063); `base_fingerprint` protects against stale writes
// (AE-7); internal notes and family-visible messages live on workflow_events
// (AE-17), not here; risk level is derived from the type in code.
//
// Additive: no backfill, no row is created here. History is never deleted:
// down() refuses once a request exists — the rollback after Production use
// is a CODE rollback that leaves this table in place.
return new class extends Migration
{
    private const STATUSES = "'DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'RETURNED_FOR_CLARIFICATION', 'RESUBMITTED', "
        ."'APPROVED', 'REJECTED', 'APPLIED', 'CANCELLED'";

    // The approved base list (docs/03 §68). The PFP-008 proposals are NOT
    // approved and are deliberately absent; adding one needs its own approval
    // and a migration.
    private const TYPES = "'CONTACT_UPDATE', 'RESIDENCE_UPDATE', 'PERSON_CORRECTION', 'ADD_FAMILY_MEMBER', "
        ."'MEMBERSHIP_CHANGE', 'HOUSEHOLD_HEAD_CHANGE', 'BIRTH_REPORT', 'DEATH_REPORT', 'MARRIAGE_UPDATE', "
        ."'DOCUMENT_UPDATE', 'OTHER'";

    private const REJECTION_REASONS = "'INSUFFICIENT_INFORMATION', 'CANNOT_VERIFY', 'DATA_ALREADY_CORRECT', "
        ."'DUPLICATE_REQUEST', 'DATA_CHANGED', 'NO_LONGER_APPLICABLE', 'OTHER'";

    public function up(): void
    {
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            // Public identifier for every API lookup; the id never leaves the server.
            $table->uuid('uuid')->unique();
            // Human reference, generated server-side from change_request_code_seq.
            $table->string('request_code', 20)->unique();
            $table->foreignId('family_id')->constrained('families')->restrictOnDelete();
            $table->string('type', 40);
            // The version of the type's submitted_data schema.
            $table->unsignedSmallInteger('payload_version');
            $table->string('status', 30);
            // The targeted Person and, for a member, the membership (FP-ADR-063).
            $table->foreignId('person_id')->nullable()->constrained('persons')->restrictOnDelete();
            $table->foreignId('target_membership_id')->nullable()->constrained('family_memberships')->restrictOnDelete();
            // Proposed values only, validated per type; immutable after submission (AE-5).
            $table->jsonb('submitted_data');
            // The requester's own explanation (plain text).
            $table->text('reason')->nullable();
            // Keyed fingerprint of the canonical values the request is based on
            // (FingerprintContext::CHANGE_REQUEST_BASE). Never returned to a client.
            $table->char('base_fingerprint', 64)->nullable();
            $table->unsignedSmallInteger('base_key_version')->nullable();
            // The requesting account AND the Person it acted as (User ≠ Person).
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by_person_id')->constrained('persons')->restrictOnDelete();
            // Client-generated idempotency key for creation (one per form).
            $table->uuid('client_reference')->nullable();
            $table->timestamp('submitted_at')->nullable();
            // Actor columns restrict deletion: the audit trail keeps its actors.
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason_code', 40)->nullable();
            // The family-visible rejection message (docs/03 §76); internal notes
            // live on workflow_events.internal_note.
            $table->text('rejection_reason')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            // Refused or failed apply attempts; the request stays APPROVED (docs/04 §43).
            $table->unsignedSmallInteger('apply_failure_count')->default(0);
            $table->timestamp('last_apply_failed_at')->nullable();
            $table->timestamps();

            $table->unique(['submitted_by', 'client_reference']);
            $table->index(['family_id', 'status']);
            $table->index(['status', 'submitted_at']);
            $table->index('type');
            $table->index('person_id');
            $table->index('target_membership_id');
        });

        // PostgreSQL-only (repository convention): the request-code sequence and
        // the CHECK constraints. On SQLite (test suite) ChangeRequestCodes, the
        // enums, the model guards and the Domain Actions carry the same rules.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Owned by the column, so the sequence goes with the table (migrate:fresh
        // drops tables only). IF NOT EXISTS: an orphan from an interrupted
        // fresh run is adopted rather than failing the migration.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS change_request_code_seq AS bigint START WITH 1 INCREMENT BY 1 NO CYCLE');
        DB::statement('ALTER SEQUENCE change_request_code_seq OWNED BY change_requests.request_code');

        $checks = [
            'chk_change_request_status' => 'status IN ('.self::STATUSES.')',
            'chk_change_request_type' => 'type IN ('.self::TYPES.')',
            'chk_change_request_code' => "request_code ~ '^CRQ-[0-9]{6,}$'",
            'chk_change_request_payload_version' => 'payload_version >= 1',
            // Only a DRAFT is unsubmitted (DRAFT is unused in V1, AE-2).
            'chk_change_request_submitted' => "(status = 'DRAFT') = (submitted_at IS NULL)",
            // Review start: actor and time together; every state a reviewer
            // has touched carries them (a SUBMITTED request cancelled before
            // review has none).
            'chk_change_request_reviewed' => '((reviewed_at IS NULL) = (reviewed_by IS NULL)) AND ('
                ."status NOT IN ('UNDER_REVIEW', 'RETURNED_FOR_CLARIFICATION', 'RESUBMITTED', 'APPROVED', 'REJECTED', 'APPLIED') "
                .'OR reviewed_at IS NOT NULL)',
            // Approval: actor and time together; required for APPROVED and
            // APPLIED; otherwise present only on a request rejected after
            // approval (AE-4) — so a CANCELLED request was never approved (AE-3).
            'chk_change_request_approved' => '((approved_at IS NULL) = (approved_by IS NULL)) AND ('
                ."(status IN ('APPROVED', 'APPLIED') AND approved_at IS NOT NULL) "
                ."OR (status = 'REJECTED') "
                ."OR (status NOT IN ('APPROVED', 'APPLIED', 'REJECTED') AND approved_at IS NULL))",
            // Rejection: actor, time and a controlled reason code exactly when
            // REJECTED; the family-visible message only on a rejection.
            'chk_change_request_rejected' => '('
                ."status = 'REJECTED' AND rejected_at IS NOT NULL AND rejected_by IS NOT NULL AND rejection_reason_code IS NOT NULL"
                .') OR ('
                ."status <> 'REJECTED' AND rejected_at IS NULL AND rejected_by IS NULL AND rejection_reason_code IS NULL AND rejection_reason IS NULL"
                .')',
            'chk_change_request_rejection_reason' => 'rejection_reason_code IS NULL OR rejection_reason_code IN ('.self::REJECTION_REASONS.')',
            // AE-4: a request rejected after approval is rejected ONLY as
            // NO_LONGER_APPLICABLE, and only after an apply attempt was refused.
            'chk_change_request_rejected_after_approval' => "status <> 'REJECTED' OR approved_at IS NULL OR ("
                ."rejection_reason_code = 'NO_LONGER_APPLICABLE' AND last_apply_failed_at IS NOT NULL)",
            'chk_change_request_applied' => "(status = 'APPLIED' AND applied_at IS NOT NULL AND applied_by IS NOT NULL) "
                ."OR (status <> 'APPLIED' AND applied_at IS NULL AND applied_by IS NULL)",
            'chk_change_request_cancelled' => "(status = 'CANCELLED' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL) "
                ."OR (status <> 'CANCELLED' AND cancelled_at IS NULL AND cancelled_by IS NULL)",
            'chk_change_request_base' => '((base_fingerprint IS NULL) = (base_key_version IS NULL)) AND ('
                ."base_fingerprint IS NULL OR (base_fingerprint ~ '^[0-9a-f]{64}$' AND base_key_version >= 1))",
            'chk_change_request_apply_failures' => '(apply_failure_count = 0) = (last_apply_failed_at IS NULL)',
            'chk_change_request_submitted_data' => "jsonb_typeof(submitted_data) = 'object'",
        ];

        foreach ($checks as $name => $check) {
            DB::statement("ALTER TABLE change_requests ADD CONSTRAINT {$name} CHECK ({$check})");
        }
    }

    public function down(): void
    {
        // Never drop real request history (the SELF_OTP migration's pattern).
        if (Schema::hasTable('change_requests') && DB::table('change_requests')->exists()) {
            throw new RuntimeException('Cannot drop change_requests: change request history exists.');
        }

        Schema::dropIfExists('change_requests');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP SEQUENCE IF EXISTS change_request_code_seq');
        }
    }
};
