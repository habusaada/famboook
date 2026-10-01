<?php

namespace Tests\Feature\Import;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\Family;
use App\Models\FamilyHouseholdDeclaration;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\RelationshipTypeSeeder;
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
 * Import Wizard (docs/03 §96a): explicit Clan + import mode → workbook
 * inspection → explicit worksheet → confirmed column mapping → staging →
 * read-only review. Staging only — the registry is never written and there
 * is no Apply. INCREMENTAL batches never overwrite, deduplicate or delete
 * registry data. All workbook content is synthetic.
 */
class ImportWizardTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/imports/initial-families';

    // Values placed in the forbidden columns; they must never survive.
    private const SELF_ID = '555000111';

    private const RELIGION = 'قيمة-ديانة-اختبارية';

    // Column letters: A هويتك, B المفتاح, C رقم الهوية, D الاسم, E الميلاد, F الجنس,
    // G الحالة الاجتماعية, H الديانة, I المدينة, J حالة الوفاة, K الوفاة, L أفراد,
    // M ذكور, N إناث, O الجوال, P/Q wife 1, R/S wife 2, T/U wife 3, V/W wife 4.
    private const HEADERS = [
        'هويتك', 'المفتاح', 'رقم الهوية', 'الاسم', 'الميلاد', 'الجنس', 'الحالة الاجتماعية', 'الديانة',
        'المدينة', 'حالة الوفاة', 'الوفاة', 'أفراد الأسرة', 'أبناءذكور احياء', 'أبناءإناث أحياء', 'الجوال',
        'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة',
    ];

    private Clan $target;

    private Clan $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = $this->user('SUPER_ADMIN');

        $this->target = Clan::create(['code' => 'SYN_TARGET', 'name' => 'عشيرة الهدف التجريبية']);
        $this->other = Clan::create(['code' => 'SYN_OTHER', 'name' => 'عشيرة أخرى تجريبية']);
        Branch::create(['clan_id' => $this->target->id, 'branch_group_id' => null, 'code' => 'SAADA', 'name' => 'أبو سعادة']);
        $group = BranchGroup::create(['clan_id' => $this->target->id, 'code' => 'G1']);
        Branch::create(['clan_id' => $this->target->id, 'branch_group_id' => $group->id, 'code' => 'NUSEIRA', 'name' => 'أبو نصيرة']);
        Branch::create(['clan_id' => $this->other->id, 'branch_group_id' => null, 'code' => 'FAJM', 'name' => 'الفجم']);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** One synthetic source row (header order above); $wives = [[id, name], …]. */
    private function row(array $o = [], array $wives = []): array
    {
        $cells = [
            self::SELF_ID,
            array_key_exists('key', $o) ? $o['key'] : 'أبو سعادة',
            array_key_exists('id', $o) ? $o['id'] : 900000001,
            array_key_exists('name', $o) ? $o['name'] : 'رب أسرة تجريبي أبو سعادة',
            $o['birth'] ?? new DateTimeImmutable('1980-01-15'),
            $o['gender'] ?? 'ذكر',
            $o['marital'] ?? 'متزوج',
            self::RELIGION,
            $o['city'] ?? 'مدينة أصلية تجريبية',
            $o['life'] ?? 'حي',
            $o['death'] ?? null,
            $o['size'] ?? 6,
            $o['sons'] ?? 2,
            $o['daughters'] ?? 2,
            $o['mobile'] ?? '0590000000',
        ];
        foreach (range(0, 3) as $k) {
            $cells[] = $wives[$k][0] ?? null;
            $cells[] = $wives[$k][1] ?? null;
        }

        return $cells;
    }

    /** @param array<int, list<mixed>> $rows row number => cells (header row 1 added) */
    private function workbook(array $rows, array $headers = self::HEADERS, string $sheet = 'Sheet1'): string
    {
        return SyntheticXlsx::write([$sheet => [1 => $headers] + $rows]);
    }

    private function file(string $path): UploadedFile
    {
        return new UploadedFile($path, 'synthetic-families.xlsx', null, null, true);
    }

    private function upload(string $path, ?string $clan = 'SYN_TARGET', ?string $mode = 'INITIAL', ?User $as = null): TestResponse
    {
        $payload = ['file' => $this->file($path)];
        if ($clan !== null) {
            $payload['clan_code'] = $clan;
        }
        if ($mode !== null) {
            $payload['import_mode'] = $mode;
        }

        return $this->actingAs($as ?? $this->admin)->post(self::BASE, $payload, ['Accept' => 'application/json']);
    }

    private function columns(string $id): TestResponse
    {
        return $this->actingAs($this->admin)->getJson(self::BASE."/{$id}/columns")->assertOk();
    }

    /** The server's suggestions as a mapping; unsuggested columns ignored. */
    private function suggested(string $id): array
    {
        $fields = [];
        $ignored = [];
        foreach ($this->columns($id)->json('data.columns') as $column) {
            $column['suggested_field'] ? $fields[$column['suggested_field']] = $column['letter'] : $ignored[] = $column['letter'];
        }

        return [$fields, $ignored];
    }

    private function confirm(string $id, array $fields, array $ignored): TestResponse
    {
        return $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/mapping", ['mapping' => $fields, 'ignored' => $ignored]);
    }

    /** Upload + confirm the suggested mapping; returns the batch id. */
    private function stage(string $path, string $clan = 'SYN_TARGET', string $mode = 'INITIAL'): string
    {
        $id = $this->upload($path, $clan, $mode)->assertCreated()->json('data.id');
        [$fields, $ignored] = $this->suggested($id);
        $this->confirm($id, $fields, $ignored)->assertOk();

        return $id;
    }

    private function rowAt(int $rowNumber, ?string $batchId = null): ImportRow
    {
        return ImportRow::query()
            ->when($batchId, fn ($q) => $q->where('import_batch_id', ImportBatch::where('uuid', $batchId)->value('id')))
            ->where('row_number', $rowNumber)->sole();
    }

    private function storedImportText(): string
    {
        return json_encode([DB::table('import_rows')->get()->all(), DB::table('import_batches')->get()->all()], JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, int> */
    private function registryCounts(): array
    {
        return [
            'branches' => Branch::count(), 'branch_groups' => BranchGroup::count(), 'families' => Family::count(),
            'persons' => Person::count(), 'memberships' => FamilyMembership::count(), 'residences' => FamilyResidence::count(),
            'declarations' => FamilyHouseholdDeclaration::count(), 'activities' => DB::table('family_activities')->count(),
        ];
    }

    // =========================================================== Step 1

    public function test_1_2_3_clan_and_import_mode_are_required_and_explicit(): void
    {
        $path = $this->workbook([2 => $this->row()]);

        $this->upload($path, clan: null)->assertUnprocessable()->assertJsonValidationErrors('clan_code');
        $this->upload($path, clan: 'UNKNOWN')->assertUnprocessable()->assertJsonValidationErrors('clan_code');
        $this->upload($path, mode: null)->assertUnprocessable()->assertJsonValidationErrors('import_mode');
        $this->upload($path, mode: 'SYNC')->assertUnprocessable()->assertJsonValidationErrors('import_mode');
        $this->assertSame(0, ImportBatch::count());

        $id = $this->upload($path)->assertCreated()->assertJsonPath('data.clan.code', 'SYN_TARGET')->json('data.id');
        $batch = ImportBatch::where('uuid', $id)->sole();
        $this->assertSame($this->target->id, $batch->clan_id);
        $this->assertNotSame(Clan::where('code', Clan::AL_BREEM)->value('id'), $batch->clan_id);
    }

    public function test_4_5_initial_and_incremental_modes_are_persisted(): void
    {
        $this->upload($this->workbook([2 => $this->row()]), mode: 'INITIAL')->assertCreated()->assertJsonPath('data.import_mode', 'INITIAL');
        $this->upload($this->workbook([2 => $this->row(['id' => 900000002])]), mode: 'INCREMENTAL')->assertCreated()->assertJsonPath('data.import_mode', 'INCREMENTAL');

        $this->assertSame(['INITIAL', 'INCREMENTAL'], ImportBatch::orderBy('id')->get()->map(fn ($b) => $b->import_mode->value)->all());
    }

    public function test_6_to_9_a_clan_can_be_created_from_the_wizard_and_nothing_else(): void
    {
        $before = [Branch::count(), BranchGroup::count(), Family::count()];

        $this->actingAs($this->admin)->postJson('/api/v1/clans', ['code' => 'WIZARD_CLAN', 'name' => 'عشيرة من المعالج'])
            ->assertCreated()->assertJsonPath('data.is_active', true);
        $this->actingAs($this->admin)->postJson('/api/v1/clans', ['code' => 'WIZARD_OFF', 'name' => 'غير نشطة', 'is_active' => false])
            ->assertCreated()->assertJsonPath('data.is_active', false);

        // Only Clans: no Branch, Branch Group or Family.
        $this->assertSame($before, [Branch::count(), BranchGroup::count(), Family::count()]);

        // The new Clan is immediately usable; an inactive one is not.
        $this->upload($this->workbook([2 => $this->row()]), clan: 'WIZARD_CLAN')->assertCreated();
        $this->upload($this->workbook([2 => $this->row(['id' => 900000003])]), clan: 'WIZARD_OFF')->assertUnprocessable()->assertJsonValidationErrors('clan_code');

        foreach (['DATA_ENTRY', 'SOCIAL_WORKER', 'REVIEWER'] as $role) {
            $this->actingAs($this->user($role))->postJson('/api/v1/clans', ['code' => "NO_{$role}", 'name' => 'x'])->assertForbidden();
        }
        $this->assertFalse(Clan::where('code', 'like', 'NO_%')->exists());
    }

    // =========================================================== Step 2

    public function test_11_12_14_the_workbook_is_inspected_but_not_staged(): void
    {
        $response = $this->upload($this->workbook([2 => $this->row(), 3 => $this->row(['id' => 900000002])]))
            ->assertCreated()
            ->assertJsonPath('data.status', ImportBatchStatus::UPLOADED->value)
            ->assertJsonPath('data.row_count', 0)
            ->assertJsonPath('data.staged', false)
            ->assertJsonPath('data.worksheet_name', 'Sheet1')
            ->assertJsonPath('data.worksheets.0.name', 'Sheet1')
            ->assertJsonPath('data.worksheets.0.data_rows', 2)
            ->assertJsonPath('data.worksheets.0.column_count', 23)
            ->assertJsonPath('data.summary.counts.total', 0);
        $this->assertGreaterThan(0, $response->json('data.source_size_bytes'));
        $this->assertSame(0, ImportRow::count());

        $columns = $this->columns($response->json('data.id'))->json('data.columns');
        $this->assertSame('B', $columns[0]['letter']);
        $this->assertSame('المفتاح', $columns[0]['header']);
        // The private file exists; its path is never exposed.
        $this->assertStringNotContainsString('imports/', $response->getContent());
        Storage::disk('local')->assertExists(ImportBatch::sole()->source_file_path);
    }

    public function test_13_several_plausible_worksheets_need_an_explicit_choice(): void
    {
        $id = $this->upload(SyntheticXlsx::write([
            'أ' => [1 => self::HEADERS, 2 => $this->row()],
            'ب' => [1 => self::HEADERS, 2 => $this->row(['id' => 900000002]), 3 => $this->row(['id' => 900000003])],
            'ملاحظات' => [1 => ['ملاحظة']],   // header only: not plausible
        ]))->assertCreated()->assertJsonPath('data.worksheet_name', null)->assertJsonPath('data.suggested_worksheet', null)->json('data.id');

        // No mapping step before a worksheet is chosen.
        $this->actingAs($this->admin)->getJson(self::BASE."/{$id}/columns")->assertUnprocessable()->assertJsonValidationErrors('worksheet');
        $this->actingAs($this->admin)->putJson(self::BASE."/{$id}/worksheet", ['worksheet' => 'ملاحظات'])->assertUnprocessable();
        $this->actingAs($this->admin)->putJson(self::BASE."/{$id}/worksheet", ['worksheet' => 'غير موجودة'])->assertUnprocessable();

        $this->actingAs($this->admin)->putJson(self::BASE."/{$id}/worksheet", ['worksheet' => 'ب'])->assertOk()->assertJsonPath('data.worksheet_name', 'ب');
        [$fields, $ignored] = $this->suggested($id);
        $this->confirm($id, $fields, $ignored)->assertOk()->assertJsonPath('data.summary.counts.total', 2);
    }

    public function test_the_recognized_sheet_is_suggested_among_others(): void
    {
        $this->upload(SyntheticXlsx::write([
            'ملاحظات' => [1 => ['المؤشر', 'القيمة'], 2 => ['x', 1]],
            'البيانات' => [1 => self::HEADERS, 2 => $this->row()],
        ]))->assertCreated()->assertJsonPath('data.worksheet_name', 'البيانات');
    }

    public function test_invalid_workbooks_create_no_batch(): void
    {
        $garbage = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        file_put_contents($garbage, 'not a workbook');
        $this->upload($garbage)->assertUnprocessable()->assertJsonValidationErrors('file');

        // Header row only — no data worksheet.
        $this->upload(SyntheticXlsx::write(['Sheet1' => [1 => self::HEADERS]]))->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->actingAs($this->admin)->post(self::BASE, [
            'clan_code' => 'SYN_TARGET', 'import_mode' => 'INITIAL', 'file' => UploadedFile::fake()->create('families.csv', 1, 'text/csv'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, ImportBatch::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // =========================================================== Step 3

    public function test_15_20_known_headers_get_suggestions_and_wife_columns_stay_distinct(): void
    {
        $id = $this->upload($this->workbook([2 => $this->row()]))->json('data.id');
        $suggested = collect($this->columns($id)->json('data.columns'))->pluck('suggested_field', 'letter');

        $this->assertSame('source_family_key', $suggested['B']);
        $this->assertSame('national_id', $suggested['C']);
        $this->assertSame('full_name', $suggested['D']);
        $this->assertSame('original_residence_text', $suggested['I']);
        $this->assertSame('declared_living_sons', $suggested['M']);
        $this->assertSame('declared_living_daughters', $suggested['N']);
        // Repeated headers: addressed by position.
        $this->assertSame(['wife_1_national_id', 'wife_1_name', 'wife_2_national_id', 'wife_2_name', 'wife_3_national_id', 'wife_3_name', 'wife_4_national_id', 'wife_4_name'],
            [$suggested['P'], $suggested['Q'], $suggested['R'], $suggested['S'], $suggested['T'], $suggested['U'], $suggested['V'], $suggested['W']]);
        $this->assertTrue(collect($this->columns($id)->json('data.fields'))->firstWhere('field', 'full_name')['required']);
        $this->assertFalse(collect($this->columns($id)->json('data.fields'))->firstWhere('field', 'mobile')['required']);
    }

    public function test_20_a_repeated_wife_column_is_mapped_by_its_position(): void
    {
        $id = $this->upload($this->workbook([
            2 => $this->row([], [[900000011, 'زوجة أولى'], [900000012, 'زوجة ثانية']]),
        ]))->json('data.id');
        [$fields, $ignored] = $this->suggested($id);

        // Map slot 1 to the SECOND pair (R/S) and ignore the first pair.
        $fields['wife_1_national_id'] = 'R';
        $fields['wife_1_name'] = 'S';
        unset($fields['wife_2_national_id'], $fields['wife_2_name']);
        $ignored = [...array_diff($ignored, ['R', 'S']), 'P', 'Q'];
        $this->confirm($id, $fields, $ignored)->assertOk();

        $n = $this->rowAt(2)->normalized_payload;
        $this->assertSame(['900000012', 'زوجة ثانية', null, null], [$n['wife_1_national_id'], $n['wife_1_name'], $n['wife_2_national_id'], $n['wife_2_name']]);
        $this->assertArrayNotHasKey('P', $this->rowAt(2)->raw_payload['cells']);
    }

    public function test_16_25_the_confirmed_mapping_drives_staging(): void
    {
        $id = $this->upload($this->workbook([2 => $this->row(['name' => 'اسم في العمود D', 'city' => 'قيمة في العمود I'])]))->json('data.id');
        [$fields, $ignored] = $this->suggested($id);
        // Deliberately swap two mappings: staging must follow the confirmation.
        [$fields['full_name'], $fields['original_residence_text']] = ['I', 'D'];

        $this->confirm($id, $fields, $ignored)->assertOk()
            ->assertJsonPath('data.status', ImportBatchStatus::READY_FOR_REVIEW->value)
            ->assertJsonPath('data.staged', true)
            ->assertJsonPath('data.column_mapping.fields.full_name', 'I');

        $n = $this->rowAt(2)->normalized_payload;
        $this->assertSame('قيمة في العمود I', $n['full_name']);
        $this->assertSame('اسم في العمود D', $n['original_residence_text']);
        $this->assertNotNull(ImportBatch::sole()->mapping_confirmed_at);
    }

    public function test_17_invalid_mappings_are_refused_and_stage_nothing(): void
    {
        $id = $this->upload($this->workbook([2 => $this->row()]))->json('data.id');
        [$fields, $ignored] = $this->suggested($id);

        $missing = $fields;
        unset($missing['full_name']);
        $this->confirm($id, $missing, [...$ignored, 'D'])->assertUnprocessable()->assertJsonValidationErrors('mapping.full_name');

        $twice = [...$fields, 'mobile' => 'C'];
        $this->confirm($id, $twice, [...$ignored, 'O'])->assertUnprocessable()->assertJsonValidationErrors('mapping.mobile');

        $this->confirm($id, [...$fields, 'full_name' => 'ZZ'], $ignored)->assertUnprocessable()->assertJsonValidationErrors('mapping.full_name');
        $this->confirm($id, [...$fields, 'invented_field' => 'D'], $ignored)->assertUnprocessable()->assertJsonValidationErrors('mapping.invented_field');

        // Every kept column must be explicitly mapped or ignored: column O
        // (الجوال) is left undecided here.
        $undecided = $fields;
        unset($undecided['mobile']);
        $this->confirm($id, $undecided, $ignored)->assertUnprocessable()->assertJsonValidationErrors('ignored');

        $this->assertSame(0, ImportRow::count());
        $this->assertNull(ImportBatch::sole()->mapping_confirmed_at);
    }

    public function test_18_19_optional_columns_may_be_absent_and_columns_may_be_ignored(): void
    {
        $headers = ['المفتاح', 'رقم الهوية', 'الاسم', 'ملاحظات داخلية'];
        $id = $this->upload(SyntheticXlsx::write(['Sheet1' => [1 => $headers, 2 => ['أبو سعادة', 900000001, 'رب أسرة', 'ملاحظة سرية']]]))->json('data.id');

        $this->confirm($id, ['source_family_key' => 'A', 'national_id' => 'B', 'full_name' => 'C'], ['D'])->assertOk();

        $row = $this->rowAt(2);
        $this->assertNull($row->normalized_payload['mobile']);
        $this->assertNull($row->normalized_payload['birth_date']);
        $this->assertNull($row->normalized_payload['wife_1_name']);
        $this->assertStringNotContainsString('ملاحظة سرية', $this->storedImportText());
        $this->assertSame(['D'], ImportBatch::sole()->column_mapping['ignored']);
    }

    public function test_21_22_52_forbidden_columns_are_never_mappable_stored_or_exposed(): void
    {
        $id = $this->upload($this->workbook([2 => $this->row(), 3 => $this->row(['id' => 900000002, 'key' => 'أبو نصيرة'])]))->json('data.id');

        $columns = $this->columns($id);
        $this->assertNotContains('A', array_column($columns->json('data.columns'), 'letter'));
        $this->assertNotContains('H', array_column($columns->json('data.columns'), 'letter'));
        $this->assertSame([['letter' => 'A', 'header' => 'هويتك'], ['letter' => 'H', 'header' => 'الديانة']], $columns->json('data.excluded_columns'));

        [$fields, $ignored] = $this->suggested($id);
        $this->confirm($id, [...$fields, 'mobile' => 'H'], [...$ignored, 'O'])->assertUnprocessable()->assertJsonValidationErrors('mapping.mobile');
        $this->confirm($id, $fields, [...$ignored, 'A'])->assertUnprocessable()->assertJsonValidationErrors('ignored');
        $staged = $this->confirm($id, $fields, $ignored)->assertOk();

        // Stored: never the forbidden VALUES (anywhere) nor as row data.
        $stored = $this->storedImportText();
        foreach ([self::SELF_ID, self::RELIGION] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $stored);
        }
        $this->assertStringNotContainsString('الديانة', json_encode(DB::table('import_rows')->get()->all(), JSON_UNESCAPED_UNICODE));

        $responses = [
            $staged, $columns,
            $this->actingAs($this->admin)->getJson(self::BASE."/{$id}")->assertOk(),
            $this->actingAs($this->admin)->getJson(self::BASE."/{$id}/family-keys")->assertOk(),
            $this->actingAs($this->admin)->getJson(self::BASE."/{$id}/rows")->assertOk(),
            $this->actingAs($this->admin)->getJson(self::BASE)->assertOk(),
        ];
        foreach ($responses as $r) {
            foreach ([self::SELF_ID, self::RELIGION, '900000001', '0590000000', 'raw_payload', 'normalized_payload'] as $secret) {
                $this->assertStringNotContainsString($secret, $r->getContent());
            }
        }
        // Samples are masked: at most the last three digits of an ID or phone.
        $samples = collect($columns->json('data.columns'))->pluck('samples', 'letter');
        $this->assertSame('••••••001', $samples['C'][0]);
    }

    public function test_23_replacing_the_workbook_invalidates_the_mapping(): void
    {
        $id = $this->stage($this->workbook([2 => $this->row()]));
        $this->assertSame(1, ImportRow::count());

        $this->actingAs($this->admin)->post(self::BASE."/{$id}/file", ['file' => $this->file($this->workbook([2 => $this->row(['id' => 900000009])]))], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status', ImportBatchStatus::UPLOADED->value)
            ->assertJsonPath('data.column_mapping', null)
            ->assertJsonPath('data.mapping_confirmed_at', null)
            ->assertJsonPath('data.row_count', 0)
            ->assertJsonPath('data.import_mode', 'INITIAL')
            ->assertJsonPath('data.clan.code', 'SYN_TARGET');
        $this->assertSame(0, ImportRow::count());
        $this->assertSame(1, ImportBatch::count());
    }

    public function test_24_changing_the_worksheet_invalidates_the_mapping(): void
    {
        $path = SyntheticXlsx::write([
            'أ' => [1 => self::HEADERS, 2 => $this->row()],
            'ب' => [1 => self::HEADERS, 2 => $this->row(['id' => 900000002])],
        ]);
        $id = $this->upload($path)->json('data.id');
        $this->actingAs($this->admin)->putJson(self::BASE."/{$id}/worksheet", ['worksheet' => 'أ'])->assertOk();
        [$fields, $ignored] = $this->suggested($id);
        $this->confirm($id, $fields, $ignored)->assertOk();
        $this->assertSame(1, ImportRow::count());

        // Re-selecting the same sheet keeps the mapping; another sheet drops it.
        $this->actingAs($this->admin)->putJson(self::BASE."/{$id}/worksheet", ['worksheet' => 'أ'])->assertOk()->assertJsonPath('data.staged', true);
        $this->actingAs($this->admin)->putJson(self::BASE."/{$id}/worksheet", ['worksheet' => 'ب'])->assertOk()
            ->assertJsonPath('data.column_mapping', null)->assertJsonPath('data.staged', false);
        $this->assertSame(0, ImportRow::count());
    }

    public function test_changing_a_confirmed_mapping_restages(): void
    {
        $id = $this->stage($this->workbook([2 => $this->row(['mobile' => '0591111111'])]));
        [$fields, $ignored] = $this->suggested($id);
        unset($fields['mobile']);
        $this->confirm($id, $fields, [...$ignored, 'O'])->assertOk()->assertJsonPath('data.summary.counts.total', 1);

        $this->assertSame(1, ImportRow::count());
        $this->assertNull($this->rowAt(2)->normalized_payload['mobile']);
    }

    // =========================================================== staging

    public function test_26_27_row_numbers_are_preserved_and_blank_rows_ignored(): void
    {
        $blank = array_fill(0, count(self::HEADERS), null);
        $onlyForbidden = $blank;
        $onlyForbidden[0] = self::SELF_ID; // a value only in an excluded column is still blank
        $this->stage($this->workbook([
            2 => $this->row(['id' => 900000101]),
            3 => $this->row(['id' => 900000102]),
            4 => $blank,
            6 => $this->row(['id' => 900000106]),
            7 => $onlyForbidden,
            8 => array_fill(0, count(self::HEADERS), '   '),
        ]));

        $this->assertSame([2, 3, 6], ImportRow::orderBy('row_number')->pluck('row_number')->all());
    }

    public function test_28_29_formula_keys_are_detectable_and_keys_never_inferred(): void
    {
        $this->stage($this->workbook([
            2 => [self::SELF_ID, ['formula' => 'TRIM(RIGHT(D2,5))', 'cached' => 'البريم'], ...array_slice($this->row(['id' => 900000401]), 2)],
            3 => [self::SELF_ID, ['shared_formula' => 0, 'cached' => 'البريم'], ...array_slice($this->row(['id' => 900000402]), 2)],
            4 => $this->row(['id' => 900000403, 'key' => null, 'name' => 'رب أسرة أبو سعادة']),
        ]));

        foreach ([2, 3] as $n) {
            $this->assertSame('البريم', $this->rowAt($n)->source_family_key);
            $this->assertSame('FORMULA', $this->rowAt($n)->normalized_payload['source_family_key_origin']);
            $this->assertSame(['FAMILY_KEY_FROM_FORMULA'], array_column($this->rowAt($n)->issues, 'code'));
        }
        $this->assertStringNotContainsString('TRIM(RIGHT', $this->storedImportText());
        // Never inferred from the name.
        $this->assertNull($this->rowAt(4)->source_family_key);
        $this->assertSame(['MISSING_FAMILY_KEY'], array_column($this->rowAt(4)->issues, 'code'));
    }

    public function test_30_31_32_female_widowed_heads_stage_normally_and_missing_keys_stay(): void
    {
        $this->stage($this->workbook([
            2 => $this->row(['id' => 900000501, 'gender' => 'أنثى', 'marital' => 'متزوجة']),
            3 => $this->row(['id' => 900000502, 'gender' => 'أنثى', 'marital' => 'أرمل', 'name' => 'ربة أسرة أرملة حجازي']),
            4 => $this->row(['id' => 900000503, 'key' => null]),
        ]));

        $this->assertSame(ImportRowStatus::PENDING, $this->rowAt(2)->status);
        $this->assertSame(ImportRowStatus::PENDING, $this->rowAt(3)->status);
        $this->assertSame('أبو سعادة', $this->rowAt(3)->source_family_key);
        $this->assertSame(ImportRowStatus::FLAGGED, $this->rowAt(4)->status);
    }

    public function test_33_34_35_counts_and_issue_breakdown_are_exact(): void
    {
        $twoErrors = $this->row(['id' => 900000701]);
        $twoErrors[11] = ['error' => '#VALUE!'];
        $twoErrors[12] = ['error' => '#N/A'];
        $id = $this->stage($this->workbook([
            2 => $this->row(['id' => 900000700]),                  // ready
            3 => $twoErrors,                                       // rejected
            4 => [...$this->row(['id' => 900000702]), 'زائد'],     // rejected (extra cell)
            5 => $this->row(['id' => 900000703, 'key' => null]),   // needs review
            6 => $this->row(['id' => 900000704, 'name' => null]),  // needs review
            7 => $this->row(['id' => 900000705]),                  // ready
        ]));

        $summary = $this->actingAs($this->admin)->getJson(self::BASE."/{$id}")->assertOk()->json('data.summary');
        $this->assertSame(['total' => 6, 'ready' => 2, 'needs_review' => 2, 'rejected' => 2], $summary['counts']);
        $this->assertSame($summary['counts']['total'], $summary['counts']['ready'] + $summary['counts']['needs_review'] + $summary['counts']['rejected']);
        // FLAGGED is needs review, not rejected.
        $this->assertSame(2, $summary['statuses']['FLAGGED']);
        $this->assertSame(['CELL_ERROR' => 1, 'EXTRA_CELLS' => 1, 'MISSING_FAMILY_KEY' => 1, 'MISSING_FULL_NAME' => 1], $summary['issues']);
        $this->assertSame('NOT_RUN', $summary['reconciliation']['state']);

        // Problem rows: minimal and safe.
        $rows = $this->actingAs($this->admin)->getJson(self::BASE."/{$id}/rows")->assertOk();
        $this->assertSame([3, 4, 5, 6], array_column($rows->json('data'), 'row_number'));
        $this->assertSame(['CELL_ERROR'], $rows->json('data.0.issues'));
        $this->assertSame(['row_number', 'status', 'issues', 'source_family_key'], array_keys($rows->json('data.0')));
        $this->assertSame([5, 6], array_column($this->actingAs($this->admin)->getJson(self::BASE."/{$id}/rows?filter=needs_review")->json('data'), 'row_number'));
        $this->assertSame([3, 4], array_column($this->actingAs($this->admin)->getJson(self::BASE."/{$id}/rows?filter=rejected")->json('data'), 'row_number'));
    }

    // =========================================================== Step 4

    public function test_36_37_family_key_discovery_is_exact_and_scoped_to_the_target_clan(): void
    {
        $id = $this->stage($this->workbook([
            2 => $this->row(['id' => 900000801, 'key' => 'أبو سعادة']),
            3 => $this->row(['id' => 900000802, 'key' => 'أبو سعادة']),
            4 => $this->row(['id' => 900000803, 'key' => 'أبو نصيرة']),
            5 => $this->row(['id' => 900000804, 'key' => 'الفجم']),
            6 => $this->row(['id' => 900000805, 'key' => 'ابو سعادة']),
        ]));
        $keys = collect($this->actingAs($this->admin)->getJson(self::BASE."/{$id}/family-keys")->assertOk()->json('data'))->keyBy('key');

        $this->assertSame(2, $keys['أبو سعادة']['row_count']);
        $this->assertSame([2, 3], $keys['أبو سعادة']['example_rows']);
        $this->assertSame('SAADA', $keys['أبو سعادة']['existing_branch']['code']);
        $this->assertSame('NUSEIRA', $keys['أبو نصيرة']['existing_branch']['code']);
        $this->assertNull($keys['الفجم']['existing_branch']);       // another Clan's Branch
        $this->assertNull($keys['ابو سعادة']['existing_branch']);   // exact only
    }

    // =========================================================== no domain writes

    public function test_38_to_42_the_whole_wizard_writes_no_registry_record(): void
    {
        $before = $this->registryCounts();

        $id = $this->stage($this->workbook([
            2 => $this->row(['id' => 900000901, 'key' => 'مفتاح جديد تماما'], [[900000902, 'زوجة']]),
            3 => $this->row(['id' => 900000903, 'life' => 'متوفى', 'death' => new DateTimeImmutable('2023-01-01')]),
        ]), mode: 'INCREMENTAL');
        $this->actingAs($this->admin)->getJson(self::BASE."/{$id}/family-keys")->assertOk();

        $this->assertSame($before, $this->registryCounts());
        $this->assertFalse(ImportRow::whereNotNull('family_id')->exists());
    }

    // =========================================================== incremental safety

    public function test_43_44_same_file_is_refused_but_other_files_and_clans_are_allowed(): void
    {
        $path = $this->workbook([2 => $this->row()]);
        $first = $this->upload($path)->assertCreated()->json('data.id');

        // Same file + same Clan: refused whatever the mode.
        $this->upload($path)->assertStatus(409)->assertJsonPath('existing_batch.id', $first);
        $this->upload($path, mode: 'INCREMENTAL')->assertStatus(409);
        // A different workbook for the same Clan: allowed.
        $this->upload($this->workbook([2 => $this->row(), 3 => $this->row(['id' => 900000002])]), mode: 'INCREMENTAL')->assertCreated();
        // The same file for another Clan: allowed.
        $this->upload($path, clan: 'SYN_OTHER')->assertCreated();
        // After FAILED: allowed again.
        ImportBatch::where('uuid', $first)->update(['status' => ImportBatchStatus::FAILED->value]);
        $this->upload($path)->assertCreated();
        $this->assertSame(4, ImportBatch::count());

        // Replacing a workbook with a file already live for the Clan: refused.
        $second = ImportBatch::where('import_mode', 'INCREMENTAL')->value('uuid');
        $this->actingAs($this->admin)->post(self::BASE."/{$second}/file", ['file' => $this->file($path)], ['Accept' => 'application/json'])->assertStatus(409);
    }

    public function test_45_to_50_incremental_batches_never_overwrite_merge_or_delete(): void
    {
        $this->seed(RelationshipTypeSeeder::class);
        // Existing registry data in the target Clan (synthetic).
        $family = Family::factory()->create(['clan_id' => $this->target->id]);
        $person = Person::factory()->create(['national_id' => '900000001', 'full_name' => 'الاسم المسجل', 'mobile' => '0599999999']);
        FamilyMembership::factory()->householdHead()->create(['family_id' => $family->id, 'person_id' => $person->id]);
        $absentFamily = Family::factory()->create(['clan_id' => $this->target->id]);
        $absentPerson = Person::factory()->create(['national_id' => '900000077', 'full_name' => 'غائب عن الملف']);
        FamilyMembership::factory()->householdHead()->create(['family_id' => $absentFamily->id, 'person_id' => $absentPerson->id]);
        $snapshot = fn () => [
            Person::withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
            Family::withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
            FamilyMembership::orderBy('id')->get()->map->getAttributes()->all(),
        ];
        $before = $snapshot();

        $first = $this->stage($this->workbook([2 => $this->row(['id' => 900000001])]));
        // Day 2: same person with CHANGED name/mobile, one new record, two no-ID rows with the same name;
        // the absent person is simply not in the file.
        $second = $this->stage($this->workbook([
            2 => $this->row(['id' => 900000001, 'name' => 'اسم مختلف في الملف', 'mobile' => '0591234567']),
            3 => $this->row(['id' => 900000010, 'key' => 'أبو نصيرة']),
            4 => $this->row(['id' => null, 'name' => 'اسم مكرر بلا هوية']),
            5 => $this->row(['id' => null, 'name' => 'اسم مكرر بلا هوية']),
        ]), mode: 'INCREMENTAL');

        // Nothing in the registry changed, was merged, deactivated or deleted.
        $this->assertSame($before, $snapshot());
        $this->assertSame(2, ImportBatch::count());

        // Rows are staged, not reconciled: no NEW/UNCHANGED/DUPLICATE claim is fabricated.
        $this->assertSame(4, ImportRow::where('import_batch_id', ImportBatch::where('uuid', $second)->value('id'))->count());
        $this->assertFalse(ImportRow::whereNotNull('reconciliation_status')->exists());
        $this->assertSame('NOT_RUN', $this->actingAs($this->admin)->getJson(self::BASE."/{$second}")->json('data.summary.reconciliation.state'));
        // Same-name rows without a National ID are kept separately (never deduplicated by name).
        $this->assertSame(ImportRowStatus::PENDING, $this->rowAt(4, $second)->status);
        $this->assertSame(ImportRowStatus::PENDING, $this->rowAt(5, $second)->status);
        // The first batch's history is untouched by the second.
        $this->assertSame(1, ImportRow::where('import_batch_id', ImportBatch::where('uuid', $first)->value('id'))->count());
    }

    public function test_51_reopening_a_batch_returns_the_same_context(): void
    {
        $id = $this->stage($this->workbook([2 => $this->row(), 3 => $this->row(['id' => 900000002, 'key' => null])]), mode: 'INCREMENTAL');
        $staged = $this->actingAs($this->admin)->getJson(self::BASE."/{$id}")->assertOk()->json('data');
        $again = $this->actingAs($this->admin)->getJson(self::BASE."/{$id}")->assertOk()->json('data');

        $this->assertSame($staged, $again);
        $this->assertSame('INCREMENTAL', $again['import_mode']);
        $this->assertSame('Sheet1', $again['worksheet_name']);
        $this->assertTrue($again['staged']);
        $this->assertSame('B', $again['column_mapping']['fields']['source_family_key']);
        $this->assertSame(['total' => 2, 'ready' => 1, 'needs_review' => 1, 'rejected' => 0], $again['summary']['counts']);
        $this->assertSame(1, $again['summary']['missing_family_key']);
        $this->assertSame(1, $again['summary']['distinct_family_keys']);
    }

    // =========================================================== authorization / Apply

    public function test_53_54_unauthorized_users_are_refused_and_there_is_no_apply(): void
    {
        $id = $this->stage($this->workbook([2 => $this->row()]));
        ImportBatch::query()->update(['status' => ImportBatchStatus::FAILED->value]);
        $path = $this->workbook([2 => $this->row(['id' => 900000044])]);

        foreach (['ADMINISTRATOR', 'DATA_ENTRY', 'SOCIAL_WORKER', 'REVIEWER', 'REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $user = $this->user($role);
            $this->upload($path, as: $user)->assertForbidden();
            $this->actingAs($user)->post(self::BASE."/{$id}/file", ['file' => $this->file($path)], ['Accept' => 'application/json'])->assertForbidden();
            $this->actingAs($user)->putJson(self::BASE."/{$id}/worksheet", ['worksheet' => 'Sheet1'])->assertForbidden();
            $this->actingAs($user)->getJson(self::BASE."/{$id}/columns")->assertForbidden();
            $this->actingAs($user)->postJson(self::BASE."/{$id}/mapping", ['mapping' => [], 'ignored' => []])->assertForbidden();
            foreach (['', "/{$id}", "/{$id}/family-keys", "/{$id}/rows"] as $suffix) {
                $this->actingAs($user)->getJson(self::BASE.$suffix)->assertForbidden();
            }
        }
        $this->app['auth']->forgetGuards();
        $this->getJson(self::BASE)->assertUnauthorized();

        // The Apply gate is closed by default: even SUPER_ADMIN is refused,
        // and nobody holds import.apply.
        $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/apply/start", ['plan_fingerprint' => str_repeat('a', 64)])->assertForbidden();
        $this->assertFalse($this->admin->hasPermissionTo('import.apply'));
        foreach (Role::all() as $role) {
            $this->assertFalse($role->hasPermissionTo('import.apply'), $role->name);
        }
    }
}
