<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Notification recipients (FB-NOTIFY-01B.2a; ADR FB-NOTIFY-01 ADR-03/05/07):
// the FAMILY is the recipient — one row per message and family, written by
// the publication transaction together with PUBLISHED and never rewritten
// later (head, account and family status are publication-time snapshots).
//
// - viewed_at: the first opening of the details, by the family side only.
// - acknowledged_at: the explicit IMPORTANT acknowledgement (the level rule
//   is enforced by the Domain Action: it spans two tables); it implies a
//   view. acknowledged_late: acknowledged at or after expires_at.
// - staff and coordinators never write these columns (ADR-05).
//
// Restrict foreign keys only. down() refuses once a recipient exists.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('notification_messages')->restrictOnDelete();
            $table->foreignId('family_id')->constrained('families')->restrictOnDelete();
            $table->string('family_status_at_publication', 20);
            // The household head at publication; NULL when the family had none.
            $table->foreignId('head_person_id')->nullable()->constrained('persons')->restrictOnDelete();
            // ADR-03 reporting state "No activated family account", at publication.
            $table->boolean('had_family_account');
            $table->timestamp('viewed_at')->nullable();
            $table->foreignId('viewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('acknowledged_late')->default(false);
            $table->timestamps();

            $table->unique(['message_id', 'family_id']);
            // Target of the events' composite foreign key (recipient of THIS message).
            $table->unique(['id', 'message_id']);
            $table->index(['family_id', 'message_id']);
        });

        // Unread badge and pending-acknowledgement lookups (partial: SQLite too).
        DB::statement('CREATE INDEX notification_recipients_unviewed_index ON notification_recipients (family_id) WHERE viewed_at IS NULL');
        DB::statement('CREATE INDEX notification_recipients_unacknowledged_index ON notification_recipients (message_id) WHERE acknowledged_at IS NULL');

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $checks = [
            'chk_nr_family_status' => "family_status_at_publication IN ('ACTIVE', 'INACTIVE', 'ARCHIVED')",
            'chk_nr_view_actor' => '(viewed_at IS NULL) = (viewed_by_user_id IS NULL)',
            'chk_nr_ack_actor' => '(acknowledged_at IS NULL) = (acknowledged_by_user_id IS NULL)',
            // Acknowledging includes opening the details.
            'chk_nr_ack_requires_view' => 'acknowledged_at IS NULL OR (viewed_at IS NOT NULL AND acknowledged_at >= viewed_at)',
            'chk_nr_late' => 'NOT acknowledged_late OR acknowledged_at IS NOT NULL',
        ];
        foreach ($checks as $name => $check) {
            DB::statement("ALTER TABLE notification_recipients ADD CONSTRAINT {$name} CHECK ({$check})");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_recipients') && DB::table('notification_recipients')->exists()) {
            throw new RuntimeException('Cannot drop notification_recipients: notification history exists.');
        }

        Schema::dropIfExists('notification_recipients');
    }
};
