<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §14 "relationship_type_id" references the
// relationship_types reference table, which remains out of scope
// (docs/07-ROADMAP.md Phase 7). This adds the nullable column WITHOUT
// a foreign key constraint (the referenced table doesn't exist yet) so
// the schema is forward-compatible: the column can be populated once
// reference data is implemented, without another migration. No
// business logic currently writes a non-null value to it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_memberships', function (Blueprint $table) {
            $table->unsignedBigInteger('relationship_type_id')->nullable()->after('person_id');
        });
    }

    public function down(): void
    {
        Schema::table('family_memberships', function (Blueprint $table) {
            $table->dropColumn('relationship_type_id');
        });
    }
};
