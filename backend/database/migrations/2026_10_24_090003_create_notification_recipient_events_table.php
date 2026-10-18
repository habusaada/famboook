<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Notification events (FB-NOTIFY-01B.2a; ADR FB-NOTIFY-01 ADR-02/06/10): the
// append-only history of messages, recipients and follow-ups — one table,
// three scopes:
//
// - message-level (no recipient): QUEUED, PUBLISHING_STARTED, PUBLISHED,
//   PUBLISH_FAILED, REQUEUED, CANCELLED, EXPIRED_ARCHIVED;
// - recipient-level: VIEWED, ACKNOWLEDGED, ACKNOWLEDGED_LATE — FAMILY actor
//   only, and a FAMILY actor writes nothing else (no acting for the family);
// - follow-up-level: FOLLOWUP_OPENED, CONTACT_RECORDED, FOLLOWUP_CLOSED,
//   NOTE_CORRECTION.
//
// Composite foreign keys keep the scope coherent: the recipient belongs to
// the event's message, the follow-up to its recipient, and a correction
// targets an event of the same follow-up. A NOTE_CORRECTION suppresses the
// corrected note in application views; the original row is never changed.
//
// Metadata holds codes and counts only (ADR-10). Append-only twice, like
// workflow_events: the model refuses update / delete and, on PostgreSQL, a
// row trigger rejects every UPDATE and DELETE. down() refuses once an event
// exists.
return new class extends Migration
{
    private const MESSAGE_EVENTS = "'QUEUED', 'PUBLISHING_STARTED', 'PUBLISHED', 'PUBLISH_FAILED', 'REQUEUED', 'CANCELLED', 'EXPIRED_ARCHIVED'";

    private const RECIPIENT_EVENTS = "'VIEWED', 'ACKNOWLEDGED', 'ACKNOWLEDGED_LATE'";

    private const FOLLOWUP_EVENTS = "'FOLLOWUP_OPENED', 'CONTACT_RECORDED', 'FOLLOWUP_CLOSED', 'NOTE_CORRECTION'";

    public function up(): void
    {
        Schema::create('notification_recipient_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('notification_messages')->restrictOnDelete();
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->unsignedBigInteger('followup_id')->nullable();
            $table->string('event_type', 30);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_side', 20);
            $table->string('reason_code', 50)->nullable();
            // Staff / coordinator only, on contact and closure; never shown to families.
            $table->text('internal_note')->nullable();
            $table->unsignedBigInteger('corrects_event_id')->nullable();
            // Codes and counts only; never bodies, names, identifiers or contact data.
            $table->jsonb('metadata')->default('{}');
            // Immutable: no updated_at; server time only.
            $table->timestamp('created_at')->useCurrent();

            // Target of the correction's composite foreign key.
            $table->unique(['id', 'followup_id']);
            $table->foreign(['recipient_id', 'message_id'])->references(['id', 'message_id'])
                ->on('notification_recipients')->restrictOnDelete();
            $table->foreign(['followup_id', 'recipient_id'])->references(['id', 'recipient_id'])
                ->on('notification_followups')->restrictOnDelete();
            $table->foreign(['corrects_event_id', 'followup_id'])->references(['id', 'followup_id'])
                ->on('notification_recipient_events')->restrictOnDelete();

            $table->index(['message_id', 'id']);
            $table->index(['recipient_id', 'id']);
            $table->index(['followup_id', 'id']);
            $table->index(['event_type', 'created_at']);
            $table->index('actor_user_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $checks = [
            'chk_nre_event_type' => 'event_type IN ('.self::MESSAGE_EVENTS.', '.self::RECIPIENT_EVENTS.', '.self::FOLLOWUP_EVENTS.')',
            'chk_nre_scope' => '(event_type IN ('.self::MESSAGE_EVENTS.') AND recipient_id IS NULL AND followup_id IS NULL) '
                .'OR (event_type IN ('.self::RECIPIENT_EVENTS.') AND recipient_id IS NOT NULL AND followup_id IS NULL) '
                .'OR (event_type IN ('.self::FOLLOWUP_EVENTS.') AND recipient_id IS NOT NULL AND followup_id IS NOT NULL)',
            'chk_nre_actor_side' => "actor_side IN ('FAMILY', 'STAFF', 'COORDINATOR', 'SYSTEM')",
            // A person acted unless the system did.
            'chk_nre_actor' => "(actor_side = 'SYSTEM') = (actor_user_id IS NULL)",
            // Viewing and acknowledgement are the family's alone (ADR-05).
            'chk_nre_family_actor' => "(actor_side = 'FAMILY') = (event_type IN (".self::RECIPIENT_EVENTS.'))',
            // A correction references an EARLIER event, never itself.
            'chk_nre_correction' => "(event_type = 'NOTE_CORRECTION') = (corrects_event_id IS NOT NULL) AND (corrects_event_id IS NULL OR corrects_event_id < id)",
            'chk_nre_correction_actor' => "event_type <> 'NOTE_CORRECTION' OR actor_side = 'STAFF'",
            'chk_nre_internal_note' => 'internal_note IS NULL OR ('
                ."event_type IN ('CONTACT_RECORDED', 'FOLLOWUP_CLOSED') AND actor_side IN ('STAFF', 'COORDINATOR') "
                .'AND char_length(btrim(internal_note)) >= 1 AND char_length(internal_note) <= 1000)',
            'chk_nre_reason_code' => "reason_code IS NULL OR reason_code ~ '^[A-Z][A-Z0-9_]{1,49}$'",
            'chk_nre_metadata' => "jsonb_typeof(metadata) = 'object'",
        ];
        foreach ($checks as $name => $check) {
            DB::statement("ALTER TABLE notification_recipient_events ADD CONSTRAINT {$name} CHECK ({$check})");
        }

        // A dedicated function for this table only. OR REPLACE: migrate:fresh
        // drops tables (and so the trigger) but not functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION famboook_notification_recipient_events_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'notification_recipient_events is append-only: % is not allowed', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;
            SQL);
        DB::unprepared(
            'CREATE TRIGGER trg_notification_recipient_events_append_only BEFORE UPDATE OR DELETE ON notification_recipient_events '
            .'FOR EACH ROW EXECUTE FUNCTION famboook_notification_recipient_events_append_only()'
        );
    }

    public function down(): void
    {
        // Never drop audit history.
        if (Schema::hasTable('notification_recipient_events') && DB::table('notification_recipient_events')->exists()) {
            throw new RuntimeException('Cannot drop notification_recipient_events: notification audit history exists.');
        }

        Schema::dropIfExists('notification_recipient_events');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS famboook_notification_recipient_events_append_only()');
        }
    }
};
