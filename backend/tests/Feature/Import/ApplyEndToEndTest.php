<?php

namespace Tests\Feature\Import;

use App\Actions\RunImportApplyChunkAction;
use App\Actions\StartImportApplyAction;
use App\Models\Clan;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyHouseholdDeclaration;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\ImportApplyRecord;
use App\Models\ImportBatch;
use App\Models\Person;
use App\Models\User;
use App\Support\Import\Apply\ApplyChunkBudget;
use App\Support\Import\Apply\ImportApplyPlanner;
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
 * A small synthetic INITIAL import, end to end (docs/03 §96b): Dry Run →
 * Start → chunks → completion, and the final database must match the plan
 * exactly. Runs on SQLite and — with phpunit.pgsql.xml — on PostgreSQL.
 */
class ApplyEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/imports/initial-families';

    private const HEADERS = [
        'هويتك', 'المفتاح', 'رقم الهوية', 'الاسم', 'الميلاد', 'الجنس', 'الحالة الاجتماعية', 'الديانة',
        'المدينة', 'حالة الوفاة', 'الوفاة', 'أفراد الأسرة', 'أبناءذكور احياء', 'أبناءإناث أحياء', 'الجوال',
        'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة',
    ];

    private function row(array $o, array $wives = []): array
    {
        $cells = [
            '555000111', $o['key'] ?? 'مفتاح أ', $o['id'], 'رب أسرة '.$o['id'], new DateTimeImmutable('1980-01-15'),
            $o['gender'] ?? 'ذكر', $o['marital'] ?? 'متزوج', 'قيمة-ديانة-اختبارية',
            array_key_exists('city', $o) ? $o['city'] : 'مدينة أصلية', $o['life'] ?? 'حي', $o['death'] ?? null, 6, 2, 2, '0590000000',
        ];
        foreach (range(0, 3) as $k) {
            $cells[] = $wives[$k][0] ?? null;
            $cells[] = $wives[$k][1] ?? null;
        }

        return $cells;
    }

    public function test_a_synthetic_initial_import_applies_exactly_as_planned(): void
    {
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('SUPER_ADMIN');
        $admin->givePermissionTo('import.apply');
        Clan::create(['code' => 'SYN_TARGET', 'name' => 'عشيرة الهدف']);

        $path = SyntheticXlsx::write(['Sheet1' => [1 => self::HEADERS] + [
            2 => $this->row(['id' => 960000021], [[960000022, 'زوجة']]),                                   // male head + wife
            3 => $this->row(['id' => 960000031, 'gender' => 'أنثى', 'marital' => 'متزوجة'], [[960000032, 'زوج']]), // female head + husband
            4 => $this->row(['id' => 960000041, 'life' => 'متوفى', 'death' => new DateTimeImmutable('2021-03-01')], [[960000042, 'زوجة']]), // deceased head
            5 => $this->row(['id' => 960000051, 'marital' => 'متعدد الزوجات'], [[960000052, 'زوجة أولى'], [960000061, 'زوجة ثانية']]), // polygamous
            6 => $this->row(['id' => 960000061, 'gender' => 'أنثى', 'marital' => 'متزوجة', 'key' => 'ب'], [[960000051, 'الزوج']]), // her own household
            7 => $this->row(['id' => 960000071, 'city' => null]),                                             // no original city
            8 => $this->row(['id' => 960000081], [[null, 'زوجة بلا هوية']]),                                  // spouse without ID
        ]]);
        $id = $this->actingAs($admin)->post(self::BASE, ['clan_code' => 'SYN_TARGET', 'import_mode' => 'INITIAL', 'file' => new UploadedFile($path, 'synthetic.xlsx', null, null, true)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $fields = [];
        $ignored = [];
        foreach ($this->actingAs($admin)->getJson(self::BASE."/{$id}/columns")->json('data.columns') as $c) {
            $c['suggested_field'] ? $fields[$c['suggested_field']] = $c['letter'] : $ignored[] = $c['letter'];
        }
        $this->actingAs($admin)->postJson(self::BASE."/{$id}/mapping", ['mapping' => $fields, 'ignored' => $ignored])->assertOk();
        $keys = array_column($this->actingAs($admin)->getJson(self::BASE."/{$id}/family-keys")->json('data'), 'key');
        $this->actingAs($admin)->postJson(self::BASE."/{$id}/family-keys/bulk", ['decision' => 'NO_BRANCH', 'items' => array_map(fn ($k) => ['source_family_key' => $k], $keys)])->assertOk();
        $this->actingAs($admin)->postJson(self::BASE."/{$id}/reconcile")->assertOk();
        $batch = ImportBatch::where('uuid', $id)->firstOrFail();

        // Dry Run → the operator's approval.
        $dry = $this->actingAs($admin)->getJson(self::BASE."/{$id}/dry-run")->assertOk()->json('data');
        $this->assertSame('READY', $dry['state']);
        $counts = $dry['counts'];
        $plan = app(ImportApplyPlanner::class)->plan($batch);
        $plannedEffects = array_sum(array_map(fn ($r) => count($r->effects), $plan->rows));

        // Start → chunks of 3 → completion.
        app(StartImportApplyAction::class)->handle($batch, $admin, $dry['plan_fingerprint']);
        $outcomes = [];
        for ($i = 0; $i < 10 && $batch->fresh()->status->value !== 'APPLIED'; $i++) {
            $outcomes[] = app(RunImportApplyChunkAction::class)->handle($batch, $admin, new ApplyChunkBudget(3))->outcome;
        }
        $this->assertSame(['PAUSED', 'PAUSED', 'COMPLETED'], $outcomes);
        $batch->refresh();
        $this->assertSame(['APPLIED', null], [$batch->status->value, $batch->apply_error_code]);

        // The database equals the plan.
        $this->assertSame($counts['effects']['families']['CREATE'], Family::count());
        $this->assertSame($counts['persons']['create'], Person::count());
        $this->assertSame($counts['effects']['head_memberships']['CREATE'] + $counts['effects']['spouse_memberships']['CREATE'], FamilyMembership::count());
        $this->assertSame($counts['effects']['declarations']['CREATE'], FamilyHouseholdDeclaration::count());
        $this->assertSame($counts['effects']['residences']['CREATE'], FamilyResidence::count());
        $this->assertSame($plannedEffects, ImportApplyRecord::count());
        $byOutcome = ImportApplyRecord::query()->selectRaw('outcome, count(*) AS n')->groupBy('outcome')->pluck('n', 'outcome')->map(fn ($n) => (int) $n)->all();
        ksort($byOutcome);
        $expected = ['CREATED' => 0, 'OMITTED' => 0, 'REUSED' => 0];
        foreach ($counts['effects'] as $intents) {
            $expected['CREATED'] += $intents['CREATE'];
            $expected['REUSED'] += $intents['REUSE'];
            $expected['OMITTED'] += $intents['OMIT'];
        }
        $this->assertSame(array_filter($expected), $byOutcome);
        $events = FamilyActivity::query()->selectRaw('event_type, count(*) AS n')->groupBy('event_type')->pluck('n', 'event_type')->map(fn ($n) => (int) $n)->all();
        $families = $counts['effects']['families']['CREATE'];
        $this->assertSame([$families, $families, $counts['effects']['declarations']['CREATE']], [$events['FAMILY_CREATED'], $events['FAMILY_IMPORTED'], $events['HOUSEHOLD_DECLARATION_RECORDED']]);

        // Identity: one Person per National ID; semantics as planned.
        $this->assertSame(0, DB::table('persons')->whereNotNull('national_id')->selectRaw('national_id')->groupBy('national_id')->havingRaw('count(*) > 1')->count());
        $this->assertSame('MALE', Person::where('national_id', '960000032')->sole()->gender->value); // her husband
        $this->assertSame('DECEASED', Person::where('national_id', '960000041')->sole()->life_status->value);
        $this->assertSame([7, 1], [Family::whereDate('registration_date', $batch->apply_started_at->toDateString())->count(), Person::where('national_id', '960000061')->count()]);
        $this->assertSame(0, Family::whereNotNull('branch_id')->count()); // NO_BRANCH
    }
}
