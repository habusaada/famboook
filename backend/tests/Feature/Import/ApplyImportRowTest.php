<?php

namespace Tests\Feature\Import;

use App\Actions\ApplyImportRowAction;
use App\Actions\RecordImportApplyEffectAction;
use App\Enums\FamilyActivityType;
use App\Enums\ImportApplyEffect as E;
use App\Enums\ImportApplyIntent as I;
use App\Enums\ImportApplyOutcome as O;
use App\Enums\ImportBatchStatus;
use App\Exceptions\ImportApplyExecutionException;
use App\Models\Branch;
use App\Models\Clan;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyHouseholdDeclaration;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\ImportApplyRecord;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Models\User;
use App\Support\Import\Apply\ApprovedApplyPlan;
use App\Support\Import\Apply\ImportApplyEffectPlan;
use App\Support\Import\Apply\ImportApplyPlanner;
use App\Support\Import\Apply\ImportApplyPlanningContext;
use App\Support\Import\Apply\ImportBatchApplyPlan;
use App\Support\Import\Apply\ImportRowApplyPlan;
use App\Support\Import\Apply\ImportRowApplyResult;
use App\Support\NationalIdFingerprint;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\SyntheticXlsx;
use Tests\TestCase;

/**
 * Phase 4B.4b — the Row Executor (docs/03 §96b). SYNTHETIC batches only:
 * each is staged, reconciled, approved by a fixture (the future runner's job)
 * and executed row by row. ONE SOURCE ROW = ONE TRANSACTION: every failure
 * must leave zero partial effects.
 */
class ApplyImportRowTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/imports/initial-families';

    private const STARTED = '2026-09-15 10:30:00';

    private const HEADERS = [
        'هويتك', 'المفتاح', 'رقم الهوية', 'الاسم', 'الميلاد', 'الجنس', 'الحالة الاجتماعية', 'الديانة',
        'المدينة', 'حالة الوفاة', 'الوفاة', 'أفراد الأسرة', 'أبناءذكور احياء', 'أبناءإناث أحياء', 'الجوال',
        'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة',
    ];

    private Clan $clan;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('SUPER_ADMIN');
        $this->clan = Clan::create(['code' => 'SYN_TARGET', 'name' => 'عشيرة الهدف']);
    }

    // ================================================== fixtures

    private function row(array $o = [], array $wives = []): array
    {
        $cells = [
            '555000111', $o['key'] ?? 'مفتاح أ', $o['id'], 'رب أسرة '.$o['id'], new DateTimeImmutable('1980-01-15'),
            $o['gender'] ?? 'ذكر', $o['marital'] ?? 'متزوج', 'قيمة-ديانة-اختبارية',
            array_key_exists('city', $o) ? $o['city'] : 'مدينة أصلية', $o['life'] ?? 'حي', $o['death'] ?? null,
            array_key_exists('size', $o) ? $o['size'] : 6, array_key_exists('sons', $o) ? $o['sons'] : 2, array_key_exists('daughters', $o) ? $o['daughters'] : 2, '0590000000',
        ];
        foreach (range(0, 3) as $k) {
            $cells[] = $wives[$k][0] ?? null;
            $cells[] = $wives[$k][1] ?? null;
        }

        return $cells;
    }

    private function batch(array $rows, array $decisions = []): ImportBatch
    {
        $path = SyntheticXlsx::write(['Sheet1' => [1 => self::HEADERS] + $rows]);
        $id = $this->actingAs($this->admin)->post(self::BASE, [
            'clan_code' => 'SYN_TARGET', 'import_mode' => 'INITIAL', 'file' => new UploadedFile($path, 'synthetic.xlsx', null, null, true),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $fields = [];
        $ignored = [];
        foreach ($this->actingAs($this->admin)->getJson(self::BASE."/{$id}/columns")->json('data.columns') as $c) {
            $c['suggested_field'] ? $fields[$c['suggested_field']] = $c['letter'] : $ignored[] = $c['letter'];
        }
        $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/mapping", ['mapping' => $fields, 'ignored' => $ignored])->assertOk();
        $keys = array_column($this->actingAs($this->admin)->getJson(self::BASE."/{$id}/family-keys")->json('data'), 'key');
        foreach ($decisions as $key => $body) {
            $this->actingAs($this->admin)->putJson(self::BASE."/{$id}/family-keys/resolution", ['source_family_key' => $key, ...$body])->assertOk();
        }
        $rest = array_values(array_diff($keys, array_keys($decisions)));
        if ($rest !== []) {
            $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/family-keys/bulk", ['decision' => 'NO_BRANCH', 'items' => array_map(fn ($k) => ['source_family_key' => $k], $rest)])->assertOk();
        }
        $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/reconcile")->assertOk();

        return ImportBatch::where('uuid', $id)->firstOrFail();
    }

    /** Fixture for the future runner's start: APPLYING + the approved plan fingerprint. */
    private function approve(ImportBatch $batch, ?callable $alter = null): ApprovedApplyPlan
    {
        $batch->update(['status' => ImportBatchStatus::APPLYING, 'apply_started_at' => self::STARTED, 'apply_plan_fingerprint' => hash('sha256', 'pending'), 'applied_by' => $this->admin->id]);
        $plan = app(ImportApplyPlanner::class)->plan($batch->fresh(), ImportApplyPlanningContext::asOfApplyStart($batch->fresh()));
        $this->assertSame([], $plan->preconditionFailures);
        if ($alter) {
            $plan = $alter($plan);
        }
        $batch->update(['apply_plan_fingerprint' => $plan->fingerprint()]);

        return ApprovedApplyPlan::verify($batch->fresh(), $plan);
    }

    private function importRow(ImportBatch $batch, int $n): ImportRow
    {
        return ImportRow::where('import_batch_id', $batch->id)->where('row_number', $n)->firstOrFail();
    }

    private function applyRow(ApprovedApplyPlan $plan, ImportBatch $batch, int $n): ImportRowApplyResult
    {
        return app(ApplyImportRowAction::class)->handle($plan, $this->importRow($batch, $n), $this->admin);
    }

    private function counts(): array
    {
        return [
            'persons' => Person::withTrashed()->count(), 'families' => Family::withTrashed()->count(), 'memberships' => FamilyMembership::count(),
            'residences' => FamilyResidence::count(), 'declarations' => FamilyHouseholdDeclaration::count(), 'activities' => FamilyActivity::count(),
            'records' => ImportApplyRecord::count(), 'applied_rows' => ImportRow::where('status', 'APPLIED')->count(),
        ];
    }

    /** Runs and expects a structured failure that leaves ZERO partial writes. */
    private function assertFailsCleanly(string $code, callable $run): void
    {
        $before = $this->counts();
        try {
            $run();
            $this->fail("Expected {$code}.");
        } catch (ImportApplyExecutionException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($code, $e->getMessage());
        }
        $this->assertSame($before, $this->counts());
    }

    private function record(ImportBatch $batch, int $n, E $effect): ?ImportApplyRecord
    {
        return ImportApplyRecord::where('import_row_id', $this->importRow($batch, $n)->id)->where('effect_key', $effect->value)->first();
    }

    /** Rebuilds an approved plan with one row altered (test-only; approved by the fixture). */
    private function altered(ImportBatchApplyPlan $plan, int $n, callable $effects): ImportBatchApplyPlan
    {
        $rows = array_map(function (ImportRowApplyPlan $r) use ($n, $effects) {
            if ($r->rowNumber !== $n) {
                return $r;
            }

            return new ImportRowApplyPlan($r->importRowId, $r->rowNumber, $r->sourceFamilyKey, $r->headNationalIdMasked, $effects($r->effects), $r->warnings, $r->rowBlocks, $r->spouseNationalIdsMasked);
        }, $plan->rows);

        return new ImportBatchApplyPlan($plan->batchId, $plan->reconciliationFingerprint, $plan->preconditionFailures, $rows);
    }

    // ================================================== full row

    public function test_a_full_row_is_applied_atomically_with_complete_provenance(): void
    {
        $batch = $this->batch([2 => $this->row(['id' => 930000011], [[930000012, 'زوجة']])]);
        $plan = $this->approve($batch);

        $result = $this->applyRow($plan, $batch, 2);

        $this->assertSame([ImportRowApplyResult::APPLIED, 7, [], 0], [$result->outcome, $result->provenanceRecords, $result->pendingEffects, $result->completedDependentLinks]);
        $row = $this->importRow($batch, 2);
        $family = Family::findOrFail($result->familyId);
        $this->assertSame(['APPLIED', $family->id], [$row->status->value, $row->family_id]);
        $this->assertSame([$this->clan->id, null, 'ACTIVE', 'IMPORT', '2026-09-15'], [$family->clan_id, $family->branch_id, $family->status->value, $family->registration_source->value, $family->registration_date->toDateString()]);
        $this->assertSame(sprintf('FAM-%06d', $family->id), $family->family_code);

        $head = Person::where('national_id', '930000011')->sole();
        $wife = Person::where('national_id', '930000012')->sole();
        $this->assertSame(['ALIVE', 'MALE', 'MARRIED'], [$head->life_status->value, $head->gender->value, $head->marital_status->value]);
        $this->assertSame(['UNKNOWN', 'FEMALE', 'UNKNOWN', null, null], [$wife->life_status->value, $wife->gender->value, $wife->marital_status->value, $wife->birth_date, $wife->mobile]);

        $memberships = FamilyMembership::where('family_id', $family->id)->orderBy('id')->get();
        $this->assertSame([[$head->id, true, 'HEAD', '2026-09-15'], [$wife->id, false, 'SPOUSE', '2026-09-15']], $memberships->map(fn ($m) => [$m->person_id, $m->is_household_head, $m->relationshipType->code, $m->started_at->toDateString()])->all());

        $declaration = FamilyHouseholdDeclaration::where('family_id', $family->id)->sole();
        $this->assertSame([6, 2, 2, 'IMPORT', null], [$declaration->declared_household_size, $declaration->declared_living_sons, $declaration->declared_living_daughters, $declaration->source->value ?? $declaration->source, $declaration->declared_at]);
        $residence = FamilyResidence::where('family_id', $family->id)->sole();
        $this->assertSame(['مدينة أصلية', 'IMPORT', '2026-09-15', null, null], [$residence->original_residence_text, $residence->source, $residence->started_at->toDateString(), $residence->city, $residence->displacement_status]);

        // Provenance: one record per planned effect, pointing at the real rows.
        $records = ImportApplyRecord::where('import_row_id', $row->id)->get()->keyBy(fn ($r) => $r->effect_key->value);
        $this->assertSame(['CREATED', $head->id], [$records['HEAD_PERSON']->outcome->value, $records['HEAD_PERSON']->entity_id]);
        $this->assertSame([$family->id, $memberships[0]->id, $wife->id, $memberships[1]->id, $declaration->id, $residence->id], [
            $records['FAMILY']->entity_id, $records['HEAD_MEMBERSHIP']->entity_id, $records['SPOUSE_1_PERSON']->entity_id,
            $records['SPOUSE_1_MEMBERSHIP']->entity_id, $records['HOUSEHOLD_DECLARATION']->entity_id, $records['RESIDENCE']->entity_id,
        ]);
        $this->assertSame([$this->admin->id], $records->pluck('applied_by')->unique()->values()->all());

        // Activity: FAMILY_CREATED + FAMILY_IMPORTED (safe ids only) + declaration.
        $activity = FamilyActivity::where('family_id', $family->id)->orderBy('id')->get();
        $this->assertSame(['FAMILY_CREATED', 'FAMILY_IMPORTED', 'HOUSEHOLD_DECLARATION_RECORDED'], $activity->map(fn ($a) => $a->event_type->value)->all());
        $this->assertSame(['import_batch_id' => $batch->id, 'source_row_number' => 2], $activity[1]->metadata);
        $this->assertSame([2, 1], [Person::count(), Family::count()]); // no fake children
    }

    public function test_an_existing_head_is_reused_without_updates(): void
    {
        $existing = Person::factory()->create(['national_id' => '930000021', 'gender' => 'MALE', 'birth_date' => '1980-01-15', 'full_name' => 'اسم في السجل', 'mobile' => '0591111111']);
        $batch = $this->batch([2 => $this->row(['id' => 930000021])]);
        $plan = $this->approve($batch);
        $updatedAt = $existing->fresh()->updated_at;

        $this->applyRow($plan, $batch, 2);

        $fresh = $existing->fresh();
        $this->assertSame(['اسم في السجل', '0591111111', (string) $updatedAt], [$fresh->full_name, $fresh->mobile, (string) $fresh->updated_at]);
        $this->assertSame(['REUSED', $existing->id], [$this->record($batch, 2, E::HEAD_PERSON)->outcome->value, $this->record($batch, 2, E::HEAD_PERSON)->entity_id]);
        $this->assertTrue($fresh->activeMembership()->first()->is_household_head);
        $this->assertSame(1, Person::count());
    }

    public function test_a_deceased_head_is_created_deceased_with_evidence_and_no_death_event(): void
    {
        $batch = $this->batch([2 => $this->row(['id' => 930000031, 'life' => 'متوفى', 'death' => new DateTimeImmutable('2020-05-01')])]);
        $plan = $this->approve($batch);

        $result = $this->applyRow($plan, $batch, 2);

        $head = Person::where('national_id', '930000031')->sole();
        $this->assertSame(['DECEASED', '2020-05-01'], [$head->life_status->value, $head->death_date->toDateString()]);
        $this->assertSame('HOUSEHOLD_HEAD_DECEASED', $this->record($batch, 2, E::FAMILY)->reason_code);
        $this->assertTrue(Family::findOrFail($result->familyId)->householdHeadMembership->is_active);
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::PERSON_DEATH_RECORDED)->count());
    }

    public function test_a_resolved_branch_is_used_and_omissions_are_recorded_without_writes(): void
    {
        $branch = Branch::create(['clan_id' => $this->clan->id, 'code' => 'SYN_BR', 'name' => 'فرع']);
        $batch = $this->batch([2 => $this->row(['id' => 930000041, 'city' => null, 'size' => null, 'sons' => null, 'daughters' => null], [[null, 'زوجة بلا هوية']])],
            ['مفتاح أ' => ['decision' => 'MATCH_EXISTING_BRANCH', 'branch_id' => $branch->uuid]]);
        $plan = $this->approve($batch);

        $result = $this->applyRow($plan, $batch, 2);

        $this->assertSame($branch->id, Family::findOrFail($result->familyId)->branch_id);
        foreach ([[E::RESIDENCE, 'NO_ORIGINAL_RESIDENCE'], [E::HOUSEHOLD_DECLARATION, 'NO_DECLARED_VALUES'], [E::SPOUSE_1_PERSON, 'SPOUSE_WITHOUT_NATIONAL_ID'], [E::SPOUSE_1_MEMBERSHIP, 'SPOUSE_WITHOUT_NATIONAL_ID']] as [$effect, $reason]) {
            $r = $this->record($batch, 2, $effect);
            $this->assertSame(['OMITTED', null, $reason], [$r->outcome->value, $r->entity_id, $r->reason_code]);
        }
        $this->assertSame([0, 0, 1, 1], [FamilyResidence::count(), FamilyHouseholdDeclaration::count(), Person::count(), FamilyMembership::count()]);
    }

    public function test_an_existing_spouse_with_a_membership_is_reused_only_as_the_planned_omission(): void
    {
        $batch = $this->batch([2 => $this->row(['id' => 930000051], [[930000052, 'زوجة']]), 3 => $this->row(['id' => 930000053, 'key' => 'ب'], [[930000054, 'زوجة']])]);
        // Registry facts before approval (the approved plan sees them).
        $spouse = Person::factory()->create(['national_id' => '930000052', 'gender' => 'FEMALE']);
        FamilyMembership::factory()->create(['person_id' => $spouse->id, 'family_id' => Family::factory()->create()->id, 'is_household_head' => false]);
        $free = Person::factory()->create(['national_id' => '930000054', 'gender' => 'FEMALE']);
        $plan = $this->approve($batch);
        $this->assertSame(['OMIT', 'CREATE'], [$plan->row(2)->intentOf(E::SPOUSE_1_MEMBERSHIP)->value, $plan->row(3)->intentOf(E::SPOUSE_1_MEMBERSHIP)->value]);

        $this->applyRow($plan, $batch, 2);
        $this->assertSame(['REUSED', $spouse->id], [$this->record($batch, 2, E::SPOUSE_1_PERSON)->outcome->value, $this->record($batch, 2, E::SPOUSE_1_PERSON)->entity_id]);
        $this->assertSame('PERSON_ALREADY_HAS_ACTIVE_MEMBERSHIP', $this->record($batch, 2, E::SPOUSE_1_MEMBERSHIP)->reason_code);

        // A planned CREATE membership whose Person got linked meanwhile fails.
        FamilyMembership::factory()->create(['person_id' => $free->id, 'family_id' => Family::factory()->create()->id, 'is_household_head' => false]);
        $this->assertFailsCleanly('SPOUSE_PERSON_NOW_LINKED', fn () => $this->applyRow($plan, $batch, 3));
    }

    // ================================================== failures roll back everything

    public function test_a_failure_after_family_creation_rolls_back_the_whole_row(): void
    {
        $batch = $this->batch([2 => $this->row(['id' => 930000061], [[930000062, 'زوجة']])]);
        $plan = $this->approve($batch);
        RelationshipType::where('code', 'HEAD')->update(['is_active' => false]);

        $this->assertFailsCleanly('RELATIONSHIP_TYPE_UNAVAILABLE', fn () => $this->applyRow($plan, $batch, 2));
        $this->assertSame('PENDING', $this->importRow($batch, 2)->status->value);
    }

    public function test_a_failure_after_person_creation_rolls_back_the_person(): void
    {
        $branch = Branch::create(['clan_id' => $this->clan->id, 'code' => 'SYN_OFF', 'name' => 'فرع']);
        $batch = $this->batch([2 => $this->row(['id' => 930000071])], ['مفتاح أ' => ['decision' => 'MATCH_EXISTING_BRANCH', 'branch_id' => $branch->uuid]]);
        $plan = $this->approve($batch);
        $branch->update(['is_active' => false]);

        $this->assertFailsCleanly('BRANCH_NOT_SELECTABLE', fn () => $this->applyRow($plan, $batch, 2));
        $this->assertSame(0, Person::where('national_id', '930000071')->count());
    }

    public function test_a_failure_in_the_declaration_or_residence_rolls_back_the_whole_row(): void
    {
        $batch = $this->batch([2 => $this->row(['id' => 930000081], [[930000082, 'زوجة']])]);
        $plan = $this->approve($batch);

        FamilyHouseholdDeclaration::creating(fn () => throw new RuntimeException('synthetic declaration failure'));
        $this->assertFailsCleanly('UNEXPECTED_ERROR', fn () => $this->applyRow($plan, $batch, 2));
        FamilyHouseholdDeclaration::flushEventListeners();
        FamilyResidence::creating(fn () => throw new RuntimeException('synthetic residence failure'));
        $this->assertFailsCleanly('UNEXPECTED_ERROR', fn () => $this->applyRow($plan, $batch, 2));
        FamilyResidence::flushEventListeners();

        // Nothing leaked: the row still applies cleanly afterwards.
        $this->assertSame(ImportRowApplyResult::APPLIED, $this->applyRow($plan, $batch, 2)->outcome);
    }

    public function test_a_national_id_taken_at_execution_fails_cleanly(): void
    {
        $batch = $this->batch([2 => $this->row(['id' => 930000091])]);
        $plan = $this->approve($batch);
        Person::factory()->create(['national_id' => '930000091']);

        $this->assertFailsCleanly('NATIONAL_ID_TAKEN', fn () => $this->applyRow($plan, $batch, 2));
    }

    public function test_a_reused_head_that_gained_a_membership_fails_cleanly(): void
    {
        $existing = Person::factory()->create(['national_id' => '930000101', 'gender' => 'MALE', 'birth_date' => '1980-01-15']);
        $batch = $this->batch([2 => $this->row(['id' => 930000101])]);
        $plan = $this->approve($batch);
        FamilyMembership::factory()->householdHead()->create(['person_id' => $existing->id, 'family_id' => Family::factory()->create()->id]);

        $this->assertFailsCleanly('HEAD_PERSON_NOW_LINKED', fn () => $this->applyRow($plan, $batch, 2));
    }

    // ================================================== cross-row Persons

    /** Polygamous pair: row 2 (husband, wives w1 + w2) ↔ row 3 (w2 heads her own row, names him). */
    private function polygamyBatch(): ImportBatch
    {
        return $this->batch([
            2 => $this->row(['id' => 930000111, 'marital' => 'متعدد الزوجات'], [[930000112, 'زوجة أولى'], [930000113, 'زوجة ثانية']]),
            3 => $this->row(['id' => 930000113, 'gender' => 'أنثى', 'marital' => 'متزوجة', 'key' => 'ب'], [[930000111, 'الزوج']]),
        ]);
    }

    public function test_dependent_first_stays_pending_and_the_owner_completes_the_link(): void
    {
        $batch = $this->polygamyBatch();
        $plan = $this->approve($batch);

        $first = $this->applyRow($plan, $batch, 2);
        $this->assertSame(['SPOUSE_2_PERSON'], $first->pendingEffects);
        $this->assertNull($this->record($batch, 2, E::SPOUSE_2_PERSON));
        $this->assertSame(['OMITTED', 'INDEPENDENT_HOUSEHOLD_HEAD'], [$this->record($batch, 2, E::SPOUSE_2_MEMBERSHIP)->outcome->value, $this->record($batch, 2, E::SPOUSE_2_MEMBERSHIP)->reason_code]);
        $this->assertSame(0, Person::where('national_id', '930000113')->count()); // never created by a non-owner

        $second = $this->applyRow($plan, $batch, 3);
        $wife = Person::where('national_id', '930000113')->sole();
        $husband = Person::where('national_id', '930000111')->sole();
        // Owner (row 3's head) completed row 2's link; row 3 reused row 2's head directly.
        $this->assertSame([1, []], [$second->completedDependentLinks, $second->pendingEffects]);
        $this->assertSame(['REUSED', $wife->id], [$this->record($batch, 2, E::SPOUSE_2_PERSON)->outcome->value, $this->record($batch, 2, E::SPOUSE_2_PERSON)->entity_id]);
        $this->assertSame(['REUSED', $husband->id], [$this->record($batch, 3, E::SPOUSE_1_PERSON)->outcome->value, $this->record($batch, 3, E::SPOUSE_1_PERSON)->entity_id]);
        // Exactly one Person per identity; each head in its own Family only.
        $this->assertSame([3, 2], [Person::count(), FamilyMembership::where('is_household_head', true)->count()]);
        $this->assertSame(ImportRowApplyResult::ALREADY_APPLIED, $this->applyRow($plan, $batch, 2)->outcome);
    }

    public function test_owner_first_records_the_reuse_immediately(): void
    {
        $batch = $this->polygamyBatch();
        $plan = $this->approve($batch);

        $this->assertSame(['SPOUSE_1_PERSON'], $this->applyRow($plan, $batch, 3)->pendingEffects);
        $result = $this->applyRow($plan, $batch, 2);

        $this->assertSame([[], 1], [$result->pendingEffects, $result->completedDependentLinks]);
        $this->assertSame(Person::where('national_id', '930000113')->value('id'), $this->record($batch, 2, E::SPOUSE_2_PERSON)->entity_id);
        $this->assertSame(Person::where('national_id', '930000111')->value('id'), $this->record($batch, 3, E::SPOUSE_1_PERSON)->entity_id);
    }

    public function test_an_owner_rollback_also_rolls_back_the_link_completion(): void
    {
        $batch = $this->polygamyBatch();
        $plan = $this->approve($batch);
        $this->applyRow($plan, $batch, 2);

        FamilyResidence::creating(fn () => throw new RuntimeException('synthetic failure'));
        $this->assertFailsCleanly('UNEXPECTED_ERROR', fn () => $this->applyRow($plan, $batch, 3));
        $this->assertNull($this->record($batch, 2, E::SPOUSE_2_PERSON));
        $this->assertSame(0, Person::where('national_id', '930000113')->count());
    }

    public function test_a_pending_cross_row_person_can_never_back_a_created_membership(): void
    {
        $batch = $this->polygamyBatch();
        $plan = $this->approve($batch, fn ($p) => $this->altered($p, 2, function (array $effects) {
            $effects['SPOUSE_2_MEMBERSHIP'] = new ImportApplyEffectPlan(E::SPOUSE_2_MEMBERSHIP, I::CREATE, values: ['relationship_type' => 'SPOUSE']);

            return $effects;
        }));

        $this->assertFailsCleanly('CROSS_ROW_PERSON_NOT_MATERIALIZED', fn () => $this->applyRow($plan, $batch, 2));
    }

    // ================================================== identity evidence

    public function test_a_reused_head_whose_national_id_changed_after_planning_is_refused(): void
    {
        $existing = Person::factory()->create(['national_id' => '930000161', 'gender' => 'MALE', 'birth_date' => '1980-01-15']);
        $batch = $this->batch([2 => $this->row(['id' => 930000161])]);
        $plan = $this->approve($batch);
        DB::table('persons')->where('id', $existing->id)->update(['national_id' => '930000169']);

        $this->assertFailsCleanly('PERSON_IDENTITY_CHANGED', fn () => $this->applyRow($plan, $batch, 2));
    }

    public function test_a_reused_spouse_whose_national_id_changed_after_planning_is_refused(): void
    {
        $batch = $this->batch([2 => $this->row(['id' => 930000171], [[930000172, 'زوجة']])]);
        $spouse = Person::factory()->create(['national_id' => '930000172', 'gender' => 'FEMALE']);
        $plan = $this->approve($batch);
        $this->assertSame('REUSE', $plan->row(2)->intentOf(E::SPOUSE_1_PERSON)->value);
        DB::table('persons')->where('id', $spouse->id)->update(['national_id' => ' 930000172 x']);

        $this->assertFailsCleanly('PERSON_IDENTITY_CHANGED', fn () => $this->applyRow($plan, $batch, 2));
    }

    public function test_a_cross_row_person_whose_identity_changed_is_not_reused(): void
    {
        $batch = $this->polygamyBatch();
        $plan = $this->approve($batch);
        $this->applyRow($plan, $batch, 3); // the owner creates the wife
        DB::table('persons')->where('national_id', '930000113')->update(['national_id' => '930000119']);

        // Immediate cross-row reuse re-checks the materialized identity.
        $this->assertFailsCleanly('PERSON_IDENTITY_CHANGED', fn () => $this->applyRow($plan, $batch, 2));
        $this->assertNull($this->record($batch, 2, E::SPOUSE_2_PERSON));
    }

    public function test_the_owner_never_completes_a_link_whose_approved_identity_differs(): void
    {
        $batch = $this->polygamyBatch();
        // Test-only approval of a dependent effect carrying different evidence.
        $plan = $this->approve($batch, fn ($p) => $this->altered($p, 2, function (array $effects) {
            $e = $effects['SPOUSE_2_PERSON'];
            $effects['SPOUSE_2_PERSON'] = new ImportApplyEffectPlan($e->effect, $e->intent, $e->reason, ownerRow: $e->ownerRow, ownerEffect: $e->ownerEffect, values: ['identity' => NationalIdFingerprint::of('930000999')]);

            return $effects;
        }));
        $this->applyRow($plan, $batch, 2); // pending

        $this->assertFailsCleanly('PERSON_IDENTITY_CHANGED', fn () => $this->applyRow($plan, $batch, 3));
        $this->assertNull($this->record($batch, 2, E::SPOUSE_2_PERSON));
        $this->assertSame(0, Person::where('national_id', '930000113')->count());
    }

    public function test_neither_raw_ids_nor_identity_evidence_reach_provenance_or_activity(): void
    {
        $existing = Person::factory()->create(['national_id' => '930000181', 'gender' => 'MALE', 'birth_date' => '1980-01-15']);
        $batch = $this->batch([2 => $this->row(['id' => 930000181], [[930000182, 'زوجة']])]);
        $planner = app(ImportApplyPlanner::class);
        $this->assertSame($planner->plan($batch)->fingerprint(), $planner->plan($batch->fresh())->fingerprint());
        $plan = $this->approve($batch);
        $this->applyRow($plan, $batch, 2);

        $stored = json_encode([DB::table('import_apply_records')->get(), DB::table('family_activities')->get()]);
        foreach (['930000181', '930000182', NationalIdFingerprint::of('930000181'), NationalIdFingerprint::of('930000182')] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        $this->assertSame($existing->id, $this->record($batch, 2, E::HEAD_PERSON)->entity_id);
    }

    // ================================================== idempotency and integrity

    public function test_a_repeated_invocation_is_idempotent_and_an_inconsistent_row_is_refused(): void
    {
        $batch = $this->batch([2 => $this->row(['id' => 930000121], [[930000122, 'زوجة']])]);
        $plan = $this->approve($batch);
        $this->applyRow($plan, $batch, 2);
        $after = $this->counts();

        $again = $this->applyRow($plan, $batch, 2);
        $this->assertSame([ImportRowApplyResult::ALREADY_APPLIED, 7], [$again->outcome, $again->provenanceRecords]);
        $this->assertSame($after, $this->counts());

        // Remove one mandatory record behind the application's back: never repaired.
        DB::table('import_apply_records')->where('import_row_id', $this->importRow($batch, 2)->id)->where('effect_key', 'RESIDENCE')->delete();
        $this->assertFailsCleanly('ROW_ALREADY_APPLIED_INCONSISTENT', fn () => $this->applyRow($plan, $batch, 2));
    }

    public function test_conflicting_provenance_is_never_overwritten(): void
    {
        $batch = $this->batch([2 => $this->row(['id' => 930000131])]);
        $plan = $this->approve($batch);
        $row = $this->importRow($batch, 2);
        ImportApplyRecord::record($row, E::RESIDENCE, O::OMITTED, null, 'SYNTHETIC_CONFLICT', $this->admin->id);

        $this->assertFailsCleanly('PROVENANCE_CONFLICT', fn () => $this->applyRow($plan, $batch, 2));

        // The writer: identical → returns the existing record; different → conflict.
        $writer = app(RecordImportApplyEffectAction::class);
        $same = $writer->handle($row, E::RESIDENCE, O::OMITTED, null, 'SYNTHETIC_CONFLICT', $this->admin->id);
        $this->assertSame(1, ImportApplyRecord::where('import_row_id', $row->id)->count());
        $this->assertSame('SYNTHETIC_CONFLICT', $same->reason_code);
        $this->expectException(ImportApplyExecutionException::class);
        $writer->handle($row, E::RESIDENCE, O::OMITTED, null, 'OTHER_REASON', $this->admin->id);
    }

    public function test_only_the_approved_plan_for_the_right_batch_and_row_is_executed(): void
    {
        $batch = $this->batch([2 => $this->row(['id' => 930000141])]);
        $other = $this->batch([2 => $this->row(['id' => 930000142, 'key' => 'مفتاح آخر'])]);
        $plan = $this->approve($batch);

        // A row of another batch.
        $this->assertFailsCleanly('ROW_PLAN_MISMATCH', fn () => app(ApplyImportRowAction::class)->handle($plan, $this->importRow($other, 2), $this->admin));
        // A plan whose fingerprint is not the approved one cannot even be built.
        try {
            ApprovedApplyPlan::verify($batch->fresh(), $this->altered($plan->plan, 2, function (array $effects) {
                $effects['RESIDENCE'] = new ImportApplyEffectPlan(E::RESIDENCE, I::OMIT, 'NO_ORIGINAL_RESIDENCE');

                return $effects;
            }));
            $this->fail('An unapproved plan must be refused.');
        } catch (ImportApplyExecutionException $e) {
            $this->assertSame('APPLY_PLAN_NOT_APPROVED', $e->errorCode);
        }
        // A batch that is not applying.
        $batch->update(['status' => ImportBatchStatus::APPLIED, 'applied_at' => now()]);
        $this->assertFailsCleanly('BATCH_NOT_APPLYING', fn () => $this->applyRow($plan, $batch, 2));
    }

    public function test_a_missing_mandatory_effect_or_a_blocked_row_is_refused(): void
    {
        $batch = $this->batch([2 => $this->row(['id' => 930000151]), 3 => $this->row(['id' => 930000152, 'key' => 'ب', 'gender' => ''], [[930000153, 'زوج أو زوجة']])]);
        $plan = $this->approve($batch, fn ($p) => $this->altered($p, 2, function (array $effects) {
            unset($effects['RESIDENCE']);

            return $effects;
        }));

        $this->assertFailsCleanly('MANDATORY_EFFECT_MISSING', fn () => $this->applyRow($plan, $batch, 2));
        $this->assertFalse($plan->row(3)->executable());
        $this->assertFailsCleanly('ROW_PLAN_BLOCKED', fn () => $this->applyRow($plan, $batch, 3));
    }
}
