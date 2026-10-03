<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// First self-activation (docs/11 §30a, FP-ADR-053): a successful OTP sent to
// the Person's CURRENT registered mobile establishes a Person-specific
// TRUSTED mobile with the verification method SELF_OTP. Nobody on the Staff
// side granted it, so such a row names no verifier (verified_by NULL);
// every other TRUSTED row still must.
//
// Only two CHECK constraints change; they exist on PostgreSQL only
// (repository convention) and on SQLite the enum and the domain carry the
// same rules. No row changes.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE person_mobile_trusts DROP CONSTRAINT chk_person_mobile_trust_method');
        DB::statement(
            'ALTER TABLE person_mobile_trusts ADD CONSTRAINT chk_person_mobile_trust_method CHECK ('
            ."verification_method IS NULL OR verification_method IN ('IN_PERSON', 'STAFF_CALLBACK', 'AUTHORIZED_RECORD_REVIEW', 'SELF_OTP'))"
        );
        DB::statement('ALTER TABLE person_mobile_trusts DROP CONSTRAINT chk_person_mobile_trust_granted');
        DB::statement(
            'ALTER TABLE person_mobile_trusts ADD CONSTRAINT chk_person_mobile_trust_granted CHECK ('
            ."status <> 'TRUSTED' OR (verified_at IS NOT NULL AND verification_method IS NOT NULL "
            ."AND (verified_by IS NOT NULL OR verification_method = 'SELF_OTP')))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // Never rewrite history: refuse while a self-verified trust exists.
        if (DB::table('person_mobile_trusts')->where('verification_method', 'SELF_OTP')->exists()) {
            throw new RuntimeException('Cannot remove SELF_OTP: self-verified mobile trusts exist.');
        }
        DB::statement('ALTER TABLE person_mobile_trusts DROP CONSTRAINT chk_person_mobile_trust_granted');
        DB::statement(
            'ALTER TABLE person_mobile_trusts ADD CONSTRAINT chk_person_mobile_trust_granted CHECK ('
            ."status <> 'TRUSTED' OR (verified_by IS NOT NULL AND verified_at IS NOT NULL AND verification_method IS NOT NULL))"
        );
        DB::statement('ALTER TABLE person_mobile_trusts DROP CONSTRAINT chk_person_mobile_trust_method');
        DB::statement(
            'ALTER TABLE person_mobile_trusts ADD CONSTRAINT chk_person_mobile_trust_method CHECK ('
            ."verification_method IS NULL OR verification_method IN ('IN_PERSON', 'STAFF_CALLBACK', 'AUTHORIZED_RECORD_REVIEW'))"
        );
    }
};
