<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Phase 4B.1 — Apply foundation (docs/03 §96b, docs/04 §83d). Schema only:
// nothing here applies an import or writes to registry tables.
//
// 1. Apply lifecycle: PARTIALLY_APPLIED, and apply_started_at. Once Apply has
//    started a batch can only be APPLYING / PARTIALLY_APPLIED / APPLIED (never
//    FAILED), so a partly applied file stays inside uq_import_batch_clan_checksum
//    (which excludes only FAILED) and cannot be uploaded and applied twice.
// 2. import_apply_records: append-only provenance of every intended effect of
//    an import row. (import_row_id, effect_key) is unique and both are NOT
//    NULL — no reliance on NULL semantics in unique indexes.
//
// Entity references: (entity_type, entity_id) with stable codes and NO
// foreign key — one column cannot reference five tables, and a provenance row
// must never be cascade-deleted. Registry rows are soft-deleted or protected
// by RESTRICT foreign keys, so the referenced id stays resolvable.
return new class extends Migration
{
    private const EFFECTS = [
        // effect_key => [entity_type, role, spouse_slot]
        'HEAD_PERSON' => ['PERSON', 'HEAD', null],
        'FAMILY' => ['FAMILY', null, null],
        'HEAD_MEMBERSHIP' => ['MEMBERSHIP', 'HEAD', null],
        'HOUSEHOLD_DECLARATION' => ['HOUSEHOLD_DECLARATION', null, null],
        'RESIDENCE' => ['RESIDENCE', null, null],
        'SPOUSE_1_PERSON' => ['PERSON', 'SPOUSE', 1],
        'SPOUSE_2_PERSON' => ['PERSON', 'SPOUSE', 2],
        'SPOUSE_3_PERSON' => ['PERSON', 'SPOUSE', 3],
        'SPOUSE_4_PERSON' => ['PERSON', 'SPOUSE', 4],
        'SPOUSE_1_MEMBERSHIP' => ['MEMBERSHIP', 'SPOUSE', 1],
        'SPOUSE_2_MEMBERSHIP' => ['MEMBERSHIP', 'SPOUSE', 2],
        'SPOUSE_3_MEMBERSHIP' => ['MEMBERSHIP', 'SPOUSE', 3],
        'SPOUSE_4_MEMBERSHIP' => ['MEMBERSHIP', 'SPOUSE', 4],
    ];

    private const STATUSES_BEFORE = "'UPLOADED', 'VALIDATING', 'READY_FOR_REVIEW', 'READY_TO_APPLY', 'APPLYING', 'APPLIED', 'FAILED'";

    private const STATUSES_AFTER = "'UPLOADED', 'VALIDATING', 'READY_FOR_REVIEW', 'READY_TO_APPLY', 'APPLYING', 'PARTIALLY_APPLIED', 'APPLIED', 'FAILED'";

    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            // Set when Apply starts; never cleared once a row was applied.
            $table->timestamp('apply_started_at')->nullable()->after('reconciliation_fingerprint');
        });

        // Lets import_apply_records prove its row belongs to its batch.
        DB::statement('CREATE UNIQUE INDEX uq_import_rows_id_batch ON import_rows (id, import_batch_id)');

        Schema::create('import_apply_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->restrictOnDelete();
            $table->unsignedBigInteger('import_row_id');
            $table->string('effect_key', 40);
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('role', 10)->nullable();
            $table->unsignedTinyInteger('spouse_slot')->nullable();
            $table->string('outcome', 10);
            $table->string('reason_code', 60)->nullable();
            $table->foreignId('applied_by')->constrained('users')->restrictOnDelete();
            // Append-only: no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['import_row_id', 'import_batch_id'], 'fk_import_apply_row_batch')
                ->references(['id', 'import_batch_id'])->on('import_rows')->restrictOnDelete();
            $table->unique(['import_row_id', 'effect_key'], 'uq_import_apply_row_effect');
            $table->index(['import_batch_id', 'outcome']);
            $table->index(['entity_type', 'entity_id']);
        });

        // A registry entity is CREATED by at most one import effect.
        DB::statement(
            'CREATE UNIQUE INDEX uq_import_apply_created_entity '
            ."ON import_apply_records (entity_type, entity_id) WHERE outcome = 'CREATED'"
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE import_batches DROP CONSTRAINT chk_import_batch_status');
            DB::statement('ALTER TABLE import_batches ADD CONSTRAINT chk_import_batch_status CHECK (status IN ('.self::STATUSES_AFTER.'))');
            DB::statement(
                'ALTER TABLE import_batches ADD CONSTRAINT chk_import_batch_apply_started '
                ."CHECK ((apply_started_at IS NOT NULL) = (status IN ('APPLYING', 'PARTIALLY_APPLIED', 'APPLIED')))"
            );

            $shapes = [];
            foreach (self::EFFECTS as $effect => [$entity, $role, $slot]) {
                $shapes[] = "(effect_key = '{$effect}' AND entity_type = '{$entity}' AND "
                    .($role === null ? 'role IS NULL' : "role = '{$role}'").' AND '
                    .($slot === null ? 'spouse_slot IS NULL' : "spouse_slot = {$slot}").')';
            }
            DB::statement('ALTER TABLE import_apply_records ADD CONSTRAINT chk_import_apply_shape CHECK ('.implode(' OR ', $shapes).')');
            DB::statement(
                'ALTER TABLE import_apply_records ADD CONSTRAINT chk_import_apply_outcome '
                ."CHECK (outcome IN ('CREATED', 'REUSED', 'OMITTED', 'BLOCKED'))"
            );
            // CREATED / REUSED point at an entity; OMITTED never does.
            DB::statement(
                'ALTER TABLE import_apply_records ADD CONSTRAINT chk_import_apply_entity CHECK ('
                ."(outcome NOT IN ('CREATED', 'REUSED') OR entity_id IS NOT NULL) AND "
                ."(outcome <> 'OMITTED' OR entity_id IS NULL))"
            );
            // A reason is a code, never a value; OMITTED / BLOCKED must say why.
            DB::statement(
                'ALTER TABLE import_apply_records ADD CONSTRAINT chk_import_apply_reason CHECK ('
                ."(reason_code IS NULL OR reason_code ~ '^[A-Z][A-Z0-9_]{1,59}$') AND "
                ."(outcome NOT IN ('OMITTED', 'BLOCKED') OR reason_code IS NOT NULL))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_apply_records');
        DB::statement('DROP INDEX IF EXISTS uq_import_rows_id_batch');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE import_batches DROP CONSTRAINT IF EXISTS chk_import_batch_apply_started');
            DB::statement('ALTER TABLE import_batches DROP CONSTRAINT chk_import_batch_status');
            DB::statement('ALTER TABLE import_batches ADD CONSTRAINT chk_import_batch_status CHECK (status IN ('.self::STATUSES_BEFORE.'))');
        }

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn('apply_started_at');
        });
        // SQLite rebuilt import_batches for the drop and lost the predicate of
        // uq_import_batch_clan_checksum (see 2026_10_10); recreate it exactly.
        DB::statement('DROP INDEX IF EXISTS uq_import_batch_clan_checksum');
        DB::statement(
            'CREATE UNIQUE INDEX uq_import_batch_clan_checksum '
            ."ON import_batches (clan_id, source_checksum) WHERE status <> 'FAILED'"
        );
    }
};
