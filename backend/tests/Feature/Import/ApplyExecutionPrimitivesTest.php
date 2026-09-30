<?php

namespace Tests\Feature\Import;

use App\Actions\CreateFamilyAction;
use App\Actions\CreateFamilyMembershipAction;
use App\Actions\CreateFamilyResidenceAction;
use App\Actions\CreatePersonAction;
use App\Actions\RegisterFamilyAction;
use App\Enums\FamilyActivityType;
use App\Enums\ImportApplyEffect as E;
use App\Enums\ImportApplyOutcome as O;
use App\Enums\ImportBatchStatus;
use App\Exceptions\MissingRelationshipTypeException;
use App\Models\Branch;
use App\Models\Clan;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\ImportApplyRecord;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Models\User;
use App\Support\FamilyActivityLog;
use App\Support\Import\Apply\ImportApplyPlanner;
use App\Support\Import\Apply\ImportApplyPlanningContext;
use App\Support\Import\Apply\ImportBatchApplyPlan;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SyntheticXlsx;
use Tests\TestCase;

/**
 * Phase 4B.4a — Apply execution PRIMITIVES (docs/03 §96b): the apply-plan
 * and error fields, the canonical membership / residence creation actions,
 * the planner's as-of-Apply-start mode and the FAMILY_IMPORTED contract.
 * No Apply exists; fixtures simulate the minimum a future Apply would leave
 * behind. All data synthetic.
 */
class ApplyExecutionPrimitivesTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/imports/initial-families';

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
            '555000111', $o['key'] ?? 'مفتاح أ', $o['id'], 'رب أسرة '.$o['id'], new DateTimeImmutable('1980-01-15'), 'ذكر', 'متزوج',
            'قيمة-ديانة-اختبارية', 'مدينة أصلية', 'حي', null, 6, 2, 2, '0590000000',
        ];
        foreach (range(0, 3) as $k) {
            $cells[] = $wives[$k][0] ?? null;
            $cells[] = $wives[$k][1] ?? null;
        }

        return $cells;
    }

    /** Stage → resolve (NO_BRANCH unless given) → reconcile. */
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

    private function importRow(ImportBatch $batch, int $n): ImportRow
    {
        return ImportRow::where('import_batch_id', $batch->id)->where('row_number', $n)->firstOrFail();
    }

    /**
     * The minimum a future Apply would leave behind for ONE row: its head
     * (CREATED, or the approved existing Person REUSED), its Family and HEAD
     * membership, with provenance. Uses the canonical actions; not Apply.
     */
    private function simulateAppliedHead(ImportBatch $batch, int $n, ?Person $reuse = null): Family
    {
        $row = $this->importRow($batch, $n);
        $payload = json_decode($row->getRawOriginal('normalized_payload'), true);

        return DB::transaction(function () use ($batch, $row, $payload, $reuse) {
            $person = $reuse ?? app(CreatePersonAction::class)->handle(['full_name' => $payload['full_name'], 'national_id' => $payload['national_id'], 'gender' => 'MALE', 'life_status' => 'ALIVE'], $this->admin->id);
            $family = app(CreateFamilyAction::class)->handle(['clan_code' => 'SYN_TARGET', 'registration_date' => '2026-09-30', 'registration_source' => 'IMPORT'], $this->admin->id);
            $membership = app(CreateFamilyMembershipAction::class)->handle($family, $person, 'HEAD', true, '2026-09-30', $this->admin->id, $batch->clan_id);
            ImportApplyRecord::record($row, E::HEAD_PERSON, $reuse ? O::REUSED : O::CREATED, $person, null, $this->admin->id);
            ImportApplyRecord::record($row, E::FAMILY, O::CREATED, $family, null, $this->admin->id);
            ImportApplyRecord::record($row, E::HEAD_MEMBERSHIP, O::CREATED, $membership, null, $this->admin->id);

            return $family;
        });
    }

    private function start(ImportBatch $batch, string $fingerprint): void
    {
        $batch->update(['status' => ImportBatchStatus::APPLYING, 'apply_started_at' => now(), 'apply_plan_fingerprint' => $fingerprint, 'applied_by' => $this->admin->id]);
    }

    private function asOfStart(ImportBatch $batch): ImportBatchApplyPlan
    {
        return app(ImportApplyPlanner::class)->plan($batch->fresh(), ImportApplyPlanningContext::asOfApplyStart($batch->fresh()));
    }

    // ================================================== schema

    public function test_the_apply_plan_fingerprint_exists_exactly_while_apply_has_started(): void
    {
        $fp = hash('sha256', 'plan');
        $batch = ImportBatch::factory()->create(['status' => ImportBatchStatus::APPLYING, 'apply_started_at' => now(), 'apply_plan_fingerprint' => $fp]);
        $this->assertSame($fp, $batch->fresh()->apply_plan_fingerprint);

        foreach ([
            ['status' => ImportBatchStatus::APPLYING, 'apply_started_at' => now()],
            ['status' => ImportBatchStatus::READY_FOR_REVIEW, 'apply_plan_fingerprint' => $fp],
            ['status' => ImportBatchStatus::APPLYING, 'apply_started_at' => now(), 'apply_plan_fingerprint' => 'not-a-sha256'],
        ] as $bad) {
            try {
                ImportBatch::factory()->create($bad);
                $this->fail('Expected the invariant to refuse: '.json_encode(array_keys($bad)));
            } catch (LogicException) {
            }
        }
    }

    public function test_apply_errors_are_structured_codes_never_exception_text(): void
    {
        $batch = ImportBatch::factory()->create(['status' => ImportBatchStatus::PARTIALLY_APPLIED, 'apply_started_at' => now(),
            'apply_plan_fingerprint' => hash('sha256', 'plan'), 'apply_error_code' => 'NATIONAL_ID_TAKEN', 'apply_error_row_number' => 772]);
        $this->assertSame(['NATIONAL_ID_TAKEN', 772], [$batch->fresh()->apply_error_code, $batch->fresh()->apply_error_row_number]);

        foreach ([
            ['apply_error_code' => 'SQLSTATE[23505]: duplicate key value violates unique constraint'],
            ['apply_error_code' => 'national_id_taken'],
            ['apply_error_code' => null, 'apply_error_row_number' => 5],
            ['apply_error_code' => 'X_CODE', 'apply_error_row_number' => 0],
        ] as $bad) {
            try {
                $batch->update($bad);
                $this->fail('Expected refusal: '.json_encode($bad));
            } catch (LogicException) {
                $batch->refresh();
            }
        }
        // Structured fields only: no free-text error column exists for Apply.
        $this->assertSame(['apply_error_code', 'apply_error_row_number'], array_values(array_filter(Schema::getColumnListing('import_batches'), fn ($c) => str_starts_with($c, 'apply_error'))));
    }

    // ================================================== membership action

    private function family(): Family
    {
        return app(CreateFamilyAction::class)->handle(['clan_code' => 'SYN_TARGET', 'registration_date' => '2026-09-30', 'registration_source' => 'IMPORT'], null);
    }

    private function person(string $id): Person
    {
        return app(CreatePersonAction::class)->handle(['full_name' => 'شخص', 'national_id' => $id, 'gender' => 'FEMALE', 'life_status' => 'UNKNOWN'], null);
    }

    public function test_an_existing_person_is_attached_to_an_existing_family(): void
    {
        $family = $this->family();
        $head = $this->person('920000001');
        $spouse = $this->person('920000002');

        $h = app(CreateFamilyMembershipAction::class)->handle($family, $head, 'HEAD', true, '2026-09-30', $this->admin->id, $this->clan->id);
        $s = app(CreateFamilyMembershipAction::class)->handle($family, $spouse, 'SPOUSE', false, '2026-09-30', $this->admin->id);

        $this->assertSame([true, RelationshipType::where('code', 'HEAD')->value('id'), '2026-09-30', $this->admin->id], [$h->is_household_head, $h->relationship_type_id, $h->started_at->toDateString(), $h->created_by]);
        $this->assertSame([false, RelationshipType::where('code', 'SPOUSE')->value('id')], [$s->is_household_head, $s->relationship_type_id]);
        // Never a Person creator, never an activity writer.
        $this->assertSame([2, 1], [Person::count(), FamilyActivity::count()]);
    }

    public function test_a_second_active_membership_or_second_head_is_refused(): void
    {
        [$a, $b] = [$this->family(), $this->family()];
        $person = $this->person('920000011');
        app(CreateFamilyMembershipAction::class)->handle($a, $person, 'HEAD', true, '2026-09-30', null);

        foreach ([[$b, $person, 'SPOUSE', false], [$a, $this->person('920000012'), 'HEAD', true]] as [$family, $who, $type, $head]) {
            try {
                app(CreateFamilyMembershipAction::class)->handle($family, $who, $type, $head, '2026-09-30', null);
                $this->fail('Expected refusal.');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(1, FamilyMembership::count());
    }

    public function test_a_missing_or_inactive_relationship_type_is_refused_before_writing(): void
    {
        $family = $this->family();
        RelationshipType::where('code', 'SPOUSE')->update(['is_active' => false]);

        $this->expectException(MissingRelationshipTypeException::class);
        try {
            app(CreateFamilyMembershipAction::class)->handle($family, $this->person('920000021'), 'SPOUSE', false, '2026-09-30', null);
        } finally {
            $this->assertSame(0, FamilyMembership::count());
        }
    }

    public function test_the_family_must_belong_to_the_expected_clan(): void
    {
        $family = $this->family();
        $other = Clan::create(['code' => 'SYN_OTHER', 'name' => 'أخرى']);

        $this->expectException(ValidationException::class);
        app(CreateFamilyMembershipAction::class)->handle($family, $this->person('920000031'), 'HEAD', true, '2026-09-30', null, $other->id);
    }

    public function test_registration_behaves_exactly_as_before_through_the_extracted_actions(): void
    {
        $family = app(RegisterFamilyAction::class)->handle([
            'registration_date' => '2026-09-01', 'registration_source' => 'MANUAL_ENTRY', 'clan_code' => 'AL_BREEM',
            'household_head' => ['full_name' => 'رب أسرة', 'gender' => 'MALE'],
            'residence' => ['city' => 'مدينة', 'displacement_status' => 'NOT_DISPLACED', 'displacement_location_text' => 'تُهمَل', 'original_residence_text' => 'أصلية'],
        ], $this->admin->id);

        $head = $family->householdHeadMembership;
        $this->assertSame([RelationshipType::where('code', 'HEAD')->value('id'), '2026-09-01', true], [$head->relationship_type_id, $head->started_at->toDateString(), $head->is_active]);
        $res = $family->currentResidence;
        $this->assertSame(['مدينة', 'أصلية', null, null, '2026-09-01', true], [$res->city, $res->original_residence_text, $res->displacement_location_text, $res->source, $res->started_at->toDateString(), $res->is_current]);
        $this->assertSame([FamilyActivityType::FAMILY_CREATED], FamilyActivity::where('family_id', $family->id)->pluck('event_type')->all());
    }

    // ================================================== residence action

    public function test_the_import_form_of_a_residence_holds_the_original_city_only(): void
    {
        $family = $this->family();
        $res = app(CreateFamilyResidenceAction::class)->handle($family, ['original_residence_text' => 'مدينة أصلية', 'source' => 'IMPORT'], '2026-09-30', $this->admin->id);

        $this->assertSame(['مدينة أصلية', 'IMPORT', '2026-09-30', true], [$res->original_residence_text, $res->source, $res->started_at->toDateString(), $res->is_current]);
        foreach (['governorate', 'city', 'area', 'neighborhood', 'address_text', 'latitude', 'longitude', 'displacement_status', 'displacement_location_text', 'residence_type'] as $field) {
            $this->assertNull($res->fresh()->{$field}, $field);
        }
        // A second current residence for the same Family is refused…
        try {
            app(CreateFamilyResidenceAction::class)->handle($family, ['original_residence_text' => 'أخرى'], '2026-09-30', null);
            $this->fail('Expected refusal of a second current residence.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('residence', $e->errors());
        }
        // …and so is an unknown source.
        try {
            app(CreateFamilyResidenceAction::class)->handle($this->family(), ['source' => 'GUESS'], '2026-09-30', null);
            $this->fail('Expected refusal of an unknown source.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('source', $e->errors());
        }
        $this->assertSame(1, FamilyResidence::where('family_id', $family->id)->count());
    }

    // ================================================== planner: as of Apply start

    public function test_the_normal_dry_run_plan_is_unchanged_by_the_context(): void
    {
        $batch = $this->batch([2 => $this->row(['id' => 920000101], [[920000102, 'زوجة']])]);
        $planner = app(ImportApplyPlanner::class);

        $default = $planner->plan($batch);
        $this->assertTrue($default->executable());
        $this->assertSame($default->fingerprint(), $planner->plan($batch, ImportApplyPlanningContext::current())->fingerprint());
        // As-of-start planning is refused before Apply starts.
        $this->assertSame(['APPLY_NOT_STARTED'], array_column($planner->plan($batch, ImportApplyPlanningContext::asOfApplyStart($batch))->preconditionFailures, 'code'));
        $this->assertFalse($planner->matchesApprovedPlan($batch));
    }

    public function test_as_of_start_reconstructs_the_approved_plan_and_catches_decision_changes(): void
    {
        $existing = Person::factory()->create(['national_id' => '920000201', 'gender' => 'MALE', 'birth_date' => '1980-01-15']);
        $batch = $this->batch([
            2 => $this->row(['id' => 920000201], [[920000202, 'زوجة']]),
            3 => $this->row(['id' => 920000203, 'key' => 'مفتاح ب']),
        ]);
        $planner = app(ImportApplyPlanner::class);
        $approved = $planner->plan($batch);
        $this->assertSame(['REUSE', 'CREATE'], [$approved->row(2)->intentOf(E::HEAD_PERSON)->value, $approved->row(3)->intentOf(E::HEAD_PERSON)->value]);

        // A future Apply started and executed both heads (minimum fixture).
        $this->start($batch, $approved->fingerprint());
        $this->simulateAppliedHead($batch, 2, $existing);
        $this->simulateAppliedHead($batch, 3);

        // Normal planning now differs: the reused head is linked and the
        // created head exists.
        $normal = new ImportBatchApplyPlan($batch->id, $batch->reconciliation_fingerprint, [], $planner->planRows($batch->fresh()));
        $this->assertNotSame($approved->fingerprint(), $normal->fingerprint());
        $this->assertSame(['HEAD_HAS_ACTIVE_MEMBERSHIP', 'HEAD_HAS_ACTIVE_MEMBERSHIP'], [$normal->row(2)->effect(E::HEAD_PERSON)->reason, $normal->row(3)->effect(E::HEAD_PERSON)->reason]);

        // As of Apply start, this batch's own effects are expected changes.
        $reconstructed = $this->asOfStart($batch);
        $this->assertSame([], $reconstructed->preconditionFailures);
        $this->assertSame($approved->fingerprint(), $reconstructed->fingerprint());
        $this->assertSame(['REUSE', $existing->id], [$reconstructed->row(2)->intentOf(E::HEAD_PERSON)->value, $reconstructed->row(2)->effect(E::HEAD_PERSON)->existingId]);
        $this->assertSame('CREATE', $reconstructed->row(3)->intentOf(E::HEAD_PERSON)->value);
        $this->assertTrue($planner->matchesApprovedPlan($batch->fresh()));

        // An external Person with the wife's exact ID changes a decision
        // (CREATE → REUSE): the fingerprint moves and resume must stop.
        Person::factory()->create(['national_id' => '920000202', 'gender' => 'FEMALE']);
        $this->assertSame('REUSE', $this->asOfStart($batch)->row(2)->intentOf(E::SPOUSE_1_PERSON)->value);
        $this->assertNotSame($approved->fingerprint(), $this->asOfStart($batch)->fingerprint());
        $this->assertFalse($planner->matchesApprovedPlan($batch->fresh()));
    }

    public function test_an_external_change_to_a_batch_decision_input_is_not_masked(): void
    {
        $batch = $this->batch([2 => $this->row(['id' => 920000301])]);
        $planner = app(ImportApplyPlanner::class);
        $approved = $planner->plan($batch);
        $this->start($batch, $approved->fingerprint());
        $this->simulateAppliedHead($batch, 2);
        $this->assertTrue($planner->matchesApprovedPlan($batch->fresh()));

        // A second, external Person with the same exact ID is NOT this
        // batch's own change: the approved CREATE no longer holds (a REUSE of
        // an unapproved Person, or a block when its identity differs) — the
        // plan moves and resume stops.
        Person::factory()->create(['national_id' => '920000301', 'gender' => 'FEMALE']);
        $head = $this->asOfStart($batch)->row(2)->effect(E::HEAD_PERSON);
        $this->assertSame(['BLOCK', 'HEAD_IDENTITY_MISMATCH'], [$head->intent->value, $head->reason]);
        $this->assertFalse($planner->matchesApprovedPlan($batch->fresh()));
    }

    #[DataProvider('externalPreconditionChanges')]
    public function test_branch_and_relationship_type_changes_block_resume(string $change, string $code): void
    {
        $branch = Branch::create(['clan_id' => $this->clan->id, 'code' => 'SYN_BR', 'name' => 'فرع']);
        $batch = $this->batch([2 => $this->row(['id' => 920000401])], ['مفتاح أ' => ['decision' => 'MATCH_EXISTING_BRANCH', 'branch_id' => $branch->uuid]]);
        $planner = app(ImportApplyPlanner::class);
        $this->start($batch, $planner->plan($batch)->fingerprint());
        $this->assertTrue($planner->matchesApprovedPlan($batch->fresh()));

        $change === 'branch'
            ? $branch->update(['is_active' => false])
            : RelationshipType::where('code', $change)->update(['is_active' => false]);

        $this->assertContains($code, array_column($this->asOfStart($batch)->preconditionFailures, 'code'));
        $this->assertFalse($planner->matchesApprovedPlan($batch->fresh()));
    }

    public static function externalPreconditionChanges(): array
    {
        return [
            'branch deactivated' => ['branch', 'BRANCH_NOT_SELECTABLE'],
            'HEAD type deactivated' => ['HEAD', 'RELATIONSHIP_TYPE_HEAD_UNAVAILABLE'],
            'SPOUSE type deactivated' => ['SPOUSE', 'RELATIONSHIP_TYPE_SPOUSE_UNAVAILABLE'],
        ];
    }

    public function test_as_of_start_planning_writes_nothing_and_reserves_no_identifier(): void
    {
        $batch = $this->batch([2 => $this->row(['id' => 920000501])]);
        $planner = app(ImportApplyPlanner::class);
        $this->start($batch, $planner->plan($batch)->fingerprint());
        $this->simulateAppliedHead($batch, 2);

        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = strtolower(ltrim($q->sql));
        });
        $planner->matchesApprovedPlan($batch->fresh());
        $this->assertSame([], array_values(array_filter($sql, fn ($s) => preg_match('/^(insert|update|delete)\b/', $s) || str_contains($s, 'nextval'))));
    }

    // ================================================== FAMILY_IMPORTED

    public function test_family_imported_accepts_only_safe_import_identifiers(): void
    {
        $family = $this->family();
        $activity = DB::transaction(fn () => FamilyActivityLog::record($family->id, FamilyActivityType::FAMILY_IMPORTED, $family, $this->admin->id, ['import_batch_id' => 21, 'source_row_number' => 772]));
        $this->assertSame(['import_batch_id' => 21, 'source_row_number' => 772], $activity->fresh()->metadata);

        foreach ([
            [FamilyActivityType::FAMILY_IMPORTED, ['national_id' => '920000601']],
            [FamilyActivityType::FAMILY_IMPORTED, ['full_name' => 'اسم']],
            [FamilyActivityType::FAMILY_IMPORTED, ['health_record_type' => 'DISABILITY']],
            [FamilyActivityType::FAMILY_IMPORTED, ['import_batch_id' => '920000601']],
            [FamilyActivityType::FAMILY_IMPORTED, ['source_row_number' => 0]],
            [FamilyActivityType::FAMILY_CREATED, ['import_batch_id' => 21]],
        ] as [$type, $metadata]) {
            try {
                DB::transaction(fn () => FamilyActivityLog::record($family->id, $type, $family, null, $metadata));
                $this->fail('Expected refusal: '.json_encode($metadata));
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::FAMILY_IMPORTED)->count());
    }

    public function test_family_imported_is_not_emitted_by_any_flow_yet(): void
    {
        app(RegisterFamilyAction::class)->handle([
            'registration_date' => '2026-09-01', 'registration_source' => 'IMPORT', 'clan_code' => 'AL_BREEM',
            'household_head' => ['full_name' => 'رب أسرة', 'gender' => 'MALE'], 'residence' => ['city' => 'مدينة'],
        ], null);

        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::FAMILY_IMPORTED)->count());
        $this->assertFalse(Schema::hasColumn('family_activities', 'import_batch_id'));
    }

    public function test_a_duplicate_head_membership_is_stopped_by_the_database_too(): void
    {
        $family = $this->family();
        $p = $this->person('920000701');
        app(CreateFamilyMembershipAction::class)->handle($family, $p, 'HEAD', true, '2026-09-30', null);

        $this->expectException(QueryException::class);
        FamilyMembership::create(['family_id' => $family->id, 'person_id' => $this->person('920000702')->id, 'is_household_head' => true, 'is_active' => true]);
    }
}
