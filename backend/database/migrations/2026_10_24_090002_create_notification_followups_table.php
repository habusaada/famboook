<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Notification follow-ups (FB-NOTIFY-01B.2a; ADR FB-NOTIFY-01 ADR-05/06/07,
// D12): at most one row per recipient and stage.
//
// - FIRST: NORMAL unviewed 72 h after published_at (UNVIEWED), or IMPORTANT
//   unacknowledged 24 h after it (UNACKNOWLEDGED). SECOND: IMPORTANT still
//   unacknowledged 72 h after it. FIRST and SECOND may both be OPEN.
// - Outcomes stay distinct: SATISFIED (the family), EXPIRED (the expiry
//   sweep; never reopened), CLOSED_MANUALLY (an authorized user with a reason
//   code). A manual closure never touches the recipient's view or
//   acknowledgement.
// - No assignee column: responsibility is the CURRENT permission and scope
//   (design §10).
//
// Restrict foreign keys only. down() refuses once a follow-up exists.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_followups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('recipient_id')->constrained('notification_recipients')->restrictOnDelete();
            $table->string('stage', 10);
            $table->string('trigger_reason', 20);
            $table->timestamp('due_at');
            $table->string('status', 10)->default('OPEN');
            $table->string('outcome', 20)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('close_reason_code', 50)->nullable();
            // created_at is the moment the follow-up opened.
            $table->timestamps();

            $table->unique(['recipient_id', 'stage']);
            // Target of the events' composite foreign key (follow-up of THIS recipient).
            $table->unique(['id', 'recipient_id']);
        });

        DB::statement("CREATE INDEX notification_followups_open_due_index ON notification_followups (due_at) WHERE status = 'OPEN'");

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $manual = "outcome IS NOT DISTINCT FROM 'CLOSED_MANUALLY'";
        $checks = [
            'chk_nf_stage' => "stage IN ('FIRST', 'SECOND')",
            'chk_nf_trigger_reason' => "trigger_reason IN ('UNVIEWED', 'UNACKNOWLEDGED')",
            // Only IMPORTANT has a SECOND stage.
            'chk_nf_stage_reason' => "stage = 'FIRST' OR trigger_reason = 'UNACKNOWLEDGED'",
            'chk_nf_status' => "status IN ('OPEN', 'CLOSED')",
            'chk_nf_outcome' => "outcome IS NULL OR outcome IN ('SATISFIED', 'EXPIRED', 'CLOSED_MANUALLY')",
            'chk_nf_closed' => "(status = 'CLOSED') = (closed_at IS NOT NULL) AND (status = 'CLOSED') = (outcome IS NOT NULL)",
            // Only a manual closure has a closing user and a reason code.
            'chk_nf_manual' => "({$manual}) = (closed_by_user_id IS NOT NULL) AND ({$manual}) = (close_reason_code IS NOT NULL)",
            'chk_nf_close_reason' => "close_reason_code IS NULL OR close_reason_code ~ '^[A-Z][A-Z0-9_]{1,49}$'",
        ];
        foreach ($checks as $name => $check) {
            DB::statement("ALTER TABLE notification_followups ADD CONSTRAINT {$name} CHECK ({$check})");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_followups') && DB::table('notification_followups')->exists()) {
            throw new RuntimeException('Cannot drop notification_followups: follow-up history exists.');
        }

        Schema::dropIfExists('notification_followups');
    }
};
