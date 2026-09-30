<?php

namespace Tests\Feature\Import;

use App\Enums\ImportApplyEffect as E;
use App\Enums\ImportBatchStatus;
use App\Models\Branch;
use App\Models\Clan;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\ImportBatch;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Models\User;
use App\Support\Import\Apply\ImportApplyPlanner;
use App\Support\Import\Apply\ImportRowApplyPlan;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SyntheticXlsx;
use Tests\TestCase;

/**
 * Phase 4B.2 — the pure Apply planner and the read-only Dry Run (docs/03
 * §96b). Every plan is computed from a real staged + reconciled synthetic
 * workbook; nothing is ever written to the registry. All data synthetic.
 */
class DryRunTest extends TestCase
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

    /** A synthetic household row; wives are [id, name] pairs (null = empty slot). */
    private function row(array $o = [], array $wives = []): array
    {
        $cells = [
            '555000111', $o['key'] ?? 'مفتاح أ', array_key_exists('id', $o) ? $o['id'] : 910000001,
            $o['name'] ?? 'رب أسرة تجريبي '.($o['id'] ?? 'x'), $o['birth'] ?? new DateTimeImmutable('1980-01-15'),
            $o['gender'] ?? 'ذكر', $o['marital'] ?? 'متزوج', 'قيمة-ديانة-اختبارية', array_key_exists('city', $o) ? $o['city'] : 'مدينة أصلية',
            $o['life'] ?? 'حي', $o['death'] ?? null, $o['size'] ?? 6, $o['sons'] ?? 2, $o['daughters'] ?? 2, '0590000000',
        ];
        foreach (range(0, 3) as $k) {
            $cells[] = $wives[$k][0] ?? null;
            $cells[] = $wives[$k][1] ?? null;
        }

        return $cells;
    }

    /** Stage → resolve every key (NO_BRANCH unless given) → reconcile. Returns the batch uuid. */
    private function batch(array $rows, string $mode = 'INITIAL', array $decisions = []): string
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
        $keys = array_column($this->actingAs($this->admin)->getJson(self::BASE."/{$id}/family-keys")->json('data'), 'key');
        foreach ($decisions as $key => $body) {
            $this->actingAs($this->admin)->putJson(self::BASE."/{$id}/family-keys/resolution", ['source_family_key' => $key, ...$body])->assertOk();
        }
        $rest = array_values(array_diff($keys, array_keys($decisions)));
        if ($rest !== []) {
            $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/family-keys/bulk", ['decision' => 'NO_BRANCH', 'items' => array_map(fn ($k) => ['source_family_key' => $k], $rest)])->assertOk();
        }
        $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/reconcile")->assertOk();

        return $id;
    }

    private function model(string $uuid): ImportBatch
    {
        return ImportBatch::where('uuid', $uuid)->firstOrFail();
    }

    private function plan(string $uuid): array
    {
        return $this->actingAs($this->admin)->getJson(self::BASE."/{$uuid}/dry-run")->assertOk()->json('data');
    }

    /** Planner-level row plan (defensive rules included). */
    private function rowPlan(string $uuid, int $rowNumber): ImportRowApplyPlan
    {
        return collect(app(ImportApplyPlanner::class)->planRows($this->model($uuid)))->firstOrFail(fn ($r) => $r->rowNumber === $rowNumber);
    }

    private function apiRow(string $uuid, int $rowNumber): array
    {
        return collect($this->actingAs($this->admin)->getJson(self::BASE."/{$uuid}/dry-run/rows?search={$rowNumber}")->assertOk()->json('data'))
            ->firstOrFail(fn ($r) => $r['row_number'] === $rowNumber);
    }

    private function codes(array $failures): array
    {
        return array_column($failures, 'code');
    }

    // ================================================== purity and determinism

    public function test_planning_is_write_free_deterministic_and_reserves_no_identifier(): void
    {
        $id = $this->batch([2 => $this->row(['id' => 910000011], [[910000012, 'زوجة']]), 3 => $this->row(['id' => 910000013])]);
        $batch = $this->model($id);
        $counts = fn () => [Person::withTrashed()->count(), Family::withTrashed()->count(), FamilyMembership::count(), DB::table('family_residences')->count(),
            DB::table('family_household_declarations')->count(), DB::table('family_activities')->count(), DB::table('import_apply_records')->count(),
            Person::withTrashed()->max('id'), Family::withTrashed()->max('id')];
        $before = $counts();

        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = strtolower(ltrim($q->sql));
        });
        $first = app(ImportApplyPlanner::class)->plan($batch);
        $second = app(ImportApplyPlanner::class)->plan($batch->fresh());

        $writes = array_filter($sql, fn ($s) => preg_match('/^(insert|update|delete|replace|create|alter|drop)\b/', $s) || str_contains($s, 'nextval'));
        $this->assertSame([], array_values($writes));
        $this->assertSame($before, $counts());
        $this->assertSame($first->fingerprint(), $second->fingerprint());
        $this->assertTrue($first->executable());
    }

    public function test_the_dry_run_api_changes_nothing_and_writes_no_provenance(): void
    {
        $id = $this->batch([2 => $this->row(['id' => 910000021], [[910000022, 'زوجة']])]);
        $before = $this->model($id)->only(['status', 'updated_at', 'reconciled_at', 'reconciliation_fingerprint', 'apply_started_at']);

        $a = $this->plan($id);
        $b = $this->plan($id);
        $this->actingAs($this->admin)->getJson(self::BASE."/{$id}/dry-run/rows")->assertOk();

        $this->assertSame($a['plan_fingerprint'], $b['plan_fingerprint']);
        $this->assertSame(['READY', false], [$a['state'], $a['execution_enabled']]);
        $this->assertEquals($before, $this->model($id)->only(array_keys($before)));
        $this->assertSame(0, DB::table('import_apply_records')->count());
        $this->assertSame([0, 0, 0], [Person::count(), Family::count(), FamilyMembership::count()]);
        $this->assertSame(0, DB::table('import_rows')->whereNotNull('family_id')->count());
    }

    // ================================================== head person

    public function test_a_new_head_is_planned_for_creation_with_approved_values_only(): void
    {
        $id = $this->batch([2 => $this->row(['id' => 910000031, 'marital' => 'متعدد الزوجات'])]);
        $head = $this->rowPlan($id, 2)->effect(E::HEAD_PERSON);

        $this->assertSame('CREATE', $head->intent->value);
        $this->assertSame(['full_name', 'national_id', 'gender', 'birth_date', 'marital_status', 'mobile', 'life_status', 'death_date'], array_keys($head->values));
        $this->assertSame(['910000031', 'MALE', '1980-01-15', 'MARRIED', 'ALIVE', null], [$head->values['national_id'], $head->values['gender'], $head->values['birth_date'], $head->values['marital_status'], $head->values['life_status'], $head->values['death_date']]);
        $row = $this->apiRow($id, 2);
        $this->assertSame(['CREATE', 'CREATE', 'CREATE'], [$row['head_person']['intent'], $row['family']['intent'], $row['head_membership']['intent']]);
    }

    public function test_an_existing_unlinked_head_is_reused_without_updates(): void
    {
        $person = Person::factory()->create(['national_id' => '910000041', 'gender' => 'MALE', 'birth_date' => '1980-01-15', 'full_name' => 'اسم في السجل']);
        $id = $this->batch([2 => $this->row(['id' => 910000041])]);

        $head = $this->rowPlan($id, 2)->effect(E::HEAD_PERSON);
        $this->assertSame(['REUSE', $person->id, $person->person_code, []], [$head->intent->value, $head->existingId, $head->existingCode, $head->values]);
        $this->assertContains('HEAD_PERSON_REUSED', $this->rowPlan($id, 2)->warnings);
        $this->assertSame(1, $this->plan($id)['counts']['persons']['reuse_existing']);
        $this->assertSame('اسم في السجل', $person->fresh()->full_name);
    }

    public function test_a_head_without_national_id_is_created_and_never_matched_by_name(): void
    {
        Person::factory()->create(['full_name' => 'اسم فريد للغاية', 'national_id' => null]);
        $id = $this->batch([2 => $this->row(['id' => null, 'name' => 'اسم فريد مختلف'])]);

        $plan = $this->rowPlan($id, 2);
        $this->assertSame(['CREATE', null], [$plan->effect(E::HEAD_PERSON)->intent->value, $plan->effect(E::HEAD_PERSON)->values['national_id']]);
        $this->assertContains('HEAD_WITHOUT_NATIONAL_ID', $plan->warnings);
        $this->assertTrue($plan->executable());
    }

    public function test_a_soft_deleted_head_blocks_and_is_refused_before_planning(): void
    {
        Person::factory()->create(['national_id' => '910000051'])->delete();
        $id = $this->batch([2 => $this->row(['id' => 910000051])]);

        // Reconciliation already asks for review, so the Dry Run refuses…
        $this->assertContains('ROWS_REVIEW_REQUIRED', $this->codes($this->plan($id)['preconditions']));
        $this->assertSame('PRECONDITIONS_FAILED', $this->plan($id)['state']);
        // …and the planner itself would block the row (defensive re-check).
        $plan = $this->rowPlan($id, 2);
        $this->assertSame(['BLOCK', 'HEAD_DELETED_PERSON'], [$plan->effect(E::HEAD_PERSON)->intent->value, $plan->effect(E::HEAD_PERSON)->reason]);
        $this->assertFalse($plan->executable());
        $this->assertSame(0, Person::count());
    }

    public function test_a_head_with_an_active_membership_blocks(): void
    {
        $id = $this->batch([2 => $this->row(['id' => 910000061])]);
        // Registry changes after planning inputs were reconciled (defensive re-check).
        $person = Person::factory()->create(['national_id' => '910000061']);
        FamilyMembership::factory()->householdHead()->create(['person_id' => $person->id, 'family_id' => Family::factory()->create()->id]);

        $plan = $this->rowPlan($id, 2);
        $this->assertSame(['BLOCK', 'HEAD_HAS_ACTIVE_MEMBERSHIP'], [$plan->effect(E::HEAD_PERSON)->intent->value, $plan->effect(E::HEAD_PERSON)->reason]);
        $this->assertSame('BLOCK', $plan->effect(E::HEAD_MEMBERSHIP)->intent->value);
        $this->assertContains('RECONCILIATION_STALE', $this->codes($this->plan($id)['preconditions']));
    }

    public function test_a_deceased_head_stays_head_is_created_deceased_and_warns(): void
    {
        $id = $this->batch([2 => $this->row(['id' => 910000071, 'life' => 'متوفى', 'death' => new DateTimeImmutable('2020-05-01')])]);

        $plan = $this->rowPlan($id, 2);
        $this->assertSame(['CREATE', 'DECEASED', '2020-05-01'], [$plan->effect(E::HEAD_PERSON)->intent->value, $plan->effect(E::HEAD_PERSON)->values['life_status'], $plan->effect(E::HEAD_PERSON)->values['death_date']]);
        $this->assertSame(['CREATE', 'CREATE'], [$plan->effect(E::FAMILY)->intent->value, $plan->effect(E::HEAD_MEMBERSHIP)->intent->value]);
        $this->assertContains('HOUSEHOLD_HEAD_DECEASED', $plan->warnings);
        $this->assertTrue($plan->executable());
        $this->assertSame(1, $this->plan($id)['counts']['warnings']['HOUSEHOLD_HEAD_DECEASED']);
    }

    // ================================================== spouses

    public function test_spouse_gender_follows_the_head_gender_of_this_source_format(): void
    {
        $id = $this->batch([
            2 => $this->row(['id' => 910000081, 'gender' => 'ذكر'], [[910000082, 'زوجة']]),
            3 => $this->row(['id' => 910000083, 'gender' => 'أنثى', 'marital' => 'متزوجة'], [[910000084, 'زوج']]),
        ]);

        $wife = $this->rowPlan($id, 2)->effect(E::SPOUSE_1_PERSON);
        $husband = $this->rowPlan($id, 3)->effect(E::SPOUSE_1_PERSON);
        $this->assertSame(['CREATE', 'FEMALE', 'UNKNOWN', 'UNKNOWN', null, null, null], [$wife->intent->value, $wife->values['gender'], $wife->values['life_status'], $wife->values['marital_status'], $wife->values['birth_date'], $wife->values['mobile'], $wife->values['death_date']]);
        $this->assertSame(['CREATE', 'MALE'], [$husband->intent->value, $husband->values['gender']]);
        $this->assertSame(['CREATE', 'CREATE'], [$this->rowPlan($id, 2)->intentOf(E::SPOUSE_1_MEMBERSHIP)->value, $this->rowPlan($id, 3)->intentOf(E::SPOUSE_1_MEMBERSHIP)->value]);
    }

    public function test_a_spouse_whose_gender_cannot_be_derived_blocks_instead_of_guessing(): void
    {
        $id = $this->batch([2 => $this->row(['id' => 910000085, 'gender' => ''], [[910000086, 'زوج أو زوجة']])]);

        $plan = $this->rowPlan($id, 2);
        $this->assertSame(['BLOCK', 'SPOUSE_GENDER_UNDERIVABLE'], [$plan->intentOf(E::SPOUSE_1_PERSON)->value, $plan->effect(E::SPOUSE_1_PERSON)->reason]);
        $this->assertSame('BLOCK', $plan->intentOf(E::SPOUSE_1_MEMBERSHIP)->value);
        $this->assertFalse($plan->executable());
        $counts = $this->plan($id);
        $this->assertSame(['ROWS_BLOCKED', 1], [$counts['state'], $counts['counts']['blocked_rows']]);
    }

    public function test_widow_remarriage_one_person_membership_only_with_the_living_head(): void
    {
        $id = $this->batch([
            2 => $this->row(['id' => 910000091, 'life' => 'متوفى'], [[910000099, 'زوجة']]),
            3 => $this->row(['id' => 910000092], [[910000099, 'زوجة']]),
        ]);

        // The membership occurrence owns the Person (no cross-row dependency).
        $living = $this->rowPlan($id, 3);
        $historical = $this->rowPlan($id, 2);
        $this->assertSame(['CREATE', 'CREATE'], [$living->intentOf(E::SPOUSE_1_PERSON)->value, $living->intentOf(E::SPOUSE_1_MEMBERSHIP)->value]);
        $reuse = $historical->effect(E::SPOUSE_1_PERSON);
        $this->assertSame(['REUSE', 3, E::SPOUSE_1_PERSON], [$reuse->intent->value, $reuse->ownerRow, $reuse->ownerEffect]);
        $this->assertSame(['OMIT', 'HISTORICAL_RELATIONSHIP_NO_ACTIVE_HOUSEHOLD'], [$historical->intentOf(E::SPOUSE_1_MEMBERSHIP)->value, $historical->effect(E::SPOUSE_1_MEMBERSHIP)->reason]);
        $this->assertSame(1, $this->plan($id)['counts']['effects']['spouse_persons']['CREATE']);
        $this->assertTrue($historical->executable());
    }

    public function test_a_spouse_of_two_deceased_heads_is_one_person_with_no_membership(): void
    {
        $id = $this->batch([
            2 => $this->row(['id' => 910000101, 'life' => 'متوفى'], [[910000109, 'زوجة']]),
            3 => $this->row(['id' => 910000102, 'life' => 'متوفي'], [[910000109, 'زوجة']]),
        ]);

        $first = $this->rowPlan($id, 2);
        $second = $this->rowPlan($id, 3);
        $this->assertSame('CREATE', $first->intentOf(E::SPOUSE_1_PERSON)->value);
        $this->assertSame(['REUSE', 2], [$second->intentOf(E::SPOUSE_1_PERSON)->value, $second->effect(E::SPOUSE_1_PERSON)->ownerRow]);
        foreach ([$first, $second] as $row) {
            $this->assertSame(['OMIT', 'HISTORICAL_RELATIONSHIP_NO_ACTIVE_HOUSEHOLD'], [$row->intentOf(E::SPOUSE_1_MEMBERSHIP)->value, $row->effect(E::SPOUSE_1_MEMBERSHIP)->reason]);
            $this->assertTrue($row->executable());
        }
        $counts = $this->plan($id)['counts'];
        $this->assertSame([1, 2, 0], [$counts['effects']['spouse_persons']['CREATE'], $counts['effects']['spouse_memberships']['OMIT'], $counts['blocked_rows']]);
    }

    public function test_a_polygamous_independent_household_reuses_heads_and_omits_memberships(): void
    {
        $id = $this->batch([
            2 => $this->row(['id' => 910000111, 'marital' => 'متعدد الزوجات'], [[910000112, 'زوجة أولى'], [910000113, 'زوجة ثانية']]),
            3 => $this->row(['id' => 910000113, 'gender' => 'أنثى', 'marital' => 'متزوجة', 'name' => 'ربة أسرة'], [[910000111, 'الزوج']]),
        ]);

        $husband = $this->rowPlan($id, 2);
        $wife = $this->rowPlan($id, 3);
        // wife_1 lives with him: Person + membership created in his Family.
        $this->assertSame(['CREATE', 'CREATE'], [$husband->intentOf(E::SPOUSE_1_PERSON)->value, $husband->intentOf(E::SPOUSE_1_MEMBERSHIP)->value]);
        // wife_2 heads her own row: her HEAD_PERSON owns the identity.
        $this->assertSame(['REUSE', 3, E::HEAD_PERSON], [$husband->intentOf(E::SPOUSE_2_PERSON)->value, $husband->effect(E::SPOUSE_2_PERSON)->ownerRow, $husband->effect(E::SPOUSE_2_PERSON)->ownerEffect]);
        $this->assertSame(['OMIT', 'INDEPENDENT_HOUSEHOLD_HEAD'], [$husband->intentOf(E::SPOUSE_2_MEMBERSHIP)->value, $husband->effect(E::SPOUSE_2_MEMBERSHIP)->reason]);
        // Her row names him back: his HEAD_PERSON owns him.
        $this->assertSame(['REUSE', 2, E::HEAD_PERSON], [$wife->intentOf(E::SPOUSE_1_PERSON)->value, $wife->effect(E::SPOUSE_1_PERSON)->ownerRow, $wife->effect(E::SPOUSE_1_PERSON)->ownerEffect]);
        $this->assertSame('OMIT', $wife->intentOf(E::SPOUSE_1_MEMBERSHIP)->value);
        $this->assertSame(['CREATE', 'CREATE'], [$wife->intentOf(E::HEAD_PERSON)->value, $husband->intentOf(E::HEAD_PERSON)->value]);
        $this->assertTrue($husband->executable() && $wife->executable());
        $this->assertSame(3, $this->plan($id)['counts']['persons']['create']);
    }

    public function test_an_existing_spouse_with_an_active_membership_is_reused_without_membership(): void
    {
        $id = $this->batch([2 => $this->row(['id' => 910000121], [[910000122, 'زوجة']])]);
        $spouse = Person::factory()->create(['national_id' => '910000122', 'gender' => 'FEMALE']);
        FamilyMembership::factory()->create(['person_id' => $spouse->id, 'family_id' => Family::factory()->create()->id, 'is_household_head' => false]);

        $plan = $this->rowPlan($id, 2);
        $this->assertSame(['REUSE', $spouse->id], [$plan->intentOf(E::SPOUSE_1_PERSON)->value, $plan->effect(E::SPOUSE_1_PERSON)->existingId]);
        $this->assertSame(['OMIT', 'PERSON_ALREADY_HAS_ACTIVE_MEMBERSHIP'], [$plan->intentOf(E::SPOUSE_1_MEMBERSHIP)->value, $plan->effect(E::SPOUSE_1_MEMBERSHIP)->reason]);
        $this->assertTrue($plan->executable());
    }

    public function test_a_spouse_name_without_national_id_is_never_created_or_matched(): void
    {
        Person::factory()->create(['full_name' => 'زوجة بلا هوية', 'national_id' => null]);
        $id = $this->batch([2 => $this->row(['id' => 910000131], [[null, 'زوجة بلا هوية']])]);

        $plan = $this->rowPlan($id, 2);
        $this->assertSame(['OMIT', 'OMIT', 'SPOUSE_WITHOUT_NATIONAL_ID'], [$plan->intentOf(E::SPOUSE_1_PERSON)->value, $plan->intentOf(E::SPOUSE_1_MEMBERSHIP)->value, $plan->effect(E::SPOUSE_1_PERSON)->reason]);
        $this->assertContains('SPOUSE_WITHOUT_NATIONAL_ID', $plan->warnings);
        $this->assertTrue($plan->executable());
        $this->assertSame(1, $this->plan($id)['counts']['spouse_slots']);
    }

    // ================================================== household

    public function test_declarations_are_kept_as_declared_and_no_children_are_invented(): void
    {
        $id = $this->batch([2 => $this->row(['id' => 910000141, 'size' => 3, 'sons' => 4, 'daughters' => 5])]);

        $decl = $this->rowPlan($id, 2)->effect(E::HOUSEHOLD_DECLARATION);
        $this->assertSame(['CREATE', 3, 4, 5, 'IMPORT', null], [$decl->intent->value, $decl->values['declared_household_size'], $decl->values['declared_living_sons'], $decl->values['declared_living_daughters'], $decl->values['source'], $decl->values['declared_at']]);
        $counts = $this->plan($id)['counts'];
        // Persons planned: the head only — never children for the declared counts.
        $this->assertSame([1, 1], [$counts['persons']['create'], $counts['effects']['declarations']['CREATE']]);
    }

    public function test_original_residence_is_the_source_city_only_and_omitted_when_missing(): void
    {
        $id = $this->batch([2 => $this->row(['id' => 910000151, 'city' => 'مدينة أصلية']), 3 => $this->row(['id' => 910000152, 'city' => null])]);

        $res = $this->rowPlan($id, 2)->effect(E::RESIDENCE);
        $this->assertSame(['CREATE', ['original_residence_text' => 'مدينة أصلية', 'source' => 'IMPORT', 'started_at' => ImportApplyPlanner::EXECUTION_DATE]], [$res->intent->value, $res->values]);
        $this->assertSame(['OMIT', 'NO_ORIGINAL_RESIDENCE'], [$this->rowPlan($id, 3)->intentOf(E::RESIDENCE)->value, $this->rowPlan($id, 3)->effect(E::RESIDENCE)->reason]);
    }

    public function test_family_uses_the_resolved_branch_or_none_and_never_reserves_a_code(): void
    {
        $branch = Branch::create(['clan_id' => $this->clan->id, 'code' => 'SYN_BR', 'name' => 'فرع تجريبي']);
        $id = $this->batch(
            [2 => $this->row(['id' => 910000161, 'key' => 'مفتاح مرتبط']), 3 => $this->row(['id' => 910000162, 'key' => 'مفتاح بلا فرع'])],
            decisions: ['مفتاح مرتبط' => ['decision' => 'MATCH_EXISTING_BRANCH', 'branch_id' => $branch->uuid]],
        );

        $linked = $this->rowPlan($id, 2)->effect(E::FAMILY);
        $this->assertSame([$this->clan->id, $branch->id, 'ACTIVE', 'IMPORT', ImportApplyPlanner::EXECUTION_DATE], [$linked->values['clan_id'], $linked->values['branch_id'], $linked->values['status'], $linked->values['registration_source'], $linked->values['registration_date']]);
        $this->assertNull($this->rowPlan($id, 3)->effect(E::FAMILY)->values['branch_id']);
        $this->assertArrayNotHasKey('family_code', $linked->values);
        $this->assertSame('فرع تجريبي', $this->apiRow($id, 2)['family']['branch']);
    }

    // ================================================== preconditions

    public function test_a_deactivated_target_branch_is_a_precondition_failure(): void
    {
        $branch = Branch::create(['clan_id' => $this->clan->id, 'code' => 'SYN_OFF', 'name' => 'فرع']);
        $id = $this->batch([2 => $this->row(['id' => 910000171, 'key' => 'مفتاح'])], decisions: ['مفتاح' => ['decision' => 'MATCH_EXISTING_BRANCH', 'branch_id' => $branch->uuid]]);
        $branch->update(['is_active' => false]);

        $this->assertContains('BRANCH_NOT_SELECTABLE', $this->codes($this->plan($id)['preconditions']));
        $this->assertSame(['BLOCK', 'BRANCH_NOT_SELECTABLE'], [$this->rowPlan($id, 2)->intentOf(E::FAMILY)->value, $this->rowPlan($id, 2)->effect(E::FAMILY)->reason]);
    }

    public function test_missing_or_inactive_relationship_types_are_precondition_failures(): void
    {
        $id = $this->batch([2 => $this->row(['id' => 910000181])]);

        RelationshipType::where('code', 'HEAD')->update(['is_active' => false]);
        $this->assertContains('RELATIONSHIP_TYPE_HEAD_UNAVAILABLE', $this->codes($this->plan($id)['preconditions']));
        RelationshipType::where('code', 'HEAD')->update(['is_active' => true]);
        RelationshipType::where('code', 'SPOUSE')->delete();
        $failures = $this->codes($this->plan($id)['preconditions']);
        $this->assertSame(['RELATIONSHIP_TYPE_SPOUSE_UNAVAILABLE'], $failures);
        $this->actingAs($this->admin)->getJson(self::BASE."/{$id}/dry-run/rows")->assertStatus(422);
    }

    public function test_a_stale_reconciliation_is_refused(): void
    {
        $id = $this->batch([2 => $this->row(['id' => 910000191])]);
        Person::factory()->create();

        $plan = $this->plan($id);
        $this->assertSame(['PRECONDITIONS_FAILED', ['RECONCILIATION_STALE'], null], [$plan['state'], $this->codes($plan['preconditions']), $plan['counts']]);
    }

    public function test_an_incremental_batch_is_not_planned(): void
    {
        $id = $this->batch([2 => $this->row(['id' => 910000201])], 'INCREMENTAL');

        $this->assertContains('BATCH_NOT_INITIAL', $this->codes($this->plan($id)['preconditions']));
    }

    public function test_a_started_apply_is_not_planned_again(): void
    {
        foreach ([ImportBatchStatus::PARTIALLY_APPLIED, ImportBatchStatus::APPLIED] as $n => $status) {
            $id = $this->batch([2 => $this->row(['id' => 910000210 + $n, 'key' => "مفتاح {$n}"])]);
            $this->model($id)->update(['status' => $status, 'apply_started_at' => now(), 'apply_plan_fingerprint' => hash('sha256', 'synthetic-plan'), 'applied_at' => now()]);

            $this->assertContains('APPLY_ALREADY_STARTED', $this->codes($this->plan($id)['preconditions']));
        }
    }

    // ================================================== API safety

    public function test_the_api_masks_ids_and_exposes_no_names(): void
    {
        $id = $this->batch([2 => $this->row(['id' => 910000221, 'name' => 'اسم سري للغاية'], [[910000222, 'اسم زوجة سري']])]);

        $json = $this->actingAs($this->admin)->getJson(self::BASE."/{$id}/dry-run/rows")->assertOk()->getContent().$this->actingAs($this->admin)->getJson(self::BASE."/{$id}/dry-run")->getContent();
        foreach (['910000221', '910000222', 'اسم سري للغاية', 'اسم زوجة سري', '0590000000', 'raw_payload'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $row = $this->apiRow($id, 2);
        $this->assertSame(['*****0221', '*****0222'], [$row['national_id_masked'], $row['spouses'][0]['national_id_masked']]);
    }

    public function test_the_dry_run_requires_import_review_and_not_import_apply(): void
    {
        $id = $this->batch([2 => $this->row(['id' => 910000231])]);
        $other = User::factory()->create();
        $other->assignRole('DATA_ENTRY');

        $this->actingAs($other)->getJson(self::BASE."/{$id}/dry-run")->assertForbidden();
        $this->actingAs($other)->getJson(self::BASE."/{$id}/dry-run/rows")->assertForbidden();
        $this->assertFalse($this->admin->can('import.apply'));
        $this->actingAs($this->admin)->getJson(self::BASE."/{$id}/dry-run")->assertOk();
    }

    public function test_row_filters_and_search(): void
    {
        $id = $this->batch([
            2 => $this->row(['id' => 910000241, 'life' => 'متوفى']),
            3 => $this->row(['id' => 910000242, 'key' => 'مفتاح خاص']),
        ]);
        $get = fn (string $q) => $this->actingAs($this->admin)->getJson(self::BASE."/{$id}/dry-run/rows?{$q}")->assertOk()->json();

        $this->assertSame([2], array_column($get('filter=warnings')['data'], 'row_number'));
        $this->assertSame([2], array_column($get('reason=HOUSEHOLD_HEAD_DECEASED')['data'], 'row_number'));
        $this->assertSame([3], array_column($get('search='.urlencode('خاص'))['data'], 'row_number'));
        $this->assertSame(0, $get('filter=blocked')['meta']['total']);
    }
}
