<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CO-01C (docs/06 AUTH-ADR-092, docs/04 §55b): the Family an audited access
// concerned — the Coordinator member reveal records the Family the member
// was resolved through. Nullable: every existing event, and every event that
// concerns no Family, keeps NULL. A reference only — never a value — so the
// existing rows, columns and CHECK constraints are untouched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_security_events', function (Blueprint $table) {
            $table->foreignId('family_id')->nullable()->constrained('families')->restrictOnDelete();
            $table->index(['family_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('auth_security_events', function (Blueprint $table) {
            $table->dropIndex(['family_id', 'created_at']);
            $table->dropConstrainedForeignId('family_id');
        });
    }
};
