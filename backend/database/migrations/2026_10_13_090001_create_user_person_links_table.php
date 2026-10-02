<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// PWA-1C (docs/04 §53, §55b): the explicit User-Person Link. Schema only —
// no row is created here and none is derived from existing persons. Every
// foreign key is RESTRICT: identity history is never cascaded or nulled.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_person_links', function (Blueprint $table) {
            $table->id();
            // Public reference; internal ids are never exposed by the API.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            $table->string('link_type', 20);
            $table->string('status', 30);
            $table->string('verification_method', 30)->nullable();
            // NULL verified_by = verified by the activation system process.
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('suspension_reason', 60)->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('end_reason', 60)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['person_id', 'status']);
        });

        // V1: at most one ACTIVE/SUSPENDED link per Person and per User. A
        // suspended link still occupies its slot; ended links are history.
        DB::statement(
            'CREATE UNIQUE INDEX uq_user_person_links_current_person '
            ."ON user_person_links (person_id) WHERE status IN ('ACTIVE', 'SUSPENDED')"
        );
        DB::statement(
            'CREATE UNIQUE INDEX uq_user_person_links_current_user '
            ."ON user_person_links (user_id) WHERE status IN ('ACTIVE', 'SUSPENDED')"
        );

        // Postgres-only, as for the existing CHECK constraints: SQLite (test
        // suite) relies on the enums and, later, the lifecycle actions.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE user_person_links ADD CONSTRAINT chk_user_person_link_type CHECK (link_type IN ('SELF'))");
            DB::statement(
                'ALTER TABLE user_person_links ADD CONSTRAINT chk_user_person_link_status '
                ."CHECK (status IN ('PENDING_VERIFICATION', 'VERIFIED', 'ACTIVE', 'SUSPENDED', 'ENDED'))"
            );
            DB::statement(
                'ALTER TABLE user_person_links ADD CONSTRAINT chk_user_person_link_method '
                ."CHECK (verification_method IS NULL OR verification_method IN ('SYSTEM_OTP_ACTIVATION', 'STAFF'))"
            );
            // An enabled (or suspended) link is always a verified one.
            DB::statement(
                'ALTER TABLE user_person_links ADD CONSTRAINT chk_user_person_link_verified '
                ."CHECK (status NOT IN ('ACTIVE', 'SUSPENDED') OR verified_at IS NOT NULL)"
            );
            DB::statement(
                'ALTER TABLE user_person_links ADD CONSTRAINT chk_user_person_link_ended '
                ."CHECK (status <> 'ENDED' OR (ended_at IS NOT NULL AND end_reason IS NOT NULL))"
            );
            // Reasons are codes, never free text.
            DB::statement(
                'ALTER TABLE user_person_links ADD CONSTRAINT chk_user_person_link_reasons CHECK ('
                ."(suspension_reason IS NULL OR suspension_reason ~ '^[A-Z][A-Z0-9_]{1,59}$') AND "
                ."(end_reason IS NULL OR end_reason ~ '^[A-Z][A-Z0-9_]{1,59}$'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_person_links');
    }
};
