<?php

namespace Tests\Feature\Import;

use App\Models\Branch;
use App\Models\Clan;
use App\Models\Family;
use App\Models\FamilyHouseholdDeclaration;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\SyntheticXlsx;
use Tests\TestCase;

/**
 * Record reconciliation (docs/03 §96a): staged rows are compared with the
 * permanent registry WITHOUT changing it. Person and Family matching are
 * separate; National ID (exact) is the only deterministic Person evidence;
 * names, keys, Branches and phones never identify anything. All synthetic.
 */
class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/imports/initial-families';

    private const SELF_ID = '555000111';

    private const RELIGION = 'قيمة-ديانة-اختبارية';

    // A هويتك / B المفتاح / C رقم الهوية / D الاسم / E الميلاد / F الجنس / G الحالة / H الديانة /
    // I المدينة / J حالة الوفاة / K الوفاة / L أفراد / M ذكور / N إناث / O الجوال / P..W wife1..wife4
    private const HEADERS = [
        'هويتك', 'المفتاح', 'رقم الهوية', 'الاسم', 'الميلاد', 'الجنس', 'الحالة الاجتماعية', 'الديانة',
        'المدينة', 'حالة الوفاة', 'الوفاة', 'أفراد الأسرة', 'أبناءذكور احياء', 'أبناءإناث أحياء', 'الجوال',
        'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة',
    ];

    private Clan $clan;

    private Clan $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = $this->user('SUPER_ADMIN');
        $this->clan = Clan::create(['code' => 'SYN_TARGET', 'name' => 'عشيرة الهدف']);
        $this->other = Clan::create(['code' => 'SYN_OTHER', 'name' => 'عشيرة أخرى']);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** A synthetic source row (defaults equal the "existing" household below). */
    private function row(array $o = [], array $wives = []): array
    {
        $cells = [
            self::SELF_ID,
            array_key_exists('key', $o) ? $o['key'] : 'مفتاح أ',
            array_key_exists('id', $o) ? $o['id'] : 900000001,
            array_key_exists('name', $o) ? $o['name'] : 'رب أسرة تجريبي',
            array_key_exists('birth', $o) ? $o['birth'] : new DateTimeImmutable('1980-01-15'),
            $o['gender'] ?? 'ذكر',
            $o['marital'] ?? 'متزوج',
            self::RELIGION,
            $o['city'] ?? 'مدينة أصلية',
            $o['life'] ?? 'حي',
            $o['death'] ?? null,
            $o['size'] ?? 6,
            $o['sons'] ?? 2,
            $o['daughters'] ?? 2,
            array_key_exists('mobile', $o) ? $o['mobile'] : '0590000000',
        ];
        foreach (range(0, 3) as $k) {
            $cells[] = $wives[$k][0] ?? null;
            $cells[] = $wives[$k][1] ?? null;
        }

        return $cells;
    }

    /** Stage → resolve every key (NO_BRANCH unless given) → reconcile. Returns the batch id. */
    private function reconcileRows(array $rows, string $mode = 'INITIAL', array $decisions = []): string
    {
        $id = $this->stageRows($rows, $mode);
        $keys = array_column($this->actingAs($this->admin)->getJson(self::BASE."/{$id}/family-keys")->json('data'), 'key');
        $noBranch = array_values(array_diff($keys, array_keys($decisions)));
        foreach ($decisions as $key => $body) {
            $this->actingAs($this->admin)->putJson(self::BASE."/{$id}/family-keys/resolution", ['source_family_key' => $key, ...$body])->assertOk();
        }
        if ($noBranch !== []) {
            $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/family-keys/bulk", [
                'decision' => 'NO_BRANCH', 'items' => array_map(fn ($k) => ['source_family_key' => $k], $noBranch),
            ])->assertOk();
        }
        $this->reconcile($id)->assertOk();

        return $id;
    }

    private function stageRows(array $rows, string $mode = 'INITIAL'): string
    {
        $path = SyntheticXlsx::write(['Sheet1' => [1 => self::HEADERS] + $rows]);
        $id = $this->actingAs($this->admin)->post(self::BASE, [
            'clan_code' => 'SYN_TARGET', 'import_mode' => $mode, 'file' => new UploadedFile($path, 'synthetic.xlsx', null, null, true),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $fields = [];
        $ignored = [];
        foreach ($this->actingAs($this->admin)->getJson(self::BASE."/{$id}/columns")->json('data.columns') as $c) {
            $c['suggested_field'] ? $fields[$c['suggested_field']] = $c['letter'] : $ignored[] = $c['letter'];
        }
        $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/mapping", ['mapping' => $fields, 'ignored' => $ignored])->assertOk();

        return $id;
    }

    private function reconcile(string $id): TestResponse
    {
        return $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/reconcile");
    }

    /** Reconciliation row (API) by Excel row number. */
    private function at(string $id, int $rowNumber): array
    {
        $rows = $this->actingAs($this->admin)->getJson(self::BASE."/{$id}/reconciliation")->assertOk()->json('data');

        return collect($rows)->firstWhere('row_number', $rowNumber);
    }

    private function codes(array $row): array
    {
        return array_values(array_unique(array_column($row['issues'], 'code')));
    }

    private function summary(string $id): array
    {
        return $this->actingAs($this->admin)->getJson(self::BASE."/{$id}")->assertOk()->json('data.summary.reconciliation');
    }

    /** An existing registry household in the target Clan (matches the default row). */
    private function household(string $nationalId = '900000001', array $person = [], ?Clan $clan = null, bool $head = true): array
    {
        $p = Person::factory()->create([
            'national_id' => $nationalId, 'full_name' => 'رب أسرة تجريبي', 'gender' => 'MALE', 'birth_date' => '1980-01-15',
            'marital_status' => 'MARRIED', 'mobile' => '0590000000', 'life_status' => 'ALIVE', 'death_date' => null, ...$person,
        ]);
        $f = Family::factory()->create(['clan_id' => ($clan ?? $this->clan)->id, 'branch_id' => null]);
        if (! $head) {
            FamilyMembership::factory()->householdHead()->create(['family_id' => $f->id, 'person_id' => Person::factory()->create()->id]);
        }
        FamilyMembership::factory()->create(['family_id' => $f->id, 'person_id' => $p->id, 'is_household_head' => $head]);
        FamilyResidence::factory()->create(['family_id' => $f->id, 'is_current' => true, 'original_residence_text' => 'مدينة أصلية']);
        FamilyHouseholdDeclaration::create(['family_id' => $f->id, 'declared_household_size' => 6, 'declared_living_sons' => 2, 'declared_living_daughters' => 2, 'source' => 'IMPORT', 'is_current' => true]);

        return [$p, $f];
    }

    /** Full snapshot of every permanent table reconciliation must never touch. */
    private function registry(): array
    {
        $all = fn (string $t) => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return [$all('persons'), $all('families'), $all('family_memberships'), $all('family_residences'), $all('family_household_declarations'), $all('branches'), $all('branch_groups')];
    }

    // ================================================== Person / Family matching

    public function test_1_new_head_id_without_family_is_new(): void
    {
        $id = $this->reconcileRows([2 => $this->row(['id' => 900000055])]);
        $row = $this->at($id, 2);

        $this->assertSame(['NEW', 'NO_EXISTING_PERSON', 'NO_EXISTING_FAMILY'], [$row['status'], $row['head_match'], $row['family_match']]);
        $this->assertNull($row['person_code']);
    }

    public function test_2_existing_person_without_family_is_a_new_family_with_an_existing_person(): void
    {
        $person = Person::factory()->create(['national_id' => '900000001', 'full_name' => 'رب أسرة تجريبي', 'gender' => 'MALE', 'birth_date' => '1980-01-15']);
        $id = $this->reconcileRows([2 => $this->row()]);
        $row = $this->at($id, 2);

        $this->assertSame(['NEW', 'EXISTING_PERSON', 'NO_EXISTING_FAMILY'], [$row['status'], $row['head_match'], $row['family_match']]);
        $this->assertSame($person->person_code, $row['person_code']);
        $this->assertContains('EXISTING_PERSON_NO_FAMILY', $this->codes($row));
        $this->assertSame(1, $this->summary($id)['stats']['head_existing_person_no_family']);
    }

    public function test_3_deterministic_family_without_differences_is_unchanged(): void
    {
        [, $family] = $this->household();
        $id = $this->reconcileRows([2 => $this->row()]);
        $row = $this->at($id, 2);

        $this->assertSame(['UNCHANGED', 'EXISTING_FAMILY', $family->family_code], [$row['status'], $row['family_match'], $row['family_code']]);
        $this->assertSame([], $row['differences']);
    }

    public function test_4_deterministic_family_with_differences_is_changed_and_nothing_is_overwritten(): void
    {
        [$person] = $this->household();
        $before = $this->registry();
        $id = $this->reconcileRows([2 => $this->row(['mobile' => '0591234567', 'city' => 'مدينة أخرى', 'size' => 7])]);
        $row = $this->at($id, 2);

        $this->assertSame('CHANGED', $row['status']);
        $diffs = collect($row['differences'])->keyBy('field');
        $this->assertSame(['registry' => '0590000000', 'source' => '0591234567'], ['registry' => $diffs['mobile']['registry'], 'source' => $diffs['mobile']['source']]);
        $this->assertSame('مدينة أخرى', $diffs['original_residence_text']['source']);
        $this->assertSame([6, 7], [$diffs['declared_household_size']['registry'], $diffs['declared_household_size']['source']]);
        $this->assertSame($before, $this->registry());
        $this->assertSame('0590000000', $person->fresh()->mobile);
    }

    public function test_16_conflicting_deterministic_identity_is_conflict(): void
    {
        // The head's Person is a non-head member of an existing Family.
        $this->household('900000001', head: false);
        // Another head's Person heads a Family of ANOTHER Clan.
        $this->household('900000002', clan: $this->other);
        $id = $this->reconcileRows([2 => $this->row(), 3 => $this->row(['id' => 900000002])]);

        $this->assertSame(['CONFLICT', 'PERSON_NOT_HEAD'], [$this->at($id, 2)['status'], $this->at($id, 2)['family_match']]);
        $this->assertContains('EXISTING_PERSON_NOT_HEAD', $this->codes($this->at($id, 2)));
        $this->assertSame(['CONFLICT', 'OTHER_CLAN_FAMILY'], [$this->at($id, 3)['status'], $this->at($id, 3)['family_match']]);
    }

    public function test_17_ambiguous_evidence_requires_review(): void
    {
        // Existing Person with a different birth date (material identity mismatch).
        Person::factory()->create(['national_id' => '900000001', 'full_name' => 'رب أسرة تجريبي', 'gender' => 'MALE', 'birth_date' => '1975-05-05']);
        // Registry holds the ID only in another format (digits with separators).
        Person::factory()->create(['national_id' => '9000-00002', 'full_name' => 'x']);
        $id = $this->reconcileRows([2 => $this->row(), 3 => $this->row(['id' => 900000002])]);

        $this->assertSame('REVIEW_REQUIRED', $this->at($id, 2)['status']);
        $this->assertContains('EXISTING_PERSON_IDENTITY_MISMATCH', $this->codes($this->at($id, 2)));
        $this->assertSame(['REVIEW_REQUIRED', 'FORMAT_VARIANT'], [$this->at($id, 3)['status'], $this->at($id, 3)['head_match']]);
    }

    // ================================================== duplicates in file

    public function test_5_6_duplicate_head_ids_are_flagged_and_never_collapsed(): void
    {
        $id = $this->reconcileRows([
            2 => $this->row(['id' => 900000010, 'name' => 'اسم أول']),
            3 => $this->row(['id' => 900000011]),
            4 => $this->row(['id' => 900000010, 'name' => 'اسم ثان']),
        ]);

        foreach ([2, 4] as $n) {
            $row = $this->at($id, $n);
            $this->assertSame('DUPLICATE_IN_FILE', $row['status']);
            $dup = collect($row['issues'])->firstWhere('code', 'DUPLICATE_HEAD_ID_IN_FILE');
            $this->assertSame([2, 4], $dup['context']['rows']);
            $this->assertStringNotContainsString('900000010', json_encode($row));   // masked
        }
        $this->assertSame('NEW', $this->at($id, 3)['status']);
        $this->assertSame(3, ImportRow::count());
        $this->assertSame(2, $this->summary($id)['counts']['DUPLICATE_IN_FILE']);
    }

    public function test_15_head_and_spouse_cross_role_collision_is_surfaced(): void
    {
        $id = $this->reconcileRows([
            2 => $this->row(['id' => 900000020]),
            3 => $this->row(['id' => 900000021], [[900000020, 'زوجة']]),   // row 2's head as a wife
            4 => $this->row(['id' => 900000022], [[900000022, 'زوجة']]),   // same ID as head and wife in one row
        ]);

        foreach ([2, 3] as $n) {
            $this->assertSame('REVIEW_REQUIRED', $this->at($id, $n)['status']);
            $issue = collect($this->at($id, $n)['issues'])->firstWhere('code', 'HEAD_ALSO_SPOUSE_IN_FILE');
            $this->assertSame([[2], [3]], [$issue['context']['head_rows'], $issue['context']['spouse_rows']]);
        }
        $this->assertSame('CONFLICT', $this->at($id, 4)['status']);
        $this->assertContains('HEAD_ID_EQUALS_SPOUSE_ID', $this->codes($this->at($id, 4)));
        $this->assertSame(2, $this->summary($id)['stats']['cross_role_collision_rows']);
    }

    // ================================================== no-ID / weak evidence

    public function test_7_8_no_id_and_same_name_are_never_matched(): void
    {
        [$person] = $this->household('900000001');
        $id = $this->reconcileRows([
            2 => $this->row(['id' => null]),                                    // same name as the registry head, no ID
            3 => $this->row(['id' => 900000077]),                               // same name, different ID
            4 => $this->row(['id' => null, 'name' => 'اسم فريد بلا هوية']),      // no ID, no ambiguity
        ]);

        $two = $this->at($id, 2);
        $this->assertSame(['REVIEW_REQUIRED', 'NO_NATIONAL_ID', null], [$two['status'], $two['head_match'], $two['person_code']]);
        $this->assertContains('NO_ID_NAME_AMBIGUITY', $this->codes($two));
        $this->assertSame(['NEW', 'NO_EXISTING_PERSON', null], [$this->at($id, 3)['status'], $this->at($id, 3)['head_match'], $this->at($id, 3)['family_code']]);
        $this->assertSame(['NEW', 'NO_NATIONAL_ID'], [$this->at($id, 4)['status'], $this->at($id, 4)['head_match']]);
        $this->assertNotSame($person->person_code, $this->at($id, 3)['person_code']);
    }

    public function test_9_10_11_key_branch_and_phone_never_identify_a_family(): void
    {
        [, $family] = $this->household('900000001');
        $branch = Branch::create(['clan_id' => $this->clan->id, 'code' => 'BR', 'name' => 'فرع']);
        $family->update(['branch_id' => $branch->id]);
        // Same key, same resolved Branch, same phone — different ID.
        $id = $this->reconcileRows([2 => $this->row(['id' => 900000099, 'key' => 'مفتاح أ'])], decisions: [
            'مفتاح أ' => ['decision' => 'MATCH_EXISTING_BRANCH', 'branch_id' => $branch->uuid],
        ]);

        $row = $this->at($id, 2);
        $this->assertSame(['NEW', 'NO_EXISTING_FAMILY', null], [$row['status'], $row['family_match'], $row['family_code']]);
    }

    // ================================================== spouses

    public function test_14_spouse_ids_are_matched_separately(): void
    {
        $wife = Person::factory()->create(['national_id' => '900000031']);
        [, $otherFamily] = $this->household('900000032');
        $id = $this->reconcileRows([2 => $this->row(['id' => 900000030], [[900000031, 'زوجة 1'], [900000032, 'زوجة 2']])]);
        $row = $this->at($id, 2);

        $this->assertSame([['slot' => 1, 'status' => 'EXISTING_PERSON'], ['slot' => 2, 'status' => 'EXISTING_PERSON']], $row['spouse_matches']);
        // The head stays NEW; a spouse heading another Family needs review.
        $this->assertSame('NO_EXISTING_PERSON', $row['head_match']);
        $this->assertSame('REVIEW_REQUIRED', $row['status']);
        $this->assertContains('SPOUSE_IN_OTHER_FAMILY', $this->codes($row));
        $this->assertSame(2, $this->summary($id)['stats']['spouse_existing_person_candidates']);
        $this->assertSame(1, Person::where('id', $wife->id)->count());
    }

    // ================================================== key resolution interplay

    public function test_18_19_no_branch_and_missing_branch_do_not_block(): void
    {
        $id = $this->reconcileRows([2 => $this->row(['key' => 'مفتاح أ']), 3 => $this->row(['id' => 900000040, 'key' => null])]);

        $this->assertSame('NEW', $this->at($id, 2)['status']);
        $this->assertSame('NEW', $this->at($id, 3)['status']);
    }

    public function test_reconciliation_requires_complete_key_resolution(): void
    {
        $id = $this->stageRows([2 => $this->row()]);
        $this->reconcile($id)->assertUnprocessable()->assertJsonValidationErrors('batch');
        $this->assertSame('NOT_RUN', $this->summary($id)['state']);
    }

    // ================================================== no mutation / idempotency / staleness

    public function test_20_to_24_36_source_and_registry_untouched_and_rerun_is_idempotent(): void
    {
        $this->household('900000001');
        Person::factory()->create(['national_id' => '900000031']);
        $id = $this->stageRows([2 => $this->row(), 3 => $this->row(['id' => 900000002], [[900000031, 'زوجة']])]);
        $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/family-keys/bulk", ['decision' => 'NO_BRANCH', 'items' => [['source_family_key' => 'مفتاح أ']]])->assertOk();
        $source = fn () => ImportRow::orderBy('row_number')->get(['raw_payload', 'normalized_payload', 'source_family_key', 'status', 'issues'])->toArray();
        [$sourceBefore, $registryBefore] = [$source(), $this->registry()];

        $this->reconcile($id)->assertOk();
        $first = [$this->actingAs($this->admin)->getJson(self::BASE."/{$id}/reconciliation")->json('data'), DB::table('import_row_reconciliations')->count()];
        $this->reconcile($id)->assertOk();
        $second = [$this->actingAs($this->admin)->getJson(self::BASE."/{$id}/reconciliation")->json('data'), DB::table('import_row_reconciliations')->count()];

        $this->assertSame($first, $second);
        $this->assertSame(2, $second[1]);
        $this->assertSame($sourceBefore, $source());
        $this->assertSame($registryBefore, $this->registry());
    }

    public function test_25_26_27_stale_results_after_registry_staging_or_key_changes(): void
    {
        $id = $this->reconcileRows([2 => $this->row()]);
        $this->assertSame('CURRENT', $this->summary($id)['state']);

        // Registry change → STALE; re-running reflects it.
        $this->household('900000001');
        $this->assertSame('STALE', $this->summary($id)['state']);
        $this->reconcile($id)->assertOk();
        $this->assertSame(['CURRENT', 'UNCHANGED'], [$this->summary($id)['state'], $this->at($id, 2)['status']]);

        // Key decision change → STALE.
        $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/family-keys/resolution/clear", ['source_family_key' => 'مفتاح أ'])->assertOk();
        $this->assertSame('STALE', $this->summary($id)['state']);
        $this->reconcile($id)->assertUnprocessable();   // keys must be resolved first
        $this->actingAs($this->admin)->putJson(self::BASE."/{$id}/family-keys/resolution", ['source_family_key' => 'مفتاح أ', 'decision' => 'NO_BRANCH'])->assertOk();
        $this->reconcile($id)->assertOk();

        // Re-staging (mapping re-confirmed) → results discarded.
        $fields = [];
        $ignored = [];
        foreach ($this->actingAs($this->admin)->getJson(self::BASE."/{$id}/columns")->json('data.columns') as $c) {
            $c['suggested_field'] ? $fields[$c['suggested_field']] = $c['letter'] : $ignored[] = $c['letter'];
        }
        $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/mapping", ['mapping' => $fields, 'ignored' => $ignored])->assertOk();
        $this->assertSame('NOT_RUN', $this->summary($id)['state']);
        $this->assertSame(0, DB::table('import_row_reconciliations')->count());
        $this->assertNull(ImportRow::sole()->reconciliation_status);
    }

    // ================================================== modes, absence, history

    public function test_28_29_30_31_incremental_is_non_destructive_and_batches_are_independent(): void
    {
        [$absentPerson, $absentFamily] = $this->household('900000088');
        $first = $this->reconcileRows([2 => $this->row()]);
        $registryBefore = $this->registry();
        $later = $this->reconcileRows([2 => $this->row(), 3 => $this->row(['id' => 900000003])], 'INCREMENTAL');

        // The absent household is untouched and not proposed for anything.
        $this->assertSame($registryBefore, $this->registry());
        $this->assertTrue($absentPerson->fresh()->is_active);
        $this->assertNull($absentFamily->fresh()->deleted_at);
        // Same engine; each batch has its own results.
        $this->assertSame('INITIAL', ImportBatch::where('uuid', $first)->value('import_mode')->value);
        $this->assertSame('INCREMENTAL', ImportBatch::where('uuid', $later)->value('import_mode')->value);
        $this->assertSame(['NEW', 'NEW'], [$this->at($later, 2)['status'], $this->at($later, 3)['status']]);
        $this->assertSame(1, DB::table('import_row_reconciliations')->where('import_batch_id', ImportBatch::where('uuid', $first)->value('id'))->count());
        $this->assertSame(2, DB::table('import_row_reconciliations')->where('import_batch_id', ImportBatch::where('uuid', $later)->value('id'))->count());
    }

    public function test_life_status_vocabulary_and_inconsistency(): void
    {
        $id = $this->reconcileRows([
            2 => $this->row(['id' => 900000050, 'life' => 'متوفى']),
            3 => $this->row(['id' => 900000051, 'life' => 'متوفي', 'death' => new DateTimeImmutable('2024-01-01')]),
            4 => $this->row(['id' => 900000052, 'life' => 'حي', 'death' => new DateTimeImmutable('2024-01-01')]),
            5 => $this->row(['id' => 900000053, 'gender' => 'غير ذلك']),
        ]);

        $this->assertSame(['NEW', 'NEW'], [$this->at($id, 2)['status'], $this->at($id, 3)['status']]);
        $this->assertContains('LIFE_STATUS_INCONSISTENT', $this->codes($this->at($id, 4)));
        $this->assertSame('REVIEW_REQUIRED', $this->at($id, 5)['status']);
        $this->assertContains('UNMAPPED_SOURCE_VALUE', $this->codes($this->at($id, 5)));
    }

    // ================================================== privacy / authorization / no Apply

    public function test_12_13_32_33_forbidden_fields_never_participate_and_ids_are_masked(): void
    {
        // A registry Person carrying the هويتك value must never be matched through it.
        Person::factory()->create(['national_id' => self::SELF_ID]);
        $id = $this->reconcileRows([2 => $this->row(['id' => 900000060])]);

        $row = $this->at($id, 2);
        $this->assertSame('NO_EXISTING_PERSON', $row['head_match']);
        $stored = json_encode(DB::table('import_row_reconciliations')->get()->all(), JSON_UNESCAPED_UNICODE);
        $api = json_encode([$row, $this->summary($id)], JSON_UNESCAPED_UNICODE);
        foreach ([self::SELF_ID, self::RELIGION, 'هويتك', 'الديانة'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $stored);
            $this->assertStringNotContainsString($forbidden, $api);
        }
        $this->assertStringNotContainsString('900000060', $api);
        $this->assertSame('*****0060', $row['national_id_masked']);
    }

    // ================================================== repeated wife (widow remarriage)

    public function test_repeated_wife_after_a_deceased_head_does_not_force_review(): void
    {
        $before = $this->registry();
        $id = $this->reconcileRows([
            2 => $this->row(['id' => 900000101, 'life' => 'متوفى'], [[900000999, 'الزوجة س']]),
            3 => $this->row(['id' => 900000102], [[900000999, 'الزوجة س']]),
        ]);

        foreach ([2, 3] as $n) {
            $row = $this->at($id, $n);
            $this->assertSame('NEW', $row['status'], "row {$n}");
            $note = collect($row['issues'])->firstWhere('code', 'SPOUSE_REPEATED_AFTER_HEAD_DEATH');
            $this->assertSame([2, 3], $note['context']['rows']);
            $this->assertSame([1, 1, 0], [$note['context']['alive'], $note['context']['deceased'], $note['context']['unknown']]);
        }
        // Still ONE identity candidate: the same exact ID in both rows, nothing created.
        $this->assertSame($before, $this->registry());
    }

    public function test_both_death_spellings_map_to_deceased_and_row_order_is_not_chronology(): void
    {
        // The deceased head is the LATER row here; the verdict is the same.
        $id = $this->reconcileRows([
            2 => $this->row(['id' => 900000111], [[900000998, 'الزوجة ص']]),
            3 => $this->row(['id' => 900000112, 'life' => 'متوفي'], [[900000998, 'الزوجة ص']]),
            4 => $this->row(['id' => 900000113, 'life' => 'متوفى'], [[900000997, 'الزوجة ع']]),
            5 => $this->row(['id' => 900000114], [[900000997, 'الزوجة ع']]),
        ]);

        foreach ([2, 3, 4, 5] as $n) {
            $this->assertSame('NEW', $this->at($id, $n)['status'], "row {$n}");
            $this->assertContains('SPOUSE_REPEATED_AFTER_HEAD_DEATH', $this->codes($this->at($id, $n)));
        }
    }

    public function test_repeated_wife_with_two_living_heads_requires_review(): void
    {
        $id = $this->reconcileRows([
            2 => $this->row(['id' => 900000121], [[900000996, 'الزوجة ق']]),
            3 => $this->row(['id' => 900000122], [[900000996, 'الزوجة ق']]),
        ]);

        foreach ([2, 3] as $n) {
            $this->assertSame('REVIEW_REQUIRED', $this->at($id, $n)['status']);
            $this->assertContains('SPOUSE_SHARED_BY_LIVING_HEADS', $this->codes($this->at($id, $n)));
        }
    }

    public function test_repeated_wife_with_an_unknown_head_status_requires_review(): void
    {
        $id = $this->reconcileRows([
            2 => $this->row(['id' => 900000131, 'life' => 'غير معروف'], [[900000995, 'الزوجة ر']]),
            3 => $this->row(['id' => 900000132], [[900000995, 'الزوجة ر']]),
        ]);

        foreach ([2, 3] as $n) {
            $this->assertSame('REVIEW_REQUIRED', $this->at($id, $n)['status']);
            $this->assertContains('SPOUSE_REPEATED_HEAD_STATUS_UNKNOWN', $this->codes($this->at($id, $n)));
            // Death is never assumed from the repetition.
            $this->assertNotContains('SPOUSE_REPEATED_AFTER_HEAD_DEATH', $this->codes($this->at($id, $n)));
        }
    }

    public function test_three_households_with_two_living_heads_all_require_review(): void
    {
        $id = $this->reconcileRows([
            2 => $this->row(['id' => 900000141, 'life' => 'متوفى'], [[900000994, 'الزوجة ش']]),
            3 => $this->row(['id' => 900000142], [[900000994, 'الزوجة ش']]),
            4 => $this->row(['id' => 900000143], [[900000994, 'الزوجة ش']]),
            // Two deceased + one living is acceptable as a whole group.
            5 => $this->row(['id' => 900000144, 'life' => 'متوفى'], [[900000993, 'الزوجة ت']]),
            6 => $this->row(['id' => 900000145, 'life' => 'متوفى'], [[900000993, 'الزوجة ت']]),
            7 => $this->row(['id' => 900000146], [[900000993, 'الزوجة ت']]),
        ]);

        foreach ([2, 3, 4] as $n) {
            $this->assertSame('REVIEW_REQUIRED', $this->at($id, $n)['status'], "row {$n}");
            $issue = collect($this->at($id, $n)['issues'])->firstWhere('code', 'SPOUSE_SHARED_BY_LIVING_HEADS');
            $this->assertSame([2, 3, 4], $issue['context']['rows']);
        }
        foreach ([5, 6, 7] as $n) {
            $this->assertSame('NEW', $this->at($id, $n)['status'], "row {$n}");
        }
    }

    public function test_existing_duplicate_same_row_and_cross_role_rules_are_unchanged(): void
    {
        $id = $this->reconcileRows([
            // Duplicate HEAD (with a deceased head and a shared wife): still DUPLICATE_IN_FILE.
            2 => $this->row(['id' => 900000151, 'life' => 'متوفى'], [[900000992, 'الزوجة ث']]),
            3 => $this->row(['id' => 900000151], [[900000992, 'الزوجة ث']]),
            // Same wife twice in one row: still REVIEW_REQUIRED.
            4 => $this->row(['id' => 900000152], [[900000991, 'الزوجة خ'], [900000991, 'الزوجة خ']]),
            // HEAD ↔ SPOUSE cross-role: still REVIEW_REQUIRED.
            5 => $this->row(['id' => 900000153, 'life' => 'متوفى']),
            6 => $this->row(['id' => 900000154], [[900000153, 'زوجة']]),
        ]);

        $this->assertSame(['DUPLICATE_IN_FILE', 'DUPLICATE_IN_FILE'], [$this->at($id, 2)['status'], $this->at($id, 3)['status']]);
        $this->assertSame('REVIEW_REQUIRED', $this->at($id, 4)['status']);
        $this->assertContains('DUPLICATE_SPOUSE_IN_ROW', $this->codes($this->at($id, 4)));
        foreach ([5, 6] as $n) {
            $this->assertSame('REVIEW_REQUIRED', $this->at($id, $n)['status']);
            $this->assertContains('HEAD_ALSO_SPOUSE_IN_FILE', $this->codes($this->at($id, $n)));
        }
        $this->assertSame(0, Person::count());
        $this->assertSame(0, Family::count());
        $this->assertSame(0, FamilyMembership::count());
    }

    // ================================================== polygamous household

    /** A polygamous man (wives in slot order: null = empty slot). */
    private function husband(int $id, array $wives, array $o = []): array
    {
        return $this->row(['id' => $id, 'marital' => 'متعدد الزوجات', ...$o], array_map(fn ($w) => $w === null ? [null, null] : [$w, 'زوجة'], $wives));
    }

    /** A woman heading her own household row, naming $husband (by ID) as spouse. */
    private function wifeHead(int $id, ?int $husband, string $name = 'ربة أسرة'): array
    {
        return $this->row(['id' => $id, 'gender' => 'أنثى', 'marital' => 'متزوجة', 'name' => $name], $husband ? [[$husband, 'الزوج']] : []);
    }

    private function householdNote(string $id, int $row): ?array
    {
        return collect($this->at($id, $row)['issues'])->firstWhere('code', 'POLYGAMY_INDEPENDENT_WIFE_HOUSEHOLD');
    }

    public function test_polygamy_latest_wife_with_own_reciprocal_household_is_accepted(): void
    {
        $before = $this->registry();
        $id = $this->reconcileRows([
            2 => $this->husband(900000201, [900000211, 900000212]),
            3 => $this->wifeHead(900000212, 900000201),
        ]);

        foreach ([2, 3] as $n) {
            $this->assertSame('NEW', $this->at($id, $n)['status'], "row {$n}");
            $this->assertNotContains('HEAD_ALSO_SPOUSE_IN_FILE', $this->codes($this->at($id, $n)));
            $this->assertNotNull($this->householdNote($id, $n), "row {$n}");
        }
        // Slot evidence only; her exact ID links both rows (one Person candidate).
        $note = $this->householdNote($id, 3);
        $this->assertSame(['*****0212', 2, [3], 2, 2], [$note['context']['national_id'], $note['context']['husband_row'], $note['context']['wife_rows'], $note['context']['slot'], $note['context']['last_slot']]);
        $this->assertSame(0, $this->summary($id)['stats']['cross_role_collision_rows']);
        // Nothing permanent written.
        $this->assertSame($before, $this->registry());
    }

    public function test_polygamy_earlier_wife_with_own_reciprocal_household_is_accepted(): void
    {
        $id = $this->reconcileRows([
            2 => $this->husband(900000221, [900000231, null, 900000233]),
            3 => $this->wifeHead(900000231, 900000221),
        ]);

        $this->assertSame(['NEW', 'NEW'], [$this->at($id, 2)['status'], $this->at($id, 3)['status']]);
        $note = $this->householdNote($id, 3);
        $this->assertSame([1, 3], [$note['context']['slot'], $note['context']['last_slot']]);
    }

    public function test_polygamy_several_wives_heading_households_naming_the_same_husband(): void
    {
        $id = $this->reconcileRows([
            2 => $this->husband(900000241, [900000251, 900000252, 900000253]),
            3 => $this->wifeHead(900000251, 900000241),
            4 => $this->wifeHead(900000252, 900000241),
            5 => $this->wifeHead(900000253, 900000241),
        ]);

        foreach ([2, 3, 4, 5] as $n) {
            $this->assertSame('NEW', $this->at($id, $n)['status'], "row {$n}");
            // Several women naming the linked husband is not a shared spouse.
            $this->assertNotContains('SPOUSE_SHARED_BY_LIVING_HEADS', $this->codes($this->at($id, $n)));
        }
        $this->assertSame([1, 2, 3], array_map(fn ($n) => $this->householdNote($id, $n)['context']['slot'], [3, 4, 5]));
    }

    public function test_polygamy_rule_needs_polygamous_alive_husband_and_reciprocal_exact_ids(): void
    {
        $id = $this->reconcileRows([
            // Ordinary married man.
            2 => $this->row(['id' => 900000261, 'marital' => 'متزوج'], [[900000271, 'زوجة']]),
            3 => $this->wifeHead(900000271, 900000261),
            // Deceased polygamous man: this rule is not used.
            4 => $this->husband(900000262, [900000272, 900000273], ['life' => 'متوفى']),
            5 => $this->wifeHead(900000273, 900000262),
            // Unknown life status.
            6 => $this->husband(900000263, [900000274, 900000275], ['life' => 'غير معروف']),
            7 => $this->wifeHead(900000275, 900000263),
            // Her row does not name him back.
            8 => $this->husband(900000264, [900000276, 900000277]),
            9 => $this->wifeHead(900000277, null),
            // Her row names a different man.
            10 => $this->husband(900000265, [900000278, 900000279]),
            11 => $this->wifeHead(900000279, 900000999),
        ]);

        foreach ([2, 3, 4, 5, 6, 7, 8, 9, 10, 11] as $n) {
            $this->assertSame('REVIEW_REQUIRED', $this->at($id, $n)['status'], "row {$n}");
            $this->assertNull($this->householdNote($id, $n), "row {$n}");
        }
        foreach ([2, 3, 4, 5, 6, 7, 8, 9, 10, 11] as $n) {
            $this->assertContains('HEAD_ALSO_SPOUSE_IN_FILE', $this->codes($this->at($id, $n)), "row {$n}");
        }
    }

    public function test_polygamy_rule_never_links_by_name(): void
    {
        $id = $this->reconcileRows([
            // Her row has the wife's NAME but a different ID; she names his ID back.
            2 => $this->row(['id' => 900000281, 'marital' => 'متعدد الزوجات'], [[900000291, 'اسم مشترك'], [900000292, 'زوجة']]),
            3 => $this->wifeHead(900000293, 900000281, 'اسم مشترك'),
        ]);

        foreach ([2, 3] as $n) {
            $this->assertSame('REVIEW_REQUIRED', $this->at($id, $n)['status'], "row {$n}");
            $this->assertNull($this->householdNote($id, $n));
        }
    }

    public function test_polygamy_link_is_not_accepted_when_either_row_has_another_issue(): void
    {
        $id = $this->reconcileRows([
            // Her head ID is duplicated in the file (duplicate-head rule unchanged).
            2 => $this->husband(900000301, [900000311, 900000312]),
            3 => $this->wifeHead(900000311, 900000301),
            4 => $this->wifeHead(900000311, 900000301),
            // Same wife twice inside the husband's row (same-row rule unchanged).
            5 => $this->husband(900000302, [900000313, 900000313]),
            6 => $this->wifeHead(900000313, 900000302),
            // His other wife is shared with another living man (spouse rule unchanged).
            7 => $this->husband(900000303, [900000315, 900000316]),
            8 => $this->wifeHead(900000316, 900000303),
            9 => $this->row(['id' => 900000304], [[900000315, 'زوجة']]),
        ]);

        $this->assertSame(['DUPLICATE_IN_FILE', 'DUPLICATE_IN_FILE'], [$this->at($id, 3)['status'], $this->at($id, 4)['status']]);
        $this->assertContains('DUPLICATE_SPOUSE_IN_ROW', $this->codes($this->at($id, 5)));
        $this->assertContains('SPOUSE_SHARED_BY_LIVING_HEADS', $this->codes($this->at($id, 7)));
        foreach ([2, 5, 6, 7, 8, 9] as $n) {
            $this->assertSame('REVIEW_REQUIRED', $this->at($id, $n)['status'], "row {$n}");
        }
        foreach ([2, 3, 4, 5, 6, 7, 8] as $n) {
            $this->assertNull($this->householdNote($id, $n), "row {$n}");
        }
    }

    public function test_polygamy_rule_leaves_the_widow_remarriage_rule_unchanged(): void
    {
        $id = $this->reconcileRows([
            2 => $this->row(['id' => 900000321, 'life' => 'متوفى'], [[900000331, 'زوجة']]),
            3 => $this->husband(900000322, [900000332, 900000331]),
            4 => $this->row(['id' => 900000323], [[900000333, 'زوجة']]),
            5 => $this->husband(900000324, [900000334, 900000333]),
        ]);

        $this->assertSame(['NEW', 'NEW'], [$this->at($id, 2)['status'], $this->at($id, 3)['status']]);
        $this->assertContains('SPOUSE_REPEATED_AFTER_HEAD_DEATH', $this->codes($this->at($id, 3)));
        // Two living husbands (one polygamous) still share a wife → review.
        $this->assertSame(['REVIEW_REQUIRED', 'REVIEW_REQUIRED'], [$this->at($id, 4)['status'], $this->at($id, 5)['status']]);
        $this->assertContains('SPOUSE_SHARED_BY_LIVING_HEADS', $this->codes($this->at($id, 5)));
        $this->assertSame(0, Family::count() + FamilyMembership::count());
    }

    public function test_34_35_authorization_and_no_apply(): void
    {
        $id = $this->reconcileRows([2 => $this->row()]);
        foreach (['ADMINISTRATOR', 'DATA_ENTRY', 'REVIEWER'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->postJson(self::BASE."/{$id}/reconcile")->assertForbidden();
            $this->actingAs($user)->getJson(self::BASE."/{$id}/reconciliation")->assertForbidden();
        }
        // The Apply gate is closed by default: even SUPER_ADMIN is refused.
        $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/apply/start", ['plan_fingerprint' => str_repeat('a', 64)])->assertForbidden();
        foreach (Role::all() as $role) {
            $this->assertFalse($role->hasPermissionTo('import.apply'), $role->name);
        }
        $this->assertSame(0, Family::count());
    }
}
