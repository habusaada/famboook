<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// PWA-1D (docs/04 §55b): an ended User-Person Link retires its Family Auth
// Identity as SUPERSEDED with the reason LINK_ENDED — account state, link
// state and authentication-identity state stay separate concepts, so an
// ended link is not represented by an indefinitely SUSPENDED identity.
//
// Only the allowed values of supersede_reason change. The CHECK constraint
// exists on PostgreSQL only (repository convention); on SQLite the
// AuthIdentitySupersedeReason enum carries the same rule. No row changes.
return new class extends Migration
{
    private const BEFORE = "'NATIONAL_ID_CORRECTED', 'KEY_ROTATION'";

    private const AFTER = "'NATIONAL_ID_CORRECTED', 'KEY_ROTATION', 'LINK_ENDED'";

    public function up(): void
    {
        $this->replaceConstraint(self::AFTER);
    }

    public function down(): void
    {
        // Never rewrite history: refuse while an ended link's identity exists.
        if (DB::table('family_auth_identities')->where('supersede_reason', 'LINK_ENDED')->exists()) {
            throw new RuntimeException(
                'Cannot remove LINK_ENDED: authentication identities superseded by an ended link exist.'
            );
        }

        $this->replaceConstraint(self::BEFORE);
    }

    private function replaceConstraint(string $reasons): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE family_auth_identities DROP CONSTRAINT chk_family_auth_identity_superseded');
        DB::statement(
            'ALTER TABLE family_auth_identities ADD CONSTRAINT chk_family_auth_identity_superseded CHECK ('
            ."(status = 'SUPERSEDED') = (superseded_at IS NOT NULL) AND "
            ."(status = 'SUPERSEDED') = (supersede_reason IS NOT NULL) AND "
            ."(supersede_reason IS NULL OR supersede_reason IN ({$reasons})))"
        );
    }
};
