<?php

namespace Tests\Feature\Import;

use App\Actions\ApplyImportRowAction;
use App\Actions\CompleteImportApplyAction;
use App\Actions\RunImportApplyChunkAction;
use App\Actions\StartImportApplyAction;
use App\Enums\ImportApplyEffect as E;
use App\Enums\ImportApplyOutcome as O;
use App\Exceptions\ImportApplyExecutionException;
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
use App\Support\Import\Apply\ApplyChunkBudget;
use App\Support\Import\Apply\ApplyRunnerLock;
use App\Support\Import\Apply\ApprovedApplyPlan;
use App\Support\Import\Apply\ImportApplyProgress;
use App\Support\Import\Apply\ImportRowApplyResult;
use App\Support\Import\Apply\PostgresApplyRunnerLock;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\SyntheticXlsx;
use Tests\TestCase;

/**
 * Phase 4B.4c — the Apply runner: start / chunk / resume / completion
 * (docs/03 §96b). SYNTHETIC batches only. The runner orchestrates; the
 * planner decides and the row executor writes, one transaction per row.
 */
class RunImportApplyTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/imports/initial-families';

    private const HEADERS = [
        'هويتك', 'المفتاح', 'رقم الهوية', 'الاسم', 'الميلاد', 'الجنس', 'الحالة الاجتماعية', 'الديانة',
        'المدينة', 'حالة الوفاة', 'الوفاة', 'أفراد الأسرة', 'أبناءذكور احياء', 'أبناءإناث أحياء', 'الجوال',
        'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // The Apply activation gate is opened for this test only: the seeder
        // then grants import.apply to SUPER_ADMIN (and to no other role).
        config(['import.apply_enabled' => true]);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('SUPER_ADMIN');
        Clan::create(['code' => 'SYN_TARGET', 'name' => 'عشيرة الهدف']);
        Carbon::setTestNow('2026-09-20 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        FamilyResidence::flushEventListeners();
        parent::tearDown();
    }

    // ================================================== fixtures

    private function row(array $o = [], array $wives = []): array
    {
        $cells = [
            '555000111', $o['key'] ?? 'مفتاح أ', $o['id'], 'رب أسرة '.$o['id'], new DateTimeImmutable('1980-01-15'),
            $o['gender'] ?? 'ذكر', $o['marital'] ?? 'متزوج', 'قيمة-ديانة-اختبارية', 'مدينة أصلية', 'حي', null, 6, 2, 2, '0590000000',
        ];
        foreach (range(0, 3) as $k) {
            $cells[] = $wives[$k][0] ?? null;
            $cells[] = $wives[$k][1] ?? null;
        }

        return $cells;
    }

    private function batch(array $rows): ImportBatch
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
        $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/family-keys/bulk", ['decision' => 'NO_BRANCH', 'items' => array_map(fn ($k) => ['source_family_key' => $k], $keys)])->assertOk();
        $this->actingAs($this->admin)->postJson(self::BASE."/{$id}/reconcile")->assertOk();

        return ImportBatch::where('uuid', $id)->firstOrFail();
    }

    /** Five independent households, rows 2..6. */
    private function fiveRows(): ImportBatch
    {
        return $this->batch(array_combine(range(2, 6), array_map(fn ($i) => $this->row(['id' => 940000100 + $i], [[940000200 + $i, 'زوجة']]), range(2, 6))));
    }

    private function dryRun(ImportBatch $batch): string
    {
        return $this->actingAs($this->admin)->getJson(self::BASE."/{$batch->uuid}/dry-run")->assertOk()->json('data.plan_fingerprint');
    }

    private function start(ImportBatch $batch): ImportApplyProgress
    {
        return app(StartImportApplyAction::class)->handle($batch, $this->admin, $this->dryRun($batch));
    }

    private function chunk(ImportBatch $batch, int $maxRows = 100, ?\Closure $clock = null): ImportApplyProgress
    {
        return app(RunImportApplyChunkAction::class)->handle($batch, $this->admin, new ApplyChunkBudget($maxRows, 10.0, $clock));
    }

    private function applied(ImportBatch $batch): array
    {
        return ImportRow::where('import_batch_id', $batch->id)->where('status', 'APPLIED')->orderBy('row_number')->pluck('row_number')->all();
    }

    private function registry(): array
    {
        return [Person::withTrashed()->count(), Family::withTrashed()->count(), FamilyMembership::count(), FamilyResidence::count(),
            FamilyHouseholdDeclaration::count(), FamilyActivity::count(), ImportApplyRecord::count()];
    }

    private function expectCode(string $code, callable $run): void
    {
        try {
            $run();
            $this->fail("Expected {$code}.");
        } catch (ImportApplyExecutionException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    /** Residence creation fails on the $n-th attempt (1-based), then works again. */
    private function failResidenceOnAttempt(int $n): void
    {
        $count = 0;
        FamilyResidence::creating(function () use (&$count, $n) {
            if (++$count === $n) {
                throw new RuntimeException('synthetic failure with sensitive text 940000999');
            }
        });
    }

    // ================================================== start

    public function test_start_records_the_approved_plan_and_executes_no_row(): void
    {
        $batch = $this->fiveRows();
        $fingerprint = $this->dryRun($batch);
        $before = $this->registry();

        $progress = app(StartImportApplyAction::class)->handle($batch, $this->admin, $fingerprint);

        $batch->refresh();
        $this->assertSame(['APPLYING', 'STARTED', 5, 0, 5], [$batch->status->value, $progress->outcome, $progress->totalRows, $progress->appliedRows, $progress->remainingRows]);
        $this->assertSame(['2026-09-20 09:00:00', $this->admin->id, $fingerprint, null, null, null], [
            $batch->apply_started_at->toDateTimeString(), $batch->applied_by, $batch->apply_plan_fingerprint, $batch->apply_error_code, $batch->apply_error_row_number, $batch->applied_at,
        ]);
        $this->assertSame($before, $this->registry());
    }

    public function test_start_refusals_leave_the_batch_ready_for_review(): void
    {
        $batch = $this->fiveRows();
        $fingerprint = $this->dryRun($batch);

        $this->expectCode('APPLY_PLAN_CHANGED', fn () => app(StartImportApplyAction::class)->handle($batch, $this->admin, hash('sha256', 'another plan')));
        // A registry change after the Dry Run: the approved plan is no longer current.
        Person::factory()->create();
        $this->expectCode('APPLY_PRECONDITIONS_FAILED', fn () => app(StartImportApplyAction::class)->handle($batch, $this->admin, $fingerprint));

        $batch->refresh();
        $this->assertSame(['READY_FOR_REVIEW', null, null, null], [$batch->status->value, $batch->apply_started_at, $batch->apply_plan_fingerprint, $batch->applied_by]);
        $this->assertSame(0, Family::count());
    }

    public function test_unresolved_keys_blocked_plans_duplicates_and_unauthorized_users_cannot_start(): void
    {
        $unresolved = $this->batch([2 => $this->row(['id' => 940000301])]);
        $fp = $this->dryRun($unresolved);
        $this->actingAs($this->admin)->postJson(self::BASE."/{$unresolved->uuid}/family-keys/resolution/clear", ['source_family_key' => 'مفتاح أ'])->assertOk();
        $this->expectCode('APPLY_PRECONDITIONS_FAILED', fn () => app(StartImportApplyAction::class)->handle($unresolved, $this->admin, $fp));

        $blocked = $this->batch([2 => $this->row(['id' => 940000302, 'gender' => '', 'key' => 'ب'], [[940000303, 'زوج أو زوجة']])]);
        $this->expectCode('APPLY_PLAN_BLOCKED', fn () => app(StartImportApplyAction::class)->handle($blocked, $this->admin, $this->dryRun($blocked)));

        $ok = $this->batch([2 => $this->row(['id' => 940000304, 'key' => 'ج'])]);
        $fp = $this->dryRun($ok);
        app(StartImportApplyAction::class)->handle($ok, $this->admin, $fp);
        $this->expectCode('APPLY_STATE_INVALID', fn () => app(StartImportApplyAction::class)->handle($ok, $this->admin, $fp));

        // Only SUPER_ADMIN holds import.apply, and only while the gate is open.
        $administrator = User::factory()->create();
        $administrator->assignRole('ADMINISTRATOR');
        $this->expectException(AuthorizationException::class);
        app(StartImportApplyAction::class)->handle($unresolved, $administrator, $fp);
    }

    // ================================================== chunks

    public function test_chunks_run_in_source_order_pause_cleanly_and_complete(): void
    {
        $batch = $this->fiveRows();
        $this->start($batch);

        $first = $this->chunk($batch, 2);
        $this->assertSame([2, 3], $this->applied($batch));
        $this->assertSame(['PAUSED', 'APPLYING', 2, 2, 3, false, null], [$first->outcome, $first->status, $first->executedInChunk, $first->appliedRows, $first->remainingRows, $first->completed(), $first->errorCode]);
        $this->assertNull($batch->fresh()->applied_at);

        // A normal pause: a later chunk continues where it stopped.
        Carbon::setTestNow('2026-09-22 16:00:00');
        $second = $this->chunk($batch, 2);
        $this->assertSame([2, 3, 4, 5], $this->applied($batch));
        $this->assertSame([2, 1], [$second->executedInChunk, $second->remainingRows]);

        $last = $this->chunk($batch, 2);
        $batch->refresh();
        $this->assertSame(['COMPLETED', 'APPLIED', true, 0, 0], [$last->outcome, $batch->status->value, $last->completed(), $last->remainingRows, $last->pendingLinks]);
        $this->assertSame('2026-09-22 16:00:00', $batch->applied_at->toDateTimeString());
        // Every Family carries the Apply START date, whatever day its chunk ran.
        $this->assertSame(['2026-09-20'], Family::pluck('registration_date')->map(fn ($d) => $d->toDateString())->unique()->values()->all());
        $this->assertSame(['2026-09-20'], FamilyResidence::pluck('started_at')->map(fn ($d) => $d->toDateString())->unique()->values()->all());
        $this->assertSame([5, 10, 10], [Family::count(), Person::count(), FamilyMembership::count()]);
    }

    public function test_the_time_budget_stops_between_rows(): void
    {
        $batch = $this->fiveRows();
        $this->start($batch);
        $ticks = [0.0, 0.0, 11.0];
        $clock = function () use (&$ticks): float {
            return array_shift($ticks) ?? 99.0;
        };

        $progress = $this->chunk($batch, 100, $clock);

        $this->assertSame([[2], 'PAUSED', 'APPLYING'], [$this->applied($batch), $progress->outcome, $batch->fresh()->status->value]);
    }

    public function test_every_row_goes_through_the_row_executor(): void
    {
        $batch = $this->fiveRows();
        $this->start($batch);
        $calls = new \stdClass;
        $calls->count = 0;
        $this->app->bind(ApplyImportRowAction::class, fn () => new class($calls) extends ApplyImportRowAction
        {
            public function __construct(private \stdClass $calls)
            {
                parent::__construct();
            }

            public function handle(ApprovedApplyPlan $plan, ImportRow $row, User $user): ImportRowApplyResult
            {
                $this->calls->count++;

                return parent::handle($plan, $row, $user);
            }
        });

        $this->chunk($batch, 3);

        // Three rows, three executor calls, three Families — no other write path.
        $this->assertSame([3, 3], [$calls->count, Family::count()]);
    }

    // ================================================== failures

    public function test_a_failure_before_any_committed_row_returns_to_ready_for_review(): void
    {
        $batch = $this->fiveRows();
        $this->start($batch);
        $this->failResidenceOnAttempt(1);

        $progress = $this->chunk($batch);

        $batch->refresh();
        $this->assertSame(['FAILED', 'READY_FOR_REVIEW', 'UNEXPECTED_ERROR', 2], [$progress->outcome, $batch->status->value, $batch->apply_error_code, $batch->apply_error_row_number]);
        $this->assertSame([null, null, null, null], [$batch->apply_started_at, $batch->apply_plan_fingerprint, $batch->applied_by, $batch->applied_at]);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $this->registry());
        // Nothing prevents a fresh, reviewed start afterwards.
        FamilyResidence::flushEventListeners();
        $this->assertSame('APPLYING', $this->start($batch)->status);
    }

    public function test_a_failure_after_committed_rows_is_partially_applied_with_a_safe_code(): void
    {
        $batch = $this->fiveRows();
        $this->start($batch);
        $startedAt = $batch->fresh()->apply_started_at->toDateTimeString();
        $this->failResidenceOnAttempt(3);

        $progress = $this->chunk($batch);

        $batch->refresh();
        $this->assertSame([[2, 3], 'FAILED', 'PARTIALLY_APPLIED'], [$this->applied($batch), $progress->outcome, $batch->status->value]);
        $this->assertSame(['UNEXPECTED_ERROR', 4, $startedAt], [$batch->apply_error_code, $batch->apply_error_row_number, $batch->apply_started_at->toDateTimeString()]);
        $this->assertNotNull($batch->apply_plan_fingerprint);
        // Earlier rows stay committed; later rows never ran; no raw text stored.
        $this->assertSame([2, 0], [Family::count(), ImportRow::where('import_batch_id', $batch->id)->whereIn('row_number', [5, 6])->whereNotNull('family_id')->count()]);
        $this->assertStringNotContainsString('940000999', json_encode($batch->fresh()->toArray()).json_encode($progress->toArray()));
        // A PARTIALLY_APPLIED batch must be resumed explicitly.
        $this->expectCode('APPLY_STATE_INVALID', fn () => $this->chunk($batch));
    }

    // ================================================== resume

    public function test_resume_continues_with_the_same_start_and_plan(): void
    {
        $batch = $this->fiveRows();
        $this->start($batch);
        $original = $batch->fresh()->only(['apply_started_at', 'apply_plan_fingerprint', 'applied_by']);
        $this->failResidenceOnAttempt(2);
        $this->chunk($batch);
        FamilyResidence::flushEventListeners();

        Carbon::setTestNow('2026-09-25 08:00:00');
        $progress = app(RunImportApplyChunkAction::class)->resume($batch, $this->admin, new ApplyChunkBudget(2));
        $this->assertSame(['PAUSED', [2, 3, 4], null], [$progress->outcome, $this->applied($batch), $batch->fresh()->apply_error_code]);
        $this->assertEquals($original, $batch->fresh()->only(array_keys($original)));

        $this->chunk($batch);
        $this->assertSame('APPLIED', $batch->fresh()->status->value);
        $this->assertSame(['2026-09-20'], Family::pluck('registration_date')->map(fn ($d) => $d->toDateString())->unique()->values()->all());
    }

    public function test_resume_is_refused_when_the_plan_changed_or_provenance_is_impossible(): void
    {
        $batch = $this->fiveRows();
        $this->start($batch);
        $this->failResidenceOnAttempt(2);
        $this->chunk($batch);
        FamilyResidence::flushEventListeners();

        RelationshipType::where('code', 'SPOUSE')->update(['is_active' => false]);
        $this->expectCode('APPLY_PLAN_CHANGED', fn () => app(RunImportApplyChunkAction::class)->resume($batch, $this->admin));
        $this->assertSame(['PARTIALLY_APPLIED', 'APPLY_PLAN_CHANGED'], [$batch->fresh()->status->value, $batch->fresh()->apply_error_code]);
        RelationshipType::where('code', 'SPOUSE')->update(['is_active' => true]);

        // An applied row missing a mandatory record is never repaired.
        DB::table('import_apply_records')->where('import_row_id', ImportRow::where('import_batch_id', $batch->id)->where('row_number', 2)->value('id'))->where('effect_key', 'RESIDENCE')->delete();
        $this->expectCode('APPLY_INTEGRITY_INVALID', fn () => app(RunImportApplyChunkAction::class)->resume($batch, $this->admin));
        $this->assertSame('PARTIALLY_APPLIED', $batch->fresh()->status->value);
        $this->expectCode('APPLY_STATE_INVALID', fn () => app(RunImportApplyChunkAction::class)->resume($this->fiveRowsStarted(), $this->admin));
    }

    private function fiveRowsStarted(): ImportBatch
    {
        $batch = $this->batch(array_combine(range(2, 3), array_map(fn ($i) => $this->row(['id' => 940000400 + $i, 'key' => 'مفتاح آخر']), range(2, 3))));
        $this->start($batch);

        return $batch;
    }

    // ================================================== cross-row

    private function polygamyBatch(): ImportBatch
    {
        return $this->batch([
            2 => $this->row(['id' => 940000501, 'marital' => 'متعدد الزوجات'], [[940000502, 'زوجة أولى'], [940000503, 'زوجة ثانية']]),
            3 => $this->row(['id' => 940000503, 'gender' => 'أنثى', 'marital' => 'متزوجة', 'key' => 'ب'], [[940000501, 'الزوج']]),
        ]);
    }

    public function test_a_pending_link_survives_a_pause_and_its_owner_completes_it_in_a_later_chunk(): void
    {
        $batch = $this->polygamyBatch();
        $this->start($batch);

        $first = $this->chunk($batch, 1);
        $this->assertSame(['PAUSED', 1], [$first->outcome, $first->pendingLinks]);

        $second = $this->chunk($batch, 1);
        $this->assertSame(['COMPLETED', 0], [$second->outcome, $second->pendingLinks]);
        $link = ImportApplyRecord::where('import_row_id', ImportRow::where('import_batch_id', $batch->id)->where('row_number', 2)->value('id'))->where('effect_key', 'SPOUSE_2_PERSON')->sole();
        $this->assertSame(['REUSED', Person::where('national_id', '940000503')->value('id')], [$link->outcome->value, $link->entity_id]);
    }

    // ================================================== completion

    /** All rows executed by the row executor, batch still APPLYING (completion not yet run). */
    private function executedNotCompleted(ImportBatch $batch): void
    {
        $this->start($batch);
        $plan = app(CompleteImportApplyAction::class)->approvedPlan($batch->fresh());
        foreach (ImportRow::where('import_batch_id', $batch->id)->orderBy('row_number')->get() as $row) {
            app(ApplyImportRowAction::class)->handle($plan, $row, $this->admin);
        }
        $this->assertSame('APPLYING', $batch->fresh()->status->value);
    }

    private function assertCompletionRefused(ImportBatch $batch, string $code = 'APPLY_COMPLETION_INCOMPLETE'): void
    {
        $this->expectCode($code, fn () => app(CompleteImportApplyAction::class)->handle($batch->fresh()));
        $this->assertSame(['APPLYING', null], [$batch->fresh()->status->value, $batch->fresh()->applied_at]);
    }

    private function rowId(ImportBatch $batch, int $n): int
    {
        return ImportRow::where('import_batch_id', $batch->id)->where('row_number', $n)->value('id');
    }

    public function test_completion_verifies_the_database_and_never_invents_provenance(): void
    {
        $batch = $this->polygamyBatch();
        $this->executedNotCompleted($batch);
        $link = fn () => DB::table('import_apply_records')->where('import_row_id', $this->rowId($batch, 2))->where('effect_key', 'SPOUSE_2_PERSON');
        $saved = (array) $link()->first();

        // A remaining cross-row link is refused, not manufactured.
        $link()->delete();
        $this->assertCompletionRefused($batch);
        $this->assertSame(0, $link()->count());
        DB::table('import_apply_records')->insert($saved);

        app(CompleteImportApplyAction::class)->handle($batch->fresh());
        $this->assertSame('APPLIED', $batch->fresh()->status->value);
    }

    public function test_completion_refuses_missing_or_wrong_records_and_families(): void
    {
        $cases = [
            'missing family' => fn (ImportBatch $b) => Family::whereKey(ImportRow::whereKey($this->rowId($b, 2))->value('family_id'))->first()->delete(),
            'missing provenance' => fn (ImportBatch $b) => DB::table('import_apply_records')->where('import_row_id', $this->rowId($b, 2))->where('effect_key', 'RESIDENCE')->delete(),
            'wrong outcome' => fn (ImportBatch $b) => DB::table('import_apply_records')->where('import_row_id', $this->rowId($b, 2))->where('effect_key', 'RESIDENCE')->update(['outcome' => 'REUSED']),
            'unexpected provenance' => fn (ImportBatch $b) => ImportApplyRecord::record(ImportRow::findOrFail($this->rowId($b, 2)), E::SPOUSE_4_PERSON, O::OMITTED, null, 'SYNTHETIC_EXTRA', $this->admin->id),
        ];
        foreach ($cases as $label => $tamper) {
            $batch = $this->batch([2 => $this->row(['id' => 940000600 + crc32($label) % 1000, 'key' => $label])]);
            $this->executedNotCompleted($batch);
            $tamper($batch);
            $this->assertCompletionRefused($batch);
        }

        $batch = $this->batch([2 => $this->row(['id' => 940000701, 'key' => 'fingerprint'])]);
        $this->executedNotCompleted($batch);
        DB::table('import_batches')->where('id', $batch->id)->update(['apply_plan_fingerprint' => hash('sha256', 'not the approved plan')]);
        $this->assertCompletionRefused($batch, 'APPLY_PLAN_CHANGED');
    }

    public function test_an_applied_batch_is_reverified_never_re_executed(): void
    {
        $batch = $this->fiveRows();
        $this->start($batch);
        $this->chunk($batch);
        $after = $this->registry();

        $again = $this->chunk($batch);
        $this->assertSame(['ALREADY_APPLIED', 'APPLIED', 0], [$again->outcome, $again->status, $again->executedInChunk]);
        $this->assertSame($after, $this->registry());

        DB::table('import_apply_records')->where('import_row_id', $this->rowId($batch, 3))->where('effect_key', 'FAMILY')->delete();
        $this->expectCode('APPLY_INTEGRITY_INVALID', fn () => $this->chunk($batch));
    }

    // ================================================== lock and progress

    /**
     * The lock as ANOTHER runner holds it. PostgreSQL session advisory locks
     * are re-entrant within one session, so there the other runner is a second
     * database session; the in-process SQLite lock is the shared singleton.
     */
    private function otherRunnersLock(): ApplyRunnerLock
    {
        if (DB::getDriverName() !== 'pgsql') {
            return app(ApplyRunnerLock::class);
        }
        config(['database.connections.other_runner' => config('database.connections.pgsql')]);

        return new PostgresApplyRunnerLock('other_runner');
    }

    public function test_one_runner_per_batch_and_the_lock_is_always_released(): void
    {
        $batch = $this->fiveRows();
        $this->start($batch);
        $lock = $this->otherRunnersLock();

        $this->assertTrue($lock->acquire($batch->id));
        $this->expectCode('APPLY_IN_PROGRESS', fn () => $this->chunk($batch, 1));
        $this->assertSame([], $this->applied($batch));
        $lock->release($batch->id);

        $this->chunk($batch, 1); // success releases
        $this->assertTrue($lock->acquire($batch->id));
        $lock->release($batch->id);

        $ready = $this->batch([2 => $this->row(['id' => 940000801, 'key' => 'ب'])]);
        $this->expectCode('APPLY_STATE_INVALID', fn () => $this->chunk($ready)); // an exception releases too
        $this->assertTrue($lock->acquire($ready->id));
        $lock->release($ready->id);
    }

    public function test_progress_comes_from_the_database_and_carries_no_personal_data(): void
    {
        $batch = $this->fiveRows();
        $this->start($batch);
        $progress = $this->chunk($batch, 3);

        $rows = ImportRow::where('import_batch_id', $batch->id);
        $this->assertSame([(clone $rows)->count(), (clone $rows)->where('status', 'APPLIED')->count(), (clone $rows)->where('status', '!=', 'APPLIED')->count()], [$progress->totalRows, $progress->appliedRows, $progress->remainingRows]);
        $json = json_encode($progress->toArray());
        foreach (['940000102', 'رب أسرة', 'زوجة', $batch->fresh()->apply_plan_fingerprint] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame(['outcome', 'status', 'total_rows', 'applied_rows', 'remaining_rows', 'executed_in_chunk', 'skipped_already_applied', 'pending_links', 'completed', 'error_code', 'error_row_number'], array_keys($progress->toArray()));
    }
}
