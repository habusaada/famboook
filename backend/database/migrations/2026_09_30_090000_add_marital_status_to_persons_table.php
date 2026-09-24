<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// docs/02-DATA-DICTIONARY.md §10 "marital_status" (V1). Needed by
// Assistance V1-B delegated receipt. Existing persons get UNKNOWN: SINGLE
// is never inferred from age or relationship.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->string('marital_status')->default('UNKNOWN')->after('gender');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE persons ADD CONSTRAINT chk_person_marital_status '
                ."CHECK (marital_status IN ('SINGLE', 'MARRIED', 'DIVORCED', 'WIDOWED', 'UNKNOWN'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE persons DROP CONSTRAINT IF EXISTS chk_person_marital_status');
        }

        Schema::table('persons', function (Blueprint $table) {
            $table->dropColumn('marital_status');
        });
    }
};
