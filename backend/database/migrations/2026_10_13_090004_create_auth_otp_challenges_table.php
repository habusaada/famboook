<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// PWA-1C (docs/04 §55b): OTP challenges. Schema only — nothing generates,
// sends or verifies a code yet (PWA-1E). code_hash is a keyed hash (context
// OTP_CODE); the plaintext code is never stored.
//
// "One open challenge per Person and purpose" is enforced by a partial unique
// index over the rows that are not consumed, superseded or locked. An index
// cannot know the time, so an EXPIRED challenge still occupies the slot: the
// PWA-1E service must supersede the previous challenge before inserting a
// new one.
//
// Operational records: finished rows may be purged after 90 days, which is
// why nothing references this table by foreign key.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_otp_challenges', function (Blueprint $table) {
            $table->id();
            // The public challenge reference.
            $table->uuid('uuid')->unique();
            $table->string('purpose', 20);
            $table->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            // Set for password resets (the account already exists).
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            // The destination: a trusted mobile of this Person.
            $table->foreignId('mobile_trust_id')->constrained('person_mobile_trusts')->restrictOnDelete();
            // HMAC-SHA256, 64 lowercase hex characters. Sensitive.
            $table->char('code_hash', 64);
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('send_count')->default(1);
            $table->timestamp('last_sent_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('grant_expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->timestamps();

            $table->index('mobile_trust_id');
            // The retention purge reads it.
            $table->index('expires_at');
        });

        DB::statement(
            'CREATE UNIQUE INDEX uq_auth_otp_challenges_open '
            .'ON auth_otp_challenges (person_id, purpose) '
            .'WHERE consumed_at IS NULL AND superseded_at IS NULL AND locked_at IS NULL'
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE auth_otp_challenges ADD CONSTRAINT chk_auth_otp_challenge_purpose '
                ."CHECK (purpose IN ('ACTIVATION', 'PASSWORD_RESET'))"
            );
            // A keyed hash, never a plaintext code.
            DB::statement(
                'ALTER TABLE auth_otp_challenges ADD CONSTRAINT chk_auth_otp_challenge_hash '
                ."CHECK (code_hash ~ '^[0-9a-f]{64}$')"
            );
            DB::statement(
                'ALTER TABLE auth_otp_challenges ADD CONSTRAINT chk_auth_otp_challenge_counters '
                .'CHECK (attempts >= 0 AND send_count >= 1)'
            );
            // A reset is always for an existing account.
            DB::statement(
                'ALTER TABLE auth_otp_challenges ADD CONSTRAINT chk_auth_otp_challenge_reset_user '
                ."CHECK (purpose <> 'PASSWORD_RESET' OR user_id IS NOT NULL)"
            );
            DB::statement(
                'ALTER TABLE auth_otp_challenges ADD CONSTRAINT chk_auth_otp_challenge_grant '
                .'CHECK (grant_expires_at IS NULL OR verified_at IS NOT NULL)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_otp_challenges');
    }
};
