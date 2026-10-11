<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// CONTACT_UPDATE V1 (docs/11 FP-ADR-079): the household head changes a
// member's — or, with stronger proof, their own — mobile number, verified by
// an OTP sent to the PROPOSED number and applied by the system.
//
// auth_otp_challenges
// - two purposes: CONTACT_NEW_MOBILE (the proposed number — no trust row:
//   the number is not the Person's yet, so the challenge carries its keyed
//   fingerprint) and CONTACT_OLD_MOBILE (the head's CURRENT trusted mobile,
//   bound to its trust row like a password reset);
// - both belong to their Change Request (change_request_id) and to the
//   requesting account (user_id);
// - mobile_trust_id becomes nullable for CONTACT_NEW_MOBILE only.
//
// person_mobile_trusts: SELF_OTP_TRANSFER — the head proved the current
// password, the old trusted number and the new number; nobody on the Staff
// side granted it, so it names no verifier (like SELF_OTP).
//
// change_requests: CONTACT_UPDATE is approved and applied by the SYSTEM
// (no Staff review), so its approver / applier / reviewer may be NULL; a
// system-closed request names no canceller. Every other type is unchanged.
//
// CHECK constraints exist on PostgreSQL only (repository convention); on
// SQLite the enums and the domain carry the same rules. No row changes.
return new class extends Migration
{
    private const OLD_PURPOSES = "'ACTIVATION', 'PASSWORD_RESET'";

    private const PURPOSES = "'ACTIVATION', 'PASSWORD_RESET', 'CONTACT_NEW_MOBILE', 'CONTACT_OLD_MOBILE'";

    public function up(): void
    {
        // The partial "one open challenge per Person and purpose" index is
        // dropped and recreated around the column change: a table rebuild
        // (SQLite) would otherwise recreate it without its WHERE clause.
        DB::statement('DROP INDEX IF EXISTS uq_auth_otp_challenges_open');
        Schema::table('auth_otp_challenges', function (Blueprint $table) {
            $table->unsignedBigInteger('mobile_trust_id')->nullable()->change();
            // The Change Request a contact-update challenge belongs to.
            $table->foreignId('change_request_id')->nullable()->after('mobile_trust_id')
                ->constrained('change_requests')->restrictOnDelete();
            // CONTACT_NEW_MOBILE: the keyed MOBILE fingerprint of the proposed
            // number and its key version — never the number itself.
            $table->char('destination_fingerprint', 64)->nullable()->after('change_request_id');
            $table->unsignedSmallInteger('destination_key_version')->nullable()->after('destination_fingerprint');
            $table->index('change_request_id');
        });
        DB::statement(
            'CREATE UNIQUE INDEX uq_auth_otp_challenges_open '
            .'ON auth_otp_challenges (person_id, purpose) '
            .'WHERE consumed_at IS NULL AND superseded_at IS NULL AND locked_at IS NULL'
        );

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE auth_otp_challenges DROP CONSTRAINT chk_auth_otp_challenge_purpose');
        DB::statement('ALTER TABLE auth_otp_challenges ADD CONSTRAINT chk_auth_otp_challenge_purpose CHECK (purpose IN ('.self::PURPOSES.'))');
        DB::statement(
            'ALTER TABLE auth_otp_challenges ADD CONSTRAINT chk_auth_otp_challenge_destination CHECK ('
            ."((purpose = 'CONTACT_NEW_MOBILE') = (mobile_trust_id IS NULL)) "
            ."AND ((purpose = 'CONTACT_NEW_MOBILE') = (destination_fingerprint IS NOT NULL)) "
            .'AND ((destination_fingerprint IS NULL) = (destination_key_version IS NULL)) '
            ."AND (destination_fingerprint IS NULL OR (destination_fingerprint ~ '^[0-9a-f]{64}\$' AND destination_key_version >= 1)) "
            ."AND ((purpose IN ('CONTACT_NEW_MOBILE', 'CONTACT_OLD_MOBILE')) = (change_request_id IS NOT NULL)) "
            ."AND (purpose NOT IN ('CONTACT_NEW_MOBILE', 'CONTACT_OLD_MOBILE') OR user_id IS NOT NULL))"
        );

        DB::statement('ALTER TABLE person_mobile_trusts DROP CONSTRAINT chk_person_mobile_trust_method');
        DB::statement(
            'ALTER TABLE person_mobile_trusts ADD CONSTRAINT chk_person_mobile_trust_method CHECK ('
            ."verification_method IS NULL OR verification_method IN ('IN_PERSON', 'STAFF_CALLBACK', 'AUTHORIZED_RECORD_REVIEW', 'SELF_OTP', 'SELF_OTP_TRANSFER'))"
        );
        DB::statement('ALTER TABLE person_mobile_trusts DROP CONSTRAINT chk_person_mobile_trust_granted');
        DB::statement(
            'ALTER TABLE person_mobile_trusts ADD CONSTRAINT chk_person_mobile_trust_granted CHECK ('
            ."status <> 'TRUSTED' OR (verified_at IS NOT NULL AND verification_method IS NOT NULL "
            ."AND (verified_by IS NOT NULL OR verification_method IN ('SELF_OTP', 'SELF_OTP_TRANSFER'))))"
        );

        $system = "type = 'CONTACT_UPDATE'";
        $this->replace('change_requests', 'chk_change_request_reviewed',
            '((reviewed_at IS NULL) = (reviewed_by IS NULL)) AND ('
            ."status NOT IN ('UNDER_REVIEW', 'RETURNED_FOR_CLARIFICATION', 'RESUBMITTED', 'APPROVED', 'REJECTED', 'APPLIED') "
            ."OR reviewed_at IS NOT NULL OR ({$system} AND status IN ('APPROVED', 'APPLIED')))");
        $this->replace('change_requests', 'chk_change_request_approved',
            "(approved_by IS NULL OR approved_at IS NOT NULL) AND (approved_at IS NULL OR approved_by IS NOT NULL OR {$system}) AND ("
            ."(status IN ('APPROVED', 'APPLIED') AND approved_at IS NOT NULL) "
            ."OR (status = 'REJECTED') "
            ."OR (status NOT IN ('APPROVED', 'APPLIED', 'REJECTED') AND approved_at IS NULL))");
        $this->replace('change_requests', 'chk_change_request_applied',
            "(status = 'APPLIED' AND applied_at IS NOT NULL AND (applied_by IS NOT NULL OR {$system})) "
            ."OR (status <> 'APPLIED' AND applied_at IS NULL AND applied_by IS NULL)");
        $this->replace('change_requests', 'chk_change_request_cancelled',
            "(status = 'CANCELLED' AND cancelled_at IS NOT NULL AND (cancelled_by IS NOT NULL OR {$system})) "
            ."OR (status <> 'CANCELLED' AND cancelled_at IS NULL AND cancelled_by IS NULL)");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Never rewrite history: refuse while rows rely on the relaxed rules.
            if (DB::table('auth_otp_challenges')->whereIn('purpose', ['CONTACT_NEW_MOBILE', 'CONTACT_OLD_MOBILE'])->exists()
                || DB::table('person_mobile_trusts')->where('verification_method', 'SELF_OTP_TRANSFER')->exists()
                || DB::table('change_requests')->where('type', 'CONTACT_UPDATE')->exists()) {
                throw new RuntimeException('Cannot roll back CONTACT_UPDATE: contact-update records exist.');
            }
            $this->replace('change_requests', 'chk_change_request_cancelled',
                "(status = 'CANCELLED' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL) "
                ."OR (status <> 'CANCELLED' AND cancelled_at IS NULL AND cancelled_by IS NULL)");
            $this->replace('change_requests', 'chk_change_request_applied',
                "(status = 'APPLIED' AND applied_at IS NOT NULL AND applied_by IS NOT NULL) "
                ."OR (status <> 'APPLIED' AND applied_at IS NULL AND applied_by IS NULL)");
            $this->replace('change_requests', 'chk_change_request_approved',
                '((approved_at IS NULL) = (approved_by IS NULL)) AND ('
                ."(status IN ('APPROVED', 'APPLIED') AND approved_at IS NOT NULL) "
                ."OR (status = 'REJECTED') "
                ."OR (status NOT IN ('APPROVED', 'APPLIED', 'REJECTED') AND approved_at IS NULL))");
            $this->replace('change_requests', 'chk_change_request_reviewed',
                '((reviewed_at IS NULL) = (reviewed_by IS NULL)) AND ('
                ."status NOT IN ('UNDER_REVIEW', 'RETURNED_FOR_CLARIFICATION', 'RESUBMITTED', 'APPROVED', 'REJECTED', 'APPLIED') "
                .'OR reviewed_at IS NOT NULL)');
            $this->replace('person_mobile_trusts', 'chk_person_mobile_trust_granted',
                "status <> 'TRUSTED' OR (verified_at IS NOT NULL AND verification_method IS NOT NULL "
                ."AND (verified_by IS NOT NULL OR verification_method = 'SELF_OTP'))");
            $this->replace('person_mobile_trusts', 'chk_person_mobile_trust_method',
                "verification_method IS NULL OR verification_method IN ('IN_PERSON', 'STAFF_CALLBACK', 'AUTHORIZED_RECORD_REVIEW', 'SELF_OTP')");
            DB::statement('ALTER TABLE auth_otp_challenges DROP CONSTRAINT chk_auth_otp_challenge_destination');
            $this->replace('auth_otp_challenges', 'chk_auth_otp_challenge_purpose', 'purpose IN ('.self::OLD_PURPOSES.')');
        }

        DB::statement('DROP INDEX IF EXISTS uq_auth_otp_challenges_open');
        Schema::table('auth_otp_challenges', function (Blueprint $table) {
            $table->dropIndex(['change_request_id']);
            $table->dropConstrainedForeignId('change_request_id');
            $table->dropColumn(['destination_fingerprint', 'destination_key_version']);
            $table->unsignedBigInteger('mobile_trust_id')->nullable(false)->change();
        });
        DB::statement(
            'CREATE UNIQUE INDEX uq_auth_otp_challenges_open '
            .'ON auth_otp_challenges (person_id, purpose) '
            .'WHERE consumed_at IS NULL AND superseded_at IS NULL AND locked_at IS NULL'
        );
    }

    private function replace(string $table, string $name, string $check): void
    {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$check})");
    }
};
