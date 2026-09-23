<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §19 "family_memberships.relationship_type_id BIGINT
// NULL FK relationship_types.id". The column was added nullable and
// FK-less in 2026_09_23_180000 (relationship_types didn't exist yet); this
// adds the actual foreign key now that the reference table exists.
// nullOnDelete matches the column's nullability — a relationship type
// should be deactivated (is_active = false), not deleted, per docs/06
// §56, but this keeps historical memberships intact even if that rule is
// ever violated at the database level.
//
// Postgres-only, same reason as this table's CHECK constraint (see
// 2026_09_23_153942): SQLite cannot ALTER TABLE ADD CONSTRAINT on an
// existing table without a full table rebuild, and that rebuild
// re-creates this table's pre-existing raw partial unique indexes
// (uq_person_active_family_membership, uq_family_active_household_head)
// from SQLite's index introspection, which does not expose partial WHERE
// clauses — silently turning them into full unique indexes. Enforced at
// the application layer (AddFamilyMemberRequest) on SQLite instead.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('family_memberships', function (Blueprint $table) {
            $table->foreign('relationship_type_id')
                ->references('id')->on('relationship_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('family_memberships', function (Blueprint $table) {
            $table->dropForeign(['relationship_type_id']);
        });
    }
};
