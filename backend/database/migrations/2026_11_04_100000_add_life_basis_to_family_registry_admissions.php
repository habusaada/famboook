<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FB-FAMILY-ADD-01 RR-02A (D-06R, proposed): on what the admitted child's life rests.
 * Additive and nullable (rows written before it have none). Every new admission writes
 * `life_basis`: VERIFIED_SOURCE (an authoritative verifier proved it) or
 * REGISTRY_SCOPE_DECLARATION (a living-scope release plus the head's declaration; the
 * Person is recorded UNKNOWN, never ALIVE), which must carry the approval reference, the
 * release's extraction date and the time of the head's life declaration. The table stays
 * append-only (the existing triggers are unchanged).
 */
return new class extends Migration
{
    private const CHECKS = [
        'chk_registry_admission_life_basis' => "life_basis IS NULL OR life_basis IN ('VERIFIED_SOURCE', 'REGISTRY_SCOPE_DECLARATION')",
        'chk_registry_admission_life_scope' => "life_basis IS DISTINCT FROM 'REGISTRY_SCOPE_DECLARATION' OR (life_policy_reference IS NOT NULL "
            ."AND btrim(life_policy_reference) <> '' AND registry_extracted_on IS NOT NULL AND life_declared_at IS NOT NULL)",
        'chk_registry_admission_life_verified' => "life_basis IS DISTINCT FROM 'VERIFIED_SOURCE' OR (life_policy_reference IS NULL AND registry_extracted_on IS NULL)",
    ];

    public function up(): void
    {
        Schema::table('family_registry_admissions', function (Blueprint $table) {
            $table->string('life_basis', 30)->nullable();
            $table->string('life_policy_reference', 80)->nullable();
            $table->date('registry_extracted_on')->nullable();
            $table->timestampTz('life_declared_at')->nullable();
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::CHECKS as $name => $check) {
            DB::statement("ALTER TABLE family_registry_admissions ADD CONSTRAINT {$name} CHECK ({$check})");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (array_keys(self::CHECKS) as $name) {
                DB::statement("ALTER TABLE family_registry_admissions DROP CONSTRAINT IF EXISTS {$name}");
            }
        }
        Schema::table('family_registry_admissions', function (Blueprint $table) {
            $table->dropColumn(['life_basis', 'life_policy_reference', 'registry_extracted_on', 'life_declared_at']);
        });
    }
};
