<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/04-DATABASE.md §59a "Family Activity Log (V1)". Append-only,
// system-generated timeline of successful family Domain Actions. It holds
// no previous/new values and no copied records: only what happened, to
// what, by whom and when. Nothing is backfilled for existing families.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_activities', function (Blueprint $table) {
            $table->id();
            // Public identifier; internal ids are never exposed by the API.
            $table->uuid('uuid')->unique();
            $table->foreignId('family_id')->constrained('families')->restrictOnDelete();
            // The authenticated application user, NOT the field researcher.
            // Nullable for future system/import operations.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            // Morph-mapped short names (family, person, residence, health_record).
            $table->nullableMorphs('subject');
            // Allow-listed keys only (see App\Support\FamilyActivityLog).
            $table->json('metadata')->nullable();
            // Immutable: no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['family_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_activities');
    }
};
