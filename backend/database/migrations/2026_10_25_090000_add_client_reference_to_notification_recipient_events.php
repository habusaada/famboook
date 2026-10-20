<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Follow-up contact idempotency (FB-NOTIFY-01E.3-F3.2; ADR FB-NOTIFY-01 §2f):
// the client's uuid for a CONTACT_RECORDED event. One contact per
// (follow-up, acting user, client_reference): a retried request is a replay,
// never a second event — the key never crosses actors or follow-ups.
//
// Additive and nullable: existing rows keep NULL (no backfill); the
// append-only trigger is untouched (it guards UPDATE / DELETE, not ALTER).
// PostgreSQL also refuses a client_reference on any other event type.
// down() refuses once a client_reference is stored (audit history).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_recipient_events', function (Blueprint $table) {
            $table->uuid('client_reference')->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX notification_recipient_events_contact_reference_unique '
            .'ON notification_recipient_events (followup_id, actor_user_id, client_reference) WHERE client_reference IS NOT NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE notification_recipient_events ADD CONSTRAINT chk_nre_client_reference CHECK (client_reference IS NULL OR (event_type = 'CONTACT_RECORDED' AND actor_user_id IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::table('notification_recipient_events')->whereNotNull('client_reference')->exists()) {
            throw new RuntimeException('Cannot drop notification_recipient_events.client_reference: contact references exist.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notification_recipient_events DROP CONSTRAINT IF EXISTS chk_nre_client_reference');
        }
        DB::statement('DROP INDEX IF EXISTS notification_recipient_events_contact_reference_unique');
        Schema::table('notification_recipient_events', function (Blueprint $table) {
            $table->dropColumn('client_reference');
        });
    }
};
