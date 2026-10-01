<?php

namespace Tests\Feature\Import;

use App\Actions\StartImportApplyAction;
use App\Models\Clan;
use App\Models\Family;
use App\Models\FamilyResidence;
use App\Models\ImportApplyRecord;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Person;
use App\Models\User;
use App\Support\Import\Apply\ApplyRunnerLock;
use App\Support\Import\Apply\ImportApplyProgress;
use App\Support\Import\Apply\PostgresApplyRunnerLock;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SyntheticXlsx;
use Tests\TestCase;

/**
 * Phase 4B.4d — the Apply API (docs/03 §96b, docs/06 §61): thin endpoints
 * around the runner, the activation gate (config import.apply_enabled,
 * SUPER_ADMIN only), HTTP/error mapping and privacy of responses and logs.
 * SYNTHETIC batches only.
 */
class ApplyApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/imports/initial-families';

    private const HEADERS = [
        'هويتك', 'المفتاح', 'رقم الهوية', 'الاسم', 'الميلاد', 'الجنس', 'الحالة الاجتماعية', 'الديانة',
        'المدينة', 'حالة الوفاة', 'الوفاة', 'أفراد الأسرة', 'أبناءذكور احياء', 'أبناءإناث أحياء', 'الجوال',
        'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة', 'هوية الزوجة', 'الزوجة',
    ];

    /** Identity data that must never leave the server through Apply. */
    private const SECRETS = ['940000102', '940000202', 'رب أسرة', 'زوجة', '940000999', 'SQLSTATE'];

    private User $admin;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class); // gate closed: nobody holds import.apply
        $this->seed(RelationshipTypeSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('SUPER_ADMIN');
        Clan::create(['code' => 'SYN_TARGET', 'name' => 'عشيرة الهدف']);
        Carbon::setTestNow('2026-09-20 09:00:00');
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e;
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        FamilyResidence::flushEventListeners();
        parent::tearDown();
    }

    // ================================================== fixtures

    /** Opens the activation gate the documented way: config + RolePermissionSeeder. */
    private function openGate(): void
    {
        config(['import.apply_enabled' => true]);
        $this->seed(RolePermissionSeeder::class);
    }

    private function row(int $id, int $wife, string $key = 'مفتاح أ', string $gender = 'ذكر', string $wifeName = 'زوجة'): array
    {
        $cells = ['555000111', $key, $id, 'رب أسرة '.$id, new DateTimeImmutable('1980-01-15'), $gender, 'متزوج', 'قيمة-ديانة-اختبارية',
            'مدينة أصلية', 'حي', null, 6, 2, 2, '0590000000', $wife, $wifeName];

        return [...$cells, ...array_fill(0, 6, null)];
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
        return $this->batch(array_combine(range(2, 6), array_map(fn ($i) => $this->row(940000100 + $i, 940000200 + $i), range(2, 6))));
    }

    private function fingerprint(ImportBatch $batch): string
    {
        return $this->actingAs($this->admin)->getJson(self::BASE."/{$batch->uuid}/dry-run")->assertOk()->json('data.plan_fingerprint');
    }

    private function applyStep(ImportBatch $batch, string $step, array $body = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->postJson(self::BASE."/{$batch->uuid}/apply/{$step}", $body);
    }

    private function started(): ImportBatch
    {
        $batch = $this->fiveRows();
        $this->applyStep($batch, 'start', ['plan_fingerprint' => $this->fingerprint($batch)])->assertOk();

        return $batch;
    }

    private function failResidenceOnAttempt(int $n): void
    {
        $count = 0;
        FamilyResidence::creating(function () use (&$count, $n) {
            if (++$count === $n) {
                throw new RuntimeException('SQLSTATE[23505] synthetic failure with sensitive text 940000999');
            }
        });
    }

    private function registry(): array
    {
        return [Person::withTrashed()->count(), Family::withTrashed()->count(), ImportApplyRecord::count()];
    }

    private function batchState(ImportBatch $batch): array
    {
        return $batch->fresh()->only(['status', 'apply_started_at', 'apply_plan_fingerprint', 'applied_by', 'applied_at', 'apply_error_code', 'apply_error_row_number']);
    }

    private function assertNothingSensitive(string $text, ?ImportBatch $batch = null): void
    {
        foreach ([...self::SECRETS, ...($batch?->fresh()->apply_plan_fingerprint ? [$batch->fresh()->apply_plan_fingerprint] : [])] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    private function logText(): string
    {
        return json_encode(array_map(fn (MessageLogged $e) => [$e->level, $e->message, $e->context], $this->logged), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    // ================================================== activation gate & authorization

    public function test_with_the_gate_closed_nobody_can_apply_not_even_super_admin(): void
    {
        $this->assertFalse(config('import.apply_enabled'));
        $batch = $this->fiveRows();
        $fingerprint = $this->fingerprint($batch);
        $before = $this->batchState($batch);

        $this->applyStep($batch, 'start', ['plan_fingerprint' => $fingerprint])->assertForbidden();
        $this->applyStep($batch, 'run')->assertForbidden();
        $this->applyStep($batch, 'resume')->assertForbidden();

        // A direct grant (never part of the baseline) still cannot run Apply
        // while the gate is closed: the runtime gate is checked too.
        $direct = User::factory()->create();
        $direct->assignRole('SUPER_ADMIN');
        $direct->givePermissionTo('import.apply');
        $this->applyStep($batch, 'start', ['plan_fingerprint' => $fingerprint], $direct)->assertForbidden();
        $this->expectException(AuthorizationException::class);
        try {
            app(StartImportApplyAction::class)->handle($batch, $direct, $fingerprint);
        } finally {
            $this->assertEquals($before, $this->batchState($batch));
            $this->assertSame([0, 0, 0], $this->registry());
        }
    }

    public function test_with_the_gate_open_only_super_admin_may_apply(): void
    {
        $this->openGate();
        $batch = $this->fiveRows();
        $fingerprint = $this->fingerprint($batch);
        $before = $this->batchState($batch);

        foreach (array_diff(RolePermissionSeeder::ROLES, ['SUPER_ADMIN']) as $roleName) {
            $user = User::factory()->create();
            $user->assignRole($roleName);
            $this->assertFalse($user->can('import.apply'), $roleName);
            $this->applyStep($batch, 'start', ['plan_fingerprint' => $fingerprint], $user)->assertForbidden();
            $this->applyStep($batch, 'run', [], $user)->assertForbidden();
            $this->applyStep($batch, 'resume', [], $user)->assertForbidden();
        }
        $this->assertEquals($before, $this->batchState($batch));

        // Unauthenticated.
        $this->app['auth']->forgetGuards();
        $this->postJson(self::BASE."/{$batch->uuid}/apply/start", ['plan_fingerprint' => $fingerprint])->assertUnauthorized();
        $this->getJson(self::BASE."/{$batch->uuid}/apply")->assertUnauthorized();

        $this->applyStep($batch, 'start', ['plan_fingerprint' => $fingerprint])->assertOk();
    }

    public function test_progress_is_read_with_import_review_never_import_apply(): void
    {
        $batch = $this->fiveRows();

        // Gate closed: a reviewer (SUPER_ADMIN, import.review) reads progress.
        $this->actingAs($this->admin)->getJson(self::BASE."/{$batch->uuid}/apply")->assertOk()
            ->assertJsonPath('data.batch_id', $batch->uuid)
            ->assertJsonPath('data.status', 'READY_FOR_REVIEW')
            ->assertJsonPath('data.total_rows', 5);

        $administrator = User::factory()->create();
        $administrator->assignRole('ADMINISTRATOR');
        $this->actingAs($administrator)->getJson(self::BASE."/{$batch->uuid}/apply")->assertForbidden();
    }

    // ================================================== start

    public function test_start_takes_only_a_well_formed_plan_fingerprint(): void
    {
        $this->openGate();
        $batch = $this->fiveRows();
        $fingerprint = $this->fingerprint($batch);

        foreach ([[], ['plan_fingerprint' => ''], ['plan_fingerprint' => strtoupper($fingerprint)], ['plan_fingerprint' => substr($fingerprint, 1)],
            ['plan_fingerprint' => $fingerprint.'0'], ['plan_fingerprint' => [$fingerprint]], ['plan_fingerprint' => str_repeat('g', 64)]] as $body) {
            $this->applyStep($batch, 'start', $body)->assertUnprocessable()->assertJsonValidationErrors('plan_fingerprint');
        }
        $this->assertSame('READY_FOR_REVIEW', $batch->fresh()->status->value);

        // Execution controls in the body are ignored, never honoured.
        $response = $this->applyStep($batch, 'start', ['plan_fingerprint' => $fingerprint, 'rows' => [2], 'max_rows' => 1, 'apply_started_at' => '2020-01-01'])->assertOk();
        $this->assertSame(['STARTED', 'APPLYING', 0, 0, 5], [$response->json('data.outcome'), $response->json('data.status'), $response->json('data.executed_in_chunk'), $response->json('data.applied_rows'), $response->json('data.remaining_rows')]);
        $this->assertSame('2026-09-20', $batch->fresh()->apply_started_at->toDateString());
        $this->assertSame([0, 0, 0], $this->registry());
        $this->assertSame($batch->uuid, $response->json('data.batch_id'));
        $this->assertNothingSensitive($response->getContent(), $batch);

        $run = $this->applyStep($batch, 'run', ['max_rows' => 1, 'rows' => [2]])->assertOk();
        $this->assertSame(['APPLIED', 5, true], [$run->json('data.status'), $run->json('data.executed_in_chunk'), $run->json('data.completed')]);
    }

    public function test_start_refusals_map_to_safe_codes_and_leave_the_batch_ready_for_review(): void
    {
        $this->openGate();
        $batch = $this->fiveRows();
        $fingerprint = $this->fingerprint($batch);

        $this->applyStep($batch, 'start', ['plan_fingerprint' => str_repeat('a', 64)])->assertStatus(409)
            ->assertExactJson(['message' => 'تغيّرت خطة الاعتماد منذ المعاينة. أعد المعاينة قبل البدء.', 'code' => 'APPLY_PLAN_CHANGED', 'row_number' => null]);
        $this->assertSame([null, null], [$batch->fresh()->apply_started_at, $batch->fresh()->apply_plan_fingerprint]);

        $this->applyStep($batch, 'start', ['plan_fingerprint' => $fingerprint])->assertOk();
        $this->applyStep($batch, 'start', ['plan_fingerprint' => $fingerprint])->assertStatus(409)->assertJsonPath('code', 'APPLY_STATE_INVALID');

        $blocked = $this->batch([2 => $this->row(940000302, 940000303, 'ب', '', 'زوج أو زوجة')]);
        $this->applyStep($blocked, 'start', ['plan_fingerprint' => $this->fingerprint($blocked)])->assertStatus(422)->assertJsonPath('code', 'APPLY_PLAN_BLOCKED');

        $unresolved = $this->batch([2 => $this->row(940000301, 940000304, 'ج')]);
        $fp = $this->fingerprint($unresolved);
        $this->actingAs($this->admin)->postJson(self::BASE."/{$unresolved->uuid}/family-keys/resolution/clear", ['source_family_key' => 'ج'])->assertOk();
        $this->applyStep($unresolved, 'start', ['plan_fingerprint' => $fp])->assertStatus(422)->assertJsonPath('code', 'APPLY_PRECONDITIONS_FAILED');

        foreach ([$blocked, $unresolved] as $b) {
            $this->assertSame(['READY_FOR_REVIEW', null], [$b->fresh()->status->value, $b->fresh()->apply_started_at]);
        }
        $this->assertSame([0, 0, 0], $this->registry());
        $this->assertNothingSensitive($this->logText());
    }

    public function test_an_unexpected_cause_becomes_a_generic_500_and_is_never_logged(): void
    {
        $this->openGate();
        $batch = $this->fiveRows();
        $this->app->bind(StartImportApplyAction::class, fn () => new class extends StartImportApplyAction
        {
            public function handle(ImportBatch $batch, User $user, string $approvedFingerprint): ImportApplyProgress
            {
                throw new RuntimeException('SQLSTATE[23505]: duplicate key (national_id)=(940000102) رب أسرة');
            }
        });

        $response = $this->applyStep($batch, 'start', ['plan_fingerprint' => $this->fingerprint($batch)])->assertStatus(500)
            ->assertExactJson(['message' => 'حدث خطأ غير متوقع أثناء الاعتماد.', 'code' => 'UNEXPECTED_ERROR', 'row_number' => null]);

        $this->assertNothingSensitive($response->getContent());
        $this->assertNothingSensitive($this->logText());
        // Observability: the code, the batch UUID and the cause's class only.
        $entry = collect($this->logged)->firstWhere('message', 'Import apply request failed');
        $this->assertSame(['code' => 'UNEXPECTED_ERROR', 'row_number' => null, 'batch' => $batch->uuid, 'cause' => RuntimeException::class], $entry->context);
    }

    public function test_framework_outcomes_inside_the_action_are_not_turned_into_500(): void
    {
        $this->openGate();
        $batch = $this->fiveRows();
        $fingerprint = $this->fingerprint($batch);
        $cases = [
            [new ModelNotFoundException, 404],
            [new HttpException(429), 429],
            [ValidationException::withMessages(['plan_fingerprint' => 'x']), 422],
            [new AuthorizationException, 403],
            [new AuthenticationException, 401],
        ];
        foreach ($cases as [$exception, $status]) {
            $this->app->bind(StartImportApplyAction::class, fn () => new class($exception) extends StartImportApplyAction
            {
                public function __construct(private \Throwable $e) {}

                public function handle(ImportBatch $batch, User $user, string $approvedFingerprint): ImportApplyProgress
                {
                    throw $this->e;
                }
            });
            $response = $this->applyStep($batch, 'start', ['plan_fingerprint' => $fingerprint])->assertStatus($status);
            $this->assertNotSame('UNEXPECTED_ERROR', $response->json('code'), $exception::class);
        }
        $this->assertSame('READY_FOR_REVIEW', $batch->fresh()->status->value);
    }

    // ================================================== run / resume / progress

    public function test_run_executes_a_server_budgeted_chunk_and_reports_database_progress(): void
    {
        $this->openGate();
        $batch = $this->started();

        $response = $this->applyStep($batch, 'run')->assertOk();
        $this->assertSame(
            ['batch_id', 'outcome', 'status', 'total_rows', 'applied_rows', 'remaining_rows', 'executed_in_chunk', 'skipped_already_applied', 'pending_links', 'completed', 'error_code', 'error_row_number'],
            array_keys($response->json('data')),
        );
        $this->assertSame(['COMPLETED', 'APPLIED', 5, 0, true], [$response->json('data.outcome'), $response->json('data.status'), $response->json('data.applied_rows'), $response->json('data.remaining_rows'), $response->json('data.completed')]);
        $this->assertSame(5, Family::count());
        $this->assertNothingSensitive($response->getContent(), $batch);

        // Re-running an APPLIED batch re-verifies it and executes nothing.
        $again = $this->applyStep($batch, 'run')->assertOk();
        $this->assertSame(['ALREADY_APPLIED', 0], [$again->json('data.outcome'), $again->json('data.executed_in_chunk')]);
        $this->assertSame(5, Family::count());

        // A batch that was never started cannot be run or resumed.
        $ready = $this->batch([2 => $this->row(940000801, 940000802, 'ب')]);
        $this->applyStep($ready, 'run')->assertStatus(409)->assertJsonPath('code', 'APPLY_STATE_INVALID');
        $this->applyStep($ready, 'resume')->assertStatus(409)->assertJsonPath('code', 'APPLY_STATE_INVALID');
    }

    public function test_a_row_failure_inside_a_chunk_is_a_200_failed_outcome_and_progress_hides_the_row(): void
    {
        $this->openGate();
        $batch = $this->started();
        $this->failResidenceOnAttempt(3);

        $response = $this->applyStep($batch, 'run')->assertOk();
        $this->assertSame(['FAILED', 'PARTIALLY_APPLIED', 2, 'UNEXPECTED_ERROR', 4], [
            $response->json('data.outcome'), $response->json('data.status'), $response->json('data.applied_rows'),
            $response->json('data.error_code'), $response->json('data.error_row_number'),
        ]);
        $this->assertNothingSensitive($response->getContent(), $batch);

        // The reviewer's read-only progress: the code, but not the failing row.
        $progress = $this->actingAs($this->admin)->getJson(self::BASE."/{$batch->uuid}/apply")->assertOk();
        $this->assertSame(['batch_id', 'outcome', 'status', 'total_rows', 'applied_rows', 'remaining_rows', 'completed', 'error_code'], array_keys($progress->json('data')));
        $this->assertSame(['STATUS', 'PARTIALLY_APPLIED', 'UNEXPECTED_ERROR'], [$progress->json('data.outcome'), $progress->json('data.status'), $progress->json('data.error_code')]);
        $this->assertNothingSensitive($progress->getContent(), $batch);

        // PARTIALLY_APPLIED must be resumed explicitly; resume finishes it.
        FamilyResidence::flushEventListeners();
        $this->applyStep($batch, 'run')->assertStatus(409)->assertJsonPath('code', 'APPLY_STATE_INVALID');
        $resumed = $this->applyStep($batch, 'resume')->assertOk();
        $this->assertSame(['APPLIED', 5, null, null], [$resumed->json('data.status'), $resumed->json('data.applied_rows'), $resumed->json('data.error_code'), $resumed->json('data.error_row_number')]);
        $this->assertNothingSensitive($this->logText(), $batch);
    }

    public function test_a_failure_before_any_committed_row_returns_the_batch_to_ready_for_review(): void
    {
        $this->openGate();
        $batch = $this->started();
        $this->failResidenceOnAttempt(1);

        $response = $this->applyStep($batch, 'run')->assertOk();
        $this->assertSame(['FAILED', 'READY_FOR_REVIEW', 'UNEXPECTED_ERROR', 2], [$response->json('data.outcome'), $response->json('data.status'), $response->json('data.error_code'), $response->json('data.error_row_number')]);
        $this->assertSame([null, null], [$batch->fresh()->apply_started_at, $batch->fresh()->apply_plan_fingerprint]);
        $this->assertSame([0, 0, 0], $this->registry());
    }

    public function test_resume_refuses_an_integrity_problem_with_a_fixed_message(): void
    {
        $this->openGate();
        $batch = $this->started();
        $this->failResidenceOnAttempt(3);
        $this->applyStep($batch, 'run')->assertOk();
        FamilyResidence::flushEventListeners();

        $row2 = ImportRow::where('import_batch_id', $batch->id)->where('row_number', 2)->value('id');
        DB::table('import_apply_records')->where('import_row_id', $row2)->where('effect_key', 'RESIDENCE')->delete();

        $this->applyStep($batch, 'resume')->assertStatus(409)->assertExactJson([
            'message' => 'تعذّر التحقق من سلامة ما اعتُمد سابقًا لهذه الدفعة. لا يمكن المتابعة.', 'code' => 'APPLY_INTEGRITY_INVALID', 'row_number' => null,
        ]);
        $this->assertSame(['PARTIALLY_APPLIED', 'APPLY_INTEGRITY_INVALID'], [$batch->fresh()->status->value, $batch->fresh()->apply_error_code]);
        $this->assertSame(2, Family::count()); // nothing repaired, nothing new
    }

    public function test_a_second_runner_gets_apply_in_progress_and_nothing_runs(): void
    {
        $this->openGate();
        $batch = $this->started();
        if (DB::getDriverName() === 'pgsql') {
            config(['database.connections.other_runner' => config('database.connections.pgsql')]);
            $lock = new PostgresApplyRunnerLock('other_runner');
        } else {
            $lock = app(ApplyRunnerLock::class);
        }

        $this->assertTrue($lock->acquire($batch->id));
        try {
            $this->applyStep($batch, 'run')->assertStatus(409)->assertJsonPath('code', 'APPLY_IN_PROGRESS');
            $this->assertSame(0, Family::count());
        } finally {
            $lock->release($batch->id);
            DB::purge('other_runner');
        }
        $this->applyStep($batch, 'run')->assertOk()->assertJsonPath('data.status', 'APPLIED');
    }

    public function test_there_is_no_completion_endpoint_and_no_client_execution_control(): void
    {
        $this->openGate();
        $batch = $this->started();

        foreach (['complete', 'rows', 'chunk', 'execute'] as $step) {
            $this->applyStep($batch, $step)->assertNotFound();
        }
        $this->actingAs($this->admin)->putJson(self::BASE."/{$batch->uuid}/apply", ['status' => 'APPLIED'])->assertStatus(405);
        $this->assertSame('APPLYING', $batch->fresh()->status->value);
    }
}
