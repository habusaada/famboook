<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Operational Dashboard V1 (docs/04 §19). Every current-population figure
// selects active memberships by family (scoped family ids). The only
// existing family_id index on this table is the partial unique
// household-head index, which does not serve that lookup.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_memberships', function (Blueprint $table) {
            $table->index(['family_id', 'is_active'], 'ix_family_memberships_family_active');
        });
    }

    public function down(): void
    {
        Schema::table('family_memberships', function (Blueprint $table) {
            $table->dropIndex('ix_family_memberships_family_active');
        });
    }
};
