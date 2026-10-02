<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// PWA-1C (docs/04 §55b, docs/11 §30a): family-side accounts have no email,
// so users.email becomes nullable. Only the NOT NULL is dropped — the unique
// index stays (PostgreSQL allows many NULLs under it) and no row changes.
// Staff accounts still require an email (ManageStaffUsersAction), Staff login
// still looks a user up by email, and no synthetic email is ever written.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Never invent an email for a family-side account: refuse instead.
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException(
                'Cannot restore users.email NOT NULL: accounts without an email exist.'
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN email SET NOT NULL');

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
