<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Notification messages (FB-NOTIFY-01B.2a; ADR FB-NOTIFY-01 ADR-01/02/08/09,
// design alignment §2a): one SYSTEM notice or ANNOUNCEMENT and its
// publication lifecycle. Not Laravel's `notifications` table (User is
// Notifiable). Recipients are families (notification_recipients); the
// history is notification_recipient_events.
//
// - kind / sender_side / sender_user_id / sender_scope / origin form exactly
//   three valid shapes: SYSTEM (system, no user, a unique workflow_events
//   origin), ANNOUNCEMENT by STAFF (a user, no scope) and ANNOUNCEMENT by a
//   COORDINATOR (a user and the scope snapshot). The family-visible label is
//   derived from sender_side, never stored.
// - published_at and recipient_count exist only once publication succeeded;
//   ARCHIVED happens only at expiry (no manual archive, D4); CANCELLED never
//   after publication (from DRAFT, QUEUED or PUBLISH_FAILED, D3).
// - publish_failed_at / publish_failure_code keep the LATEST failure even
//   after a retry or a cancellation; the full history is in the events.
// - expires_at is the sender's absolute choice, never shifted.
//
// Restrict foreign keys only: no history is cascaded away. down() refuses
// once a message exists (the rollback after use is a CODE rollback).
return new class extends Migration
{
    private const STATUSES = "'DRAFT', 'QUEUED', 'PUBLISHING', 'PUBLISHED', 'PUBLISH_FAILED', 'ARCHIVED', 'CANCELLED'";

    public function up(): void
    {
        Schema::create('notification_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // NTF-000001 (D1): from notification_message_code_seq; never the id.
            $table->string('notice_code', 20)->unique();
            $table->string('kind', 20);
            $table->string('sender_side', 20);
            $table->string('level', 20)->default('NORMAL');
            $table->string('status', 20)->default('DRAFT');
            $table->string('title', 150);
            $table->text('body');
            // A family-relative link to an authorized view, or none.
            $table->string('action_path', 255)->nullable();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->restrictOnDelete();
            // The coordinator's assignment snapshot at sending (ADR-10).
            $table->jsonb('sender_scope')->nullable();
            // The requested audience criteria; resolved only on the server.
            $table->jsonb('audience')->default('{}');
            // Idempotent publication request, as for change requests.
            $table->uuid('client_reference')->nullable();
            // SYSTEM only: the originating workflow event, at most one message each (ADR-09).
            $table->foreignId('origin_workflow_event_id')->nullable()->unique()->constrained('workflow_events')->restrictOnDelete();
            // Historical fact of a successful publication (D2).
            $table->unsignedInteger('recipient_count')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('publishing_started_at')->nullable();
            // Worker fencing token: only the worker that claimed it may finish it.
            $table->uuid('publishing_token')->nullable();
            $table->unsignedSmallInteger('publish_attempts')->default(0);
            $table->timestamp('publish_failed_at')->nullable();
            // A controlled code, never exception text.
            $table->string('publish_failure_code', 50)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason_code', 50)->nullable();
            $table->timestamps();

            // NULLs are distinct: a message without a client reference never collides.
            $table->unique(['sender_user_id', 'client_reference']);
            $table->index(['sender_user_id', 'created_at']);
            $table->index(['status', 'published_at']);
            $table->index(['status', 'expires_at']);
            $table->index(['status', 'queued_at']);
        });

        // PostgreSQL-only (repository convention): the code sequence and the
        // CHECK constraints. SQLite (test suite) relies on the enums, the
        // models and the future Domain Actions.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE SEQUENCE IF NOT EXISTS notification_message_code_seq AS bigint START WITH 1 INCREMENT BY 1 NO CYCLE');
        DB::statement('ALTER SEQUENCE notification_message_code_seq OWNED BY notification_messages.notice_code');

        $published = "status IN ('PUBLISHED', 'ARCHIVED')";
        $checks = [
            'chk_nm_notice_code' => "notice_code ~ '^NTF-[0-9]{6,}$'",
            'chk_nm_kind' => "kind IN ('SYSTEM', 'ANNOUNCEMENT')",
            'chk_nm_sender_side' => "sender_side IN ('SYSTEM', 'STAFF', 'COORDINATOR')",
            'chk_nm_level' => "level IN ('NORMAL', 'IMPORTANT')",
            'chk_nm_status' => 'status IN ('.self::STATUSES.')',
            'chk_nm_title' => 'char_length(btrim(title)) >= 1',
            'chk_nm_body' => 'char_length(btrim(body)) >= 1 AND char_length(body) <= 2000',
            // Family-relative path segments only: no scheme, host, query or "..".
            'chk_nm_action_path' => "action_path IS NULL OR action_path ~ '^/family(/[A-Za-z0-9_-]+)+$'",
            'chk_nm_json' => "jsonb_typeof(audience) = 'object' AND (sender_scope IS NULL OR jsonb_typeof(sender_scope) = 'object')",
            'chk_nm_codes' => "(publish_failure_code IS NULL OR publish_failure_code ~ '^[A-Z][A-Z0-9_]{1,49}$') "
                ."AND (cancellation_reason_code IS NULL OR cancellation_reason_code ~ '^[A-Z][A-Z0-9_]{1,49}$')",

            // The three valid sender shapes (design §6).
            'chk_nm_kind_side' => "(kind = 'SYSTEM') = (sender_side = 'SYSTEM')",
            'chk_nm_sender_user' => "(sender_side = 'SYSTEM') = (sender_user_id IS NULL)",
            'chk_nm_sender_scope' => "(sender_side = 'COORDINATOR') = (sender_scope IS NOT NULL)",
            'chk_nm_origin' => "(kind = 'SYSTEM') = (origin_workflow_event_id IS NOT NULL)",
            'chk_nm_system_reference' => "kind <> 'SYSTEM' OR client_reference IS NULL",
            // SYSTEM messages enter at QUEUED, never DRAFT (ADR-08).
            'chk_nm_system_no_draft' => "kind <> 'SYSTEM' OR status <> 'DRAFT'",

            // Publication lifecycle (ADR-08).
            'chk_nm_draft' => "status <> 'DRAFT' OR (queued_at IS NULL AND publishing_started_at IS NULL AND publish_attempts = 0 AND publish_failed_at IS NULL)",
            // smallint is signed on PostgreSQL.
            'chk_nm_attempts' => 'publish_attempts >= 0',
            'chk_nm_queued' => "status IN ('DRAFT', 'CANCELLED') OR queued_at IS NOT NULL",
            'chk_nm_publishing' => "status <> 'PUBLISHING' OR (publishing_started_at IS NOT NULL AND publishing_token IS NOT NULL)",
            'chk_nm_failure' => '(publish_failed_at IS NULL) = (publish_failure_code IS NULL)',
            'chk_nm_failed' => "status <> 'PUBLISH_FAILED' OR publish_failed_at IS NOT NULL",
            'chk_nm_published' => "({$published}) = (published_at IS NOT NULL)",
            'chk_nm_recipient_count' => "({$published}) = (recipient_count IS NOT NULL) AND (recipient_count IS NULL OR recipient_count >= 1)",
            'chk_nm_expiry' => 'expires_at IS NULL OR published_at IS NULL OR expires_at > published_at',
            // Archived only by the expiry sweep (D4).
            'chk_nm_archived' => "(status = 'ARCHIVED') = (archived_at IS NOT NULL)",
            'chk_nm_archive_on_expiry' => "status <> 'ARCHIVED' OR (expires_at IS NOT NULL AND archived_at >= expires_at)",
            // Cancelled only before publication (D3), always by an authorized user.
            'chk_nm_cancelled' => "(status = 'CANCELLED') = (cancelled_at IS NOT NULL)",
            'chk_nm_cancel_actor' => '(cancelled_at IS NULL) = (cancelled_by_user_id IS NULL) '
                .'AND (cancellation_reason_code IS NULL OR cancelled_at IS NOT NULL)',
        ];
        foreach ($checks as $name => $check) {
            DB::statement("ALTER TABLE notification_messages ADD CONSTRAINT {$name} CHECK ({$check})");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_messages') && DB::table('notification_messages')->exists()) {
            throw new RuntimeException('Cannot drop notification_messages: notification history exists.');
        }

        Schema::dropIfExists('notification_messages');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP SEQUENCE IF EXISTS notification_message_code_seq');
        }
    }
};
