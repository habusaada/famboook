<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// FB-CIVIL-02C (corrected, docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md §L-6a):
// SUPER_ADMIN and ADMINISTRATOR have automatic Civil Registry Lookup access
// with an unlimited daily quota. Their role policy is always enabled and
// unlimited and cannot be limited or disabled. The individual REVOKED block,
// the feature gates, the permissions and the audit still apply. Every other
// role keeps its 02A / 02B policy.
//
// Like the 02A defaults, this baseline row change has no policy-change
// history entry (there is no acting user). down() restores the 02A defaults.
return new class extends Migration
{
    public function up(): void
    {
        // The 02A constraint allows unlimited only for SUPER_ADMIN, so it is
        // dropped before ADMINISTRATOR is made unlimited.
        $pgsql = DB::getDriverName() === 'pgsql';
        if ($pgsql) {
            DB::statement('ALTER TABLE civil_lookup_role_policies DROP CONSTRAINT IF EXISTS chk_civil_lookup_policy_super_admin');
            DB::statement('ALTER TABLE civil_lookup_role_policies DROP CONSTRAINT IF EXISTS chk_civil_lookup_policy_unlimited_role');
        }
        DB::table('civil_lookup_role_policies')->whereIn('role_name', ['SUPER_ADMIN', 'ADMINISTRATOR'])
            ->update(['enabled' => true, 'daily_limit' => null, 'unlimited_allowed' => true, 'updated_at' => now()]);
        if (! $pgsql) {
            return;
        }
        DB::statement("ALTER TABLE civil_lookup_role_policies ADD CONSTRAINT chk_civil_lookup_policy_automatic CHECK (
            role_name NOT IN ('SUPER_ADMIN', 'ADMINISTRATOR') OR (enabled AND daily_limit IS NULL AND unlimited_allowed))");
        DB::statement("ALTER TABLE civil_lookup_role_policies ADD CONSTRAINT chk_civil_lookup_policy_unlimited_role CHECK (
            NOT unlimited_allowed OR role_name IN ('SUPER_ADMIN', 'ADMINISTRATOR'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE civil_lookup_role_policies DROP CONSTRAINT IF EXISTS chk_civil_lookup_policy_automatic');
            DB::statement('ALTER TABLE civil_lookup_role_policies DROP CONSTRAINT IF EXISTS chk_civil_lookup_policy_unlimited_role');
        }
        DB::table('civil_lookup_role_policies')->where('role_name', 'ADMINISTRATOR')
            ->update(['daily_limit' => 200, 'unlimited_allowed' => false, 'updated_at' => now()]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE civil_lookup_role_policies ADD CONSTRAINT chk_civil_lookup_policy_super_admin CHECK (
                role_name <> 'SUPER_ADMIN' OR (daily_limit IS NULL AND unlimited_allowed))");
            DB::statement("ALTER TABLE civil_lookup_role_policies ADD CONSTRAINT chk_civil_lookup_policy_unlimited_role CHECK (
                NOT unlimited_allowed OR role_name = 'SUPER_ADMIN')");
        }
    }
};
