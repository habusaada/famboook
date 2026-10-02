<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// PWA-1C (docs/04 §55b, docs/03 §89b): trust of ONE exact normalized mobile
// number for ONE Person. Schema only — no row is created from imported
// mobiles: an imported mobile is UNVERIFIED precisely because no TRUSTED row
// exists for it. The number is stored as a keyed fingerprint (context MOBILE)
// plus two digits for masked display; it is NOT unique, because shared
// numbers are valid registry data and each Person is verified separately.
// Rows are history and are never deleted.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_mobile_trusts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            // HMAC-SHA256, 64 lowercase hex characters. Sensitive.
            $table->char('mobile_fingerprint', 64);
            $table->char('mobile_last2', 2);
            $table->unsignedSmallInteger('key_version');
            $table->string('status', 30);
            $table->string('verification_method', 30)->nullable();
            // Assistance (a coordinator or Staff) is separate from the grant.
            $table->foreignId('assisted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('assisted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('stale_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 60)->nullable();
            $table->timestamps();

            // Deliberately NOT unique: per-destination throttling and the
            // "also recorded for N persons" count read it.
            $table->index('mobile_fingerprint');
            $table->index(['person_id', 'status']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX uq_person_mobile_trusts_trusted '
            ."ON person_mobile_trusts (person_id) WHERE status = 'TRUSTED'"
        );
        DB::statement(
            'CREATE UNIQUE INDEX uq_person_mobile_trusts_pending '
            ."ON person_mobile_trusts (person_id) WHERE status = 'PENDING_VERIFICATION'"
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE person_mobile_trusts ADD CONSTRAINT chk_person_mobile_trust_status '
                ."CHECK (status IN ('PENDING_VERIFICATION', 'TRUSTED', 'STALE', 'REVOKED'))"
            );
            DB::statement(
                'ALTER TABLE person_mobile_trusts ADD CONSTRAINT chk_person_mobile_trust_method CHECK ('
                ."verification_method IS NULL OR verification_method IN ('IN_PERSON', 'STAFF_CALLBACK', 'AUTHORIZED_RECORD_REVIEW'))"
            );
            // A fingerprint and two display digits, never a raw number.
            DB::statement(
                'ALTER TABLE person_mobile_trusts ADD CONSTRAINT chk_person_mobile_trust_value '
                ."CHECK (mobile_fingerprint ~ '^[0-9a-f]{64}$' AND mobile_last2 ~ '^[0-9]{2}$' AND key_version >= 1)"
            );
            // TRUSTED always names who granted it, when and how.
            DB::statement(
                'ALTER TABLE person_mobile_trusts ADD CONSTRAINT chk_person_mobile_trust_granted CHECK ('
                ."status <> 'TRUSTED' OR (verified_by IS NOT NULL AND verified_at IS NOT NULL AND verification_method IS NOT NULL))"
            );
            DB::statement(
                'ALTER TABLE person_mobile_trusts ADD CONSTRAINT chk_person_mobile_trust_revoked CHECK ('
                ."(status <> 'REVOKED' OR (revoked_by IS NOT NULL AND revoked_at IS NOT NULL AND revoke_reason IS NOT NULL)) AND "
                ."(revoke_reason IS NULL OR revoke_reason ~ '^[A-Z][A-Z0-9_]{1,59}$'))"
            );
            DB::statement(
                'ALTER TABLE person_mobile_trusts ADD CONSTRAINT chk_person_mobile_trust_stale '
                ."CHECK (status <> 'STALE' OR stale_at IS NOT NULL)"
            );
            DB::statement(
                'ALTER TABLE person_mobile_trusts ADD CONSTRAINT chk_person_mobile_trust_assisted '
                .'CHECK ((assisted_by IS NULL) = (assisted_at IS NULL))'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('person_mobile_trusts');
    }
};
