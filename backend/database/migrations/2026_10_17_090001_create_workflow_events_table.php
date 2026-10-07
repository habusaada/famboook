<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Workflow events (PWA-5a; docs/04 §33–§35 as implemented by DB-ADR-058): the
// append-only transition history of a workflow — today only Change Requests
// (morph alias 'change_request'). The workflow's CURRENT state lives on the
// workflowable row; this table is its history, distinct from audit and from
// the Family Activity Log (WF-ADR-002/003).
//
// docs/04 §33's single `comment` is split into `public_message` (visible to
// the family: a clarification request, the family's response, a rejection
// message) and `internal_note` (Staff only) — AE-17, docs/06 §71–§72. No
// payload copies: metadata holds allow-listed codes only.
//
// Append-only is enforced twice (AE-18, strengthened by the owner): the
// WorkflowEvent model refuses update / delete, and on PostgreSQL a dedicated
// row trigger rejects every UPDATE and DELETE on this table — even from code
// that bypasses Eloquent. INSERT stays allowed. TRUNCATE (a table-owner
// operation no application path issues; the PostgreSQL test harness truncates
// with CASCADE through the users foreign key) is not trigger-blocked. down()
// refuses once an event exists; after Production use the rollback is a CODE
// rollback.
return new class extends Migration
{
    private const STATUSES = "'DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'RETURNED_FOR_CLARIFICATION', 'RESUBMITTED', "
        ."'APPROVED', 'REJECTED', 'APPLIED', 'CANCELLED'";

    private const EVENT_TYPES = "'SUBMITTED', 'REVIEW_STARTED', 'RETURNED', 'RESUBMITTED', 'APPROVED', 'REJECTED', "
        ."'APPLIED', 'APPLY_FAILED', 'CANCELLED'";

    public function up(): void
    {
        Schema::create('workflow_events', function (Blueprint $table) {
            $table->id();
            // Morph alias (never a PHP class name) and the workflowable's id.
            $table->string('workflowable_type', 50);
            $table->unsignedBigInteger('workflowable_id');
            // NULL only for a request's creation (V1 submits directly, AE-2).
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('event_type', 30);
            // Restrict: an actor of recorded history is never deleted (and a
            // SET NULL would be an UPDATE the trigger refuses anyway).
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_side', 10);
            // A controlled code (e.g. the rejection reason); never free text.
            $table->string('reason_code', 40)->nullable();
            // Family-visible text, and the Staff-only note (AE-17).
            $table->text('public_message')->nullable();
            $table->text('internal_note')->nullable();
            // Allow-listed codes only (WorkflowEventRecorder); never payload values.
            $table->jsonb('metadata')->nullable();
            // Immutable: no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workflowable_type', 'workflowable_id', 'id']);
            $table->index('actor_user_id');
            $table->index('created_at');
            $table->index('to_status');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $checks = [
            'chk_workflow_event_workflowable' => "workflowable_type IN ('change_request')",
            'chk_workflow_event_statuses' => '(from_status IS NULL OR from_status IN ('.self::STATUSES.')) AND to_status IN ('.self::STATUSES.')',
            'chk_workflow_event_type' => 'event_type IN ('.self::EVENT_TYPES.')',
            // Only a failed apply records an event without a state change.
            'chk_workflow_event_change' => "(event_type = 'APPLY_FAILED') = (from_status IS NOT DISTINCT FROM to_status)",
            'chk_workflow_event_actor_side' => "actor_side IN ('FAMILY', 'STAFF', 'SYSTEM')",
            // A person acted unless the system did.
            'chk_workflow_event_actor' => "actor_side = 'SYSTEM' OR actor_user_id IS NOT NULL",
            'chk_workflow_event_reason_code' => "reason_code IS NULL OR reason_code ~ '^[A-Z][A-Z0-9_]{1,39}$'",
            'chk_workflow_event_messages' => '(public_message IS NULL OR char_length(public_message) BETWEEN 1 AND 2000) '
                .'AND (internal_note IS NULL OR char_length(internal_note) BETWEEN 1 AND 2000)',
            // Families never write Staff-only notes.
            'chk_workflow_event_internal_note' => "internal_note IS NULL OR actor_side = 'STAFF'",
            'chk_workflow_event_metadata' => "metadata IS NULL OR jsonb_typeof(metadata) = 'object'",
        ];
        foreach ($checks as $name => $check) {
            DB::statement("ALTER TABLE workflow_events ADD CONSTRAINT {$name} CHECK ({$check})");
        }

        // Append-only at the database level. A dedicated function for this
        // table only; no event trigger, nothing that touches other tables.
        // OR REPLACE: migrate:fresh drops tables (and so the triggers) but not
        // functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION famboook_workflow_events_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'workflow_events is append-only: % is not allowed', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;
            SQL);
        DB::unprepared(
            'CREATE TRIGGER trg_workflow_events_append_only BEFORE UPDATE OR DELETE ON workflow_events '
            .'FOR EACH ROW EXECUTE FUNCTION famboook_workflow_events_append_only()'
        );
    }

    public function down(): void
    {
        // Never drop real workflow history (the SELF_OTP migration's pattern).
        if (Schema::hasTable('workflow_events') && DB::table('workflow_events')->exists()) {
            throw new RuntimeException('Cannot drop workflow_events: workflow history exists.');
        }

        // Dropping the table drops its triggers; then the function.
        Schema::dropIfExists('workflow_events');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS famboook_workflow_events_append_only()');
        }
    }
};
