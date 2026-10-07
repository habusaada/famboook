<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Digital credentials (docs/04, docs/11 FP-ADR-070, PWA-8.2). Generic
// infrastructure; PWA-8 implements the FAMILY subject only — the Digital
// Family Card. A credential is a public card number (FC-XXXX-XXXX-XX) plus
// an opaque 256-bit QR token held as a SHA-256 lookup hash and a Crypt-sealed
// copy (only for re-showing the owner's QR). Never the plaintext token.
//
// History is append-only: a row only ever moves ACTIVE → REVOKED; reissue is
// a revoke (REISSUED) plus a NEW row. At most one ACTIVE credential per
// Family. family_id is nullable only so that a future subject type can be
// added without changing this column; the CHECK requires it for FAMILY.
// Additive: no backfill, no row is created here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 20);
            $table->foreignId('family_id')->nullable()->constrained('families')->restrictOnDelete();
            // Public, non-secret card number; immutable for the row.
            $table->string('credential_number', 15)->unique();
            // SHA-256 of the opaque token: the only lookup key.
            $table->char('token_hash', 64)->unique();
            // Laravel Crypt of the token, for the owner's QR only. Never logged.
            $table->text('token_encrypted');
            $table->unsignedSmallInteger('token_version')->default(1);
            $table->string('status', 20);
            $table->timestamp('issued_at');
            // NULL = system issuance (the Family Portal's lazy issuance).
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 30)->nullable();
            $table->timestamps();
            $table->index(['family_id', 'status']);
        });

        // One ACTIVE Family credential per Family. Partial indexes work on
        // PostgreSQL and SQLite (test suite).
        DB::statement(
            'CREATE UNIQUE INDEX uq_digital_credentials_active_family '
            .'ON digital_credentials (family_id) '
            ."WHERE status = 'ACTIVE' AND subject_type = 'FAMILY'"
        );

        // PostgreSQL-only CHECK constraints (repository convention); on
        // SQLite the enums, the model guard and the Domain Actions carry the
        // same rules.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE digital_credentials ADD CONSTRAINT chk_credential_subject '
                ."CHECK (subject_type = 'FAMILY' AND family_id IS NOT NULL)"
            );
            DB::statement(
                'ALTER TABLE digital_credentials ADD CONSTRAINT chk_credential_status '
                ."CHECK (status IN ('ACTIVE', 'REVOKED'))"
            );
            DB::statement(
                'ALTER TABLE digital_credentials ADD CONSTRAINT chk_credential_revocation CHECK ('
                ."((status = 'REVOKED') = (revoked_at IS NOT NULL AND revoke_reason IS NOT NULL)) AND "
                ."(revoke_reason IS NULL OR revoke_reason IN ('REISSUED', 'ADMINISTRATIVE', 'COMPROMISED')))"
            );
            DB::statement(
                'ALTER TABLE digital_credentials ADD CONSTRAINT chk_credential_number '
                ."CHECK (credential_number ~ '^FC-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{2}$')"
            );
            DB::statement(
                'ALTER TABLE digital_credentials ADD CONSTRAINT chk_credential_token_hash '
                ."CHECK (token_hash ~ '^[0-9a-f]{64}$')"
            );
            DB::statement(
                'ALTER TABLE digital_credentials ADD CONSTRAINT chk_credential_token_version '
                .'CHECK (token_version >= 1)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_credentials');
    }
};
