<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// PWA-1C (docs/04 §55b, docs/11 §30a): the append-only authentication and
// security audit. Schema only — nothing records events yet (PWA-1D onward).
//
// The shape itself keeps secrets out: there is NO free-text column. An
// identifier appears only as a keyed fingerprint (login_key), reasons are
// codes, and metadata is allow-listed by the model. Never a raw National ID,
// a raw mobile, an OTP or a password.
//
// otp_challenge_uuid is a plain reference value with NO foreign key:
// challenges are purged after 90 days while events are retained 24 months.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_security_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('event_type', 40);
            $table->string('outcome', 10);
            $table->string('reason_code', 60)->nullable();
            $table->foreignId('person_id')->nullable()->constrained('persons')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            // Staff (or, later, a coordinator) acting on someone else.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('user_person_link_id')->nullable()->constrained('user_person_links')->restrictOnDelete();
            $table->foreignId('mobile_trust_id')->nullable()->constrained('person_mobile_trusts')->restrictOnDelete();
            $table->uuid('otp_challenge_uuid')->nullable();
            // Fingerprint of a typed identifier (e.g. an attempt that matched
            // no Person). Never the identifier itself.
            $table->char('login_key', 64)->nullable();
            $table->ipAddress('ip')->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            // Allow-listed keys only (see App\Models\AuthSecurityEvent).
            $table->jsonb('metadata')->nullable();
            // Immutable: no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['person_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
            $table->index('login_key');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE auth_security_events ADD CONSTRAINT chk_auth_security_event_codes CHECK ('
                ."event_type ~ '^[A-Z][A-Z0-9_]{1,39}$' AND "
                ."outcome IN ('SUCCESS', 'FAILURE', 'DENIED') AND "
                ."(reason_code IS NULL OR reason_code ~ '^[A-Z][A-Z0-9_]{1,59}$'))"
            );
            DB::statement(
                'ALTER TABLE auth_security_events ADD CONSTRAINT chk_auth_security_event_hashes CHECK ('
                ."(login_key IS NULL OR login_key ~ '^[0-9a-f]{64}$') AND "
                ."(user_agent_hash IS NULL OR user_agent_hash ~ '^[0-9a-f]{64}$'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_security_events');
    }
};
