<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FB-CIVIL-02F (docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md §L-6c): one row per
// staff user, Gaza-local day and opened civil record whose full National ID was
// shown at the STANDARD level. The UNIQUE key makes the first disclosure the only
// charged one: CivilLookupDisclosure inserts the row (ON CONFLICT DO NOTHING)
// and consumes one quota unit in the same transaction, so a rejected consume
// leaves no row and a concurrent insert of the same key waits for the first.
//
// No National ID, fingerprint or payload is stored — only the identity_ref
// release and record ids, already kept by the audit (record_id).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('civil_lookup_record_disclosures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('disclosure_date');
            $table->unsignedBigInteger('release_id');
            $table->unsignedBigInteger('record_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'disclosure_date', 'release_id', 'record_id'], 'uq_civil_lookup_record_disclosure');
            $table->index('disclosure_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('civil_lookup_record_disclosures');
    }
};
