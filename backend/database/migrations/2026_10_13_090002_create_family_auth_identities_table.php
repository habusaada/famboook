<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// PWA-1C (docs/04 §55b): the authentication identity of a family-side
// account. login_key is a keyed fingerprint (KeyedFingerprint, context
// LOGIN_ID) — never a raw National ID. Schema only: identities are created
// at activation (PWA-1F), never derived from existing persons.
// persons.national_id is not touched and receives no UNIQUE constraint.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_auth_identities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // HMAC-SHA256, 64 lowercase hex characters. Sensitive.
            $table->char('login_key', 64);
            $table->unsignedSmallInteger('key_version');
            $table->string('status', 20);
            $table->timestamp('superseded_at')->nullable();
            $table->string('supersede_reason', 30)->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        // Two ACTIVE identities can never share a login key. A SUSPENDED or
        // SUPERSEDED identity holds an obsolete key and does not reserve it.
        DB::statement(
            'CREATE UNIQUE INDEX uq_family_auth_identities_active_key '
            ."ON family_auth_identities (login_key) WHERE status = 'ACTIVE'"
        );
        // One current identity per family-side account.
        DB::statement(
            'CREATE UNIQUE INDEX uq_family_auth_identities_current_user '
            ."ON family_auth_identities (user_id) WHERE status IN ('ACTIVE', 'SUSPENDED')"
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE family_auth_identities ADD CONSTRAINT chk_family_auth_identity_status '
                ."CHECK (status IN ('ACTIVE', 'SUSPENDED', 'SUPERSEDED'))"
            );
            // A fingerprint, never a raw identifier.
            DB::statement(
                'ALTER TABLE family_auth_identities ADD CONSTRAINT chk_family_auth_identity_key '
                ."CHECK (login_key ~ '^[0-9a-f]{64}$' AND key_version >= 1)"
            );
            DB::statement(
                'ALTER TABLE family_auth_identities ADD CONSTRAINT chk_family_auth_identity_superseded CHECK ('
                ."(status = 'SUPERSEDED') = (superseded_at IS NOT NULL) AND "
                ."(status = 'SUPERSEDED') = (supersede_reason IS NOT NULL) AND "
                ."(supersede_reason IS NULL OR supersede_reason IN ('NATIONAL_ID_CORRECTED', 'KEY_ROTATION')))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('family_auth_identities');
    }
};
