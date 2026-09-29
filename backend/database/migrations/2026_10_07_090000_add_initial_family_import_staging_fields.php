<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Initial Family Import Phase 2A (docs/03 §96a, docs/04 §83a).
//
// import_batches.clan_id — every batch explicitly targets one Clan (never a
// default); family-key discovery and later Branch resolution are scoped to it.
// uq_import_batch_clan_checksum — the same file cannot be staged twice for the
// same Clan while an earlier batch of it is still live (not FAILED).
// import_rows.normalized_payload / source_family_key — the explicit normalized
// staging representation; the key column is indexed for per-batch discovery.
//
// Additive only; no existing row is changed.
return new class extends Migration
{
    public function up(): void
    {
        // A batch without a target Clan cannot be given one by guessing.
        if (DB::table('import_batches')->exists()) {
            throw new RuntimeException(
                'import_batches already has rows without a target Clan; they must be removed or assigned explicitly first.'
            );
        }

        Schema::table('import_batches', function (Blueprint $table) {
            $table->foreignId('clan_id')->after('uuid')->constrained('clans')->restrictOnDelete();
            $table->index('clan_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX uq_import_batch_clan_checksum '
            ."ON import_batches (clan_id, source_checksum) WHERE status <> 'FAILED'"
        );

        Schema::table('import_rows', function (Blueprint $table) {
            // Normalized source values (docs/02 §88a); sanitized like raw_payload.
            $table->jsonb('normalized_payload')->nullable()->after('raw_payload');
            // Whitespace-normalized source family key (المفتاح); NULL = missing.
            $table->string('source_family_key', 150)->nullable()->after('normalized_payload');
            $table->index(['import_batch_id', 'source_family_key']);
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropIndex(['import_batch_id', 'source_family_key']);
            $table->dropColumn(['normalized_payload', 'source_family_key']);
        });

        DB::statement('DROP INDEX IF EXISTS uq_import_batch_clan_checksum');

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropIndex(['clan_id']);
            $table->dropConstrainedForeignId('clan_id');
        });
    }
};
