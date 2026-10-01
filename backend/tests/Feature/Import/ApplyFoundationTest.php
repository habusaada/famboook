<?php

namespace Tests\Feature\Import;

use App\Enums\ImportApplyEffect;
use App\Enums\ImportApplyOutcome;
use App\Enums\ImportBatchStatus;
use App\Exceptions\DuplicateImportBatchException;
use App\Models\Clan;
use App\Models\Family;
use App\Models\ImportApplyRecord;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Person;
use App\Models\User;
use App\Support\Import\ImportBatchWorkbook;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SyntheticXlsx;
use Tests\TestCase;

/**
 * Phase 4B.1 Apply FOUNDATION (docs/03 §96b): lifecycle states, checksum
 * protection of started Applies, and the append-only provenance table.
 * No Apply exists — these tests write provenance rows directly. All synthetic.
 */
class ApplyFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('SUPER_ADMIN');
    }

    private function started(ImportBatchStatus $status, array $attributes = []): ImportBatch
    {
        // Valid under every PostgreSQL CHECK (an APPLIED batch carries applied_at).
        return ImportBatch::factory()->create([
            'status' => $status, 'apply_started_at' => now(), 'apply_plan_fingerprint' => hash('sha256', 'synthetic-plan'),
            ...($status === ImportBatchStatus::APPLIED ? ['applied_at' => now()] : []),
            ...$attributes,
        ]);
    }

    private function row(ImportBatch $batch, int $number = 2): ImportRow
    {
        return ImportRow::factory()->create(['import_batch_id' => $batch->id, 'row_number' => $number]);
    }

    // ================================================== lifecycle

    public function test_apply_started_states_are_exactly_applying_partially_applied_and_applied(): void
    {
        $started = array_filter(ImportBatchStatus::cases(), fn ($s) => $s->applyStarted());
        $this->assertSame(['APPLYING', 'PARTIALLY_APPLIED', 'APPLIED'], array_values(array_map(fn ($s) => $s->value, $started)));
        $this->assertFalse(ImportBatchStatus::FAILED->applyStarted());
    }

    public function test_a_started_apply_requires_apply_started_at_and_can_never_become_failed(): void
    {
        $batch = $this->started(ImportBatchStatus::APPLYING);
        $batch->update(['status' => ImportBatchStatus::PARTIALLY_APPLIED]);
        $this->assertSame(ImportBatchStatus::PARTIALLY_APPLIED, $batch->fresh()->status);

        // A partly applied batch cannot be marked FAILED (checksum protection).
        $this->expectException(LogicException::class);
        $batch->update(['status' => ImportBatchStatus::FAILED]);
    }

    public function test_an_apply_state_without_apply_started_at_is_refused(): void
    {
        $this->expectException(LogicException::class);
        ImportBatch::factory()->create(['status' => ImportBatchStatus::PARTIALLY_APPLIED]);
    }

    public function test_pre_apply_states_never_carry_apply_started_at(): void
    {
        $this->expectException(LogicException::class);
        ImportBatch::factory()->create(['status' => ImportBatchStatus::READY_FOR_REVIEW, 'apply_started_at' => now()]);
    }

    public function test_a_started_batch_is_frozen_for_every_wizard_step(): void
    {
        // A confirmed mapping always has its column_mapping (chk_import_batch_mapping_confirmed).
        $batch = $this->started(ImportBatchStatus::PARTIALLY_APPLIED, ['mapping_confirmed_at' => now(), 'column_mapping' => ['fields' => [], 'ignored' => []]]);

        $this->assertFalse($batch->isStaged());
        $this->actingAs($this->admin)->postJson("/api/v1/imports/initial-families/{$batch->uuid}/reconcile")->assertStatus(422);
        $this->actingAs($this->admin)->putJson("/api/v1/imports/initial-families/{$batch->uuid}/family-keys/resolution", ['source_family_key' => 'x', 'decision' => 'NO_BRANCH'])->assertStatus(422);
        $this->expectException(ValidationException::class);
        ImportBatchWorkbook::assertEditable($batch);
    }

    // ================================================== checksum protection

    #[DataProvider('protectedStates')]
    public function test_a_file_in_an_apply_state_cannot_be_staged_again_for_the_same_clan(ImportBatchStatus $status): void
    {
        $existing = $this->started($status);

        try {
            ImportBatchWorkbook::assertNotStaged($existing->clan_id, $existing->source_checksum);
            $this->fail('Expected a duplicate batch.');
        } catch (DuplicateImportBatchException $e) {
            $this->assertTrue($e->existing->is($existing));
        }

        // The partial unique index enforces it below the application too.
        $this->expectException(QueryException::class);
        ImportBatch::factory()->create(['clan_id' => $existing->clan_id, 'source_checksum' => $existing->source_checksum]);
    }

    public static function protectedStates(): array
    {
        return [
            'APPLYING' => [ImportBatchStatus::APPLYING],
            'PARTIALLY_APPLIED' => [ImportBatchStatus::PARTIALLY_APPLIED],
            'APPLIED' => [ImportBatchStatus::APPLIED],
        ];
    }

    public function test_uploading_the_file_of_a_partially_applied_batch_is_refused_with_409(): void
    {
        $clan = Clan::create(['code' => 'SYN_CHK', 'name' => 'عشيرة تحقق']);
        $path = SyntheticXlsx::write(['Sheet1' => [1 => ['المفتاح', 'رقم الهوية', 'الاسم'], 2 => ['مفتاح', '900000001', 'اسم تجريبي']]]);
        $this->started(ImportBatchStatus::PARTIALLY_APPLIED, ['clan_id' => $clan->id, 'source_checksum' => hash_file('sha256', $path)]);

        $this->actingAs($this->admin)->post('/api/v1/imports/initial-families', [
            'clan_code' => 'SYN_CHK', 'import_mode' => 'INITIAL', 'file' => new UploadedFile($path, 'synthetic.xlsx', null, null, true),
        ], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('existing_batch.status', 'PARTIALLY_APPLIED');
        $this->assertSame(1, ImportBatch::count());
    }

    public function test_only_a_pre_apply_failed_batch_releases_its_file(): void
    {
        $failed = ImportBatch::factory()->create(['status' => ImportBatchStatus::FAILED]);
        ImportBatchWorkbook::assertNotStaged($failed->clan_id, $failed->source_checksum);
        ImportBatch::factory()->create(['clan_id' => $failed->clan_id, 'source_checksum' => $failed->source_checksum]);
        $this->assertSame(2, ImportBatch::where('source_checksum', $failed->source_checksum)->count());
    }

    // ================================================== provenance

    public function test_provenance_columns_hold_ids_and_codes_only(): void
    {
        $this->assertSame(
            ['id', 'import_batch_id', 'import_row_id', 'effect_key', 'entity_type', 'entity_id', 'role', 'spouse_slot', 'outcome', 'reason_code', 'applied_by', 'created_at'],
            Schema::getColumnListing('import_apply_records'),
        );
    }

    public function test_every_effect_derives_a_consistent_shape(): void
    {
        $this->assertSame(['PERSON', 'HEAD', null], [ImportApplyEffect::HEAD_PERSON->entityType(), ImportApplyEffect::HEAD_PERSON->role(), ImportApplyEffect::HEAD_PERSON->spouseSlot()]);
        $this->assertSame(['FAMILY', null, null], [ImportApplyEffect::FAMILY->entityType(), ImportApplyEffect::FAMILY->role(), ImportApplyEffect::FAMILY->spouseSlot()]);
        $this->assertSame(['MEMBERSHIP', 'SPOUSE', 3], [ImportApplyEffect::SPOUSE_3_MEMBERSHIP->entityType(), ImportApplyEffect::SPOUSE_3_MEMBERSHIP->role(), ImportApplyEffect::SPOUSE_3_MEMBERSHIP->spouseSlot()]);
        $this->assertSame(['PERSON', 'SPOUSE', 4], [ImportApplyEffect::spousePerson(4)->entityType(), ImportApplyEffect::spousePerson(4)->role(), ImportApplyEffect::spousePerson(4)->spouseSlot()]);
        $this->assertSame(['HOUSEHOLD_DECLARATION', 'RESIDENCE'], [ImportApplyEffect::HOUSEHOLD_DECLARATION->entityType(), ImportApplyEffect::RESIDENCE->entityType()]);
        $this->assertCount(13, ImportApplyEffect::cases());
    }

    public function test_created_reused_omitted_and_blocked_are_recorded_with_provenance(): void
    {
        $batch = $this->started(ImportBatchStatus::APPLYING);
        $row = $this->row($batch);
        $head = Person::factory()->create();
        $existing = Person::factory()->create();
        $family = Family::factory()->create();

        $created = ImportApplyRecord::record($row, ImportApplyEffect::HEAD_PERSON, ImportApplyOutcome::CREATED, $head, null, $this->admin->id);
        ImportApplyRecord::record($row, ImportApplyEffect::FAMILY, ImportApplyOutcome::CREATED, $family, 'HOUSEHOLD_HEAD_DECEASED', $this->admin->id);
        ImportApplyRecord::record($row, ImportApplyEffect::spousePerson(1), ImportApplyOutcome::REUSED, $existing, null, $this->admin->id);
        $omitted = ImportApplyRecord::record($row, ImportApplyEffect::spouseMembership(1), ImportApplyOutcome::OMITTED, null, 'SPOUSE_HEADS_OWN_HOUSEHOLD', $this->admin->id);
        $blocked = ImportApplyRecord::record($row, ImportApplyEffect::spousePerson(2), ImportApplyOutcome::BLOCKED, null, 'SPOUSE_MULTIPLE_PERSONS', $this->admin->id);

        $this->assertSame([$batch->id, $row->id, 'PERSON', $head->id, 'HEAD', null], [$created->import_batch_id, $created->import_row_id, $created->entity_type, $created->entity_id, $created->role, $created->spouse_slot]);
        $this->assertTrue($created->entity()->is($head));
        $this->assertTrue($created->appliedBy->is($this->admin));
        $this->assertNotNull($created->created_at);
        $this->assertSame(['SPOUSE', 1, null], [$omitted->role, $omitted->spouse_slot, $omitted->entity()]);
        $this->assertSame('SPOUSE_MULTIPLE_PERSONS', $blocked->reason_code);
        $this->assertSame(5, $batch->applyRecords()->count());
        // "Which import row created this Person?"
        $this->assertSame($row->id, ImportApplyRecord::where('entity_type', 'PERSON')->where('entity_id', $head->id)->where('outcome', 'CREATED')->value('import_row_id'));
    }

    public function test_the_same_effect_of_a_row_can_never_be_recorded_twice(): void
    {
        $row = $this->row($this->started(ImportBatchStatus::APPLYING));
        ImportApplyRecord::record($row, ImportApplyEffect::spouseMembership(2), ImportApplyOutcome::OMITTED, null, 'SPOUSE_HEADS_OWN_HOUSEHOLD', $this->admin->id);

        $this->expectException(QueryException::class);
        ImportApplyRecord::record($row, ImportApplyEffect::spouseMembership(2), ImportApplyOutcome::OMITTED, null, 'SPOUSE_HEADS_OWN_HOUSEHOLD', $this->admin->id);
    }

    public function test_person_and_membership_effects_of_the_same_slot_are_distinct(): void
    {
        $row = $this->row($this->started(ImportBatchStatus::APPLYING));
        ImportApplyRecord::record($row, ImportApplyEffect::spousePerson(1), ImportApplyOutcome::REUSED, Person::factory()->create(), null, $this->admin->id);
        ImportApplyRecord::record($row, ImportApplyEffect::spouseMembership(1), ImportApplyOutcome::OMITTED, null, 'NO_SINGLE_LIVING_HEAD', $this->admin->id);
        ImportApplyRecord::record($row, ImportApplyEffect::spouseMembership(2), ImportApplyOutcome::OMITTED, null, 'NO_SINGLE_LIVING_HEAD', $this->admin->id);

        $this->assertSame(3, ImportApplyRecord::where('import_row_id', $row->id)->count());
    }

    public function test_one_entity_is_created_by_at_most_one_effect(): void
    {
        $batch = $this->started(ImportBatchStatus::APPLYING);
        $person = Person::factory()->create();
        ImportApplyRecord::record($this->row($batch, 2), ImportApplyEffect::HEAD_PERSON, ImportApplyOutcome::CREATED, $person, null, $this->admin->id);
        // REUSING it from another row is fine…
        ImportApplyRecord::record($this->row($batch, 3), ImportApplyEffect::spousePerson(1), ImportApplyOutcome::REUSED, $person, null, $this->admin->id);

        // …claiming to have CREATED it twice is not.
        $this->expectException(QueryException::class);
        ImportApplyRecord::record($this->row($batch, 4), ImportApplyEffect::spousePerson(1), ImportApplyOutcome::CREATED, $person, null, $this->admin->id);
    }

    public function test_a_row_must_belong_to_the_recorded_batch(): void
    {
        $row = $this->row($this->started(ImportBatchStatus::APPLYING));
        $other = $this->started(ImportBatchStatus::APPLYING);

        $this->expectException(QueryException::class);
        DB::table('import_apply_records')->insert([
            'import_batch_id' => $other->id, 'import_row_id' => $row->id, 'effect_key' => 'FAMILY', 'entity_type' => 'FAMILY',
            'outcome' => 'OMITTED', 'reason_code' => 'X_TEST', 'applied_by' => $this->admin->id,
        ]);
    }

    #[DataProvider('invalidRecords')]
    public function test_invalid_provenance_is_refused(ImportApplyOutcome $outcome, bool $withEntity, ?string $reason): void
    {
        $row = $this->row($this->started(ImportBatchStatus::APPLYING));

        $this->expectException(LogicException::class);
        ImportApplyRecord::record($row, ImportApplyEffect::HEAD_PERSON, $outcome, $withEntity ? Person::factory()->create() : null, $reason, $this->admin->id);
    }

    public static function invalidRecords(): array
    {
        return [
            'CREATED without entity' => [ImportApplyOutcome::CREATED, false, null],
            'REUSED without entity' => [ImportApplyOutcome::REUSED, false, null],
            'OMITTED with entity' => [ImportApplyOutcome::OMITTED, true, 'SOME_REASON'],
            'OMITTED without reason' => [ImportApplyOutcome::OMITTED, false, null],
            'BLOCKED without reason' => [ImportApplyOutcome::BLOCKED, false, null],
            'reason that is a value' => [ImportApplyOutcome::BLOCKED, false, '900000001'],
            'reason with free text' => [ImportApplyOutcome::BLOCKED, false, 'name mismatch'],
        ];
    }

    public function test_a_record_cannot_point_at_a_missing_entity_or_contradict_its_effect(): void
    {
        $row = $this->row($this->started(ImportBatchStatus::APPLYING));
        $ghost = new Person;
        $ghost->id = 999999;

        try {
            ImportApplyRecord::record($row, ImportApplyEffect::HEAD_PERSON, ImportApplyOutcome::CREATED, $ghost, null, $this->admin->id);
            $this->fail('A missing entity must be refused.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        ImportApplyRecord::create([
            'import_batch_id' => $row->import_batch_id, 'import_row_id' => $row->id, 'effect_key' => ImportApplyEffect::SPOUSE_2_PERSON,
            'entity_type' => 'PERSON', 'role' => 'SPOUSE', 'spouse_slot' => 1, 'outcome' => ImportApplyOutcome::BLOCKED,
            'reason_code' => 'X_TEST', 'applied_by' => $this->admin->id,
        ]);
    }

    public function test_provenance_is_append_only_and_survives_a_soft_deleted_entity(): void
    {
        $row = $this->row($this->started(ImportBatchStatus::APPLYING));
        $person = Person::factory()->create();
        $record = ImportApplyRecord::record($row, ImportApplyEffect::HEAD_PERSON, ImportApplyOutcome::CREATED, $person, null, $this->admin->id);

        $person->delete();
        $this->assertTrue($record->fresh()->entity()->is($person));

        try {
            $record->update(['reason_code' => 'CHANGED']);
            $this->fail('Provenance must not be updated.');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $record->delete();
    }

    public function test_the_foundation_writes_no_registry_rows_by_itself(): void
    {
        $batch = $this->started(ImportBatchStatus::APPLYING);
        $this->row($batch);

        $this->assertSame([0, 0, 0], [Person::count(), Family::count(), DB::table('family_memberships')->count()]);
        $this->assertSame(0, ImportApplyRecord::count());
    }
}
