<?php

namespace Tests\Feature\CivilRegistry;

use App\Models\CivilImportAuditEvent;
use App\Models\CivilImportRequest;
use App\Models\CivilImportSource;
use App\Models\User;
use App\Support\CivilImport\CivilImportWorkerException;
use App\Support\IdentityRef\IdentityImportSession;
use App\Support\IdentityRef\IdentityManifest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PDO;
use RuntimeException;
use Tests\Support\CivilImport\CivilImportHarness;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefRelease;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\Support\IdentityRef\SyntheticIdentityExport;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-UI U2 — controlled import execution end to end (ADR §9
 * PostgreSQL 5): the API records requests, the worker executes them with its
 * two separate real logins, the FB-CIVIL-01A engine does the work. Covers
 * the workflow, interruption and resume, cancellation, WORKER_LOST, request
 * locking, credential separation and response redaction. The active release
 * never changes (no publication in U2). PostgreSQL only; SYNTHETIC data only.
 */
class CivilImportWorkerPostgresTest extends TestCase
{
    use CivilImportHarness;
    use DatabaseTruncation;
    use IdentityRefDatabase;
    use IdentityRefRelease;
    use IdentityRefStaging;

    protected $exceptTables = ['clans', 'relationship_types'];

    private User $admin;

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->setUpCivilImport();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('SUPER_ADMIN');
    }

    protected function tearDown(): void
    {
        if ($this->app === null) {   // setUp skipped before booting
            parent::tearDown();

            return;
        }
        $this->tearDownCivilImport();
        $this->tearDownIdentityRefStaging();
        $this->tearDownIdentityRef();
        parent::tearDown();
    }

    private function generatedExport(int $files = 2, int $rows = 4, int $first = 900_000_401): SyntheticIdentityExport
    {
        return $this->syntheticExport(SyntheticIdentityExport::generated($files, $rows, $first));
    }

    /** Catalogues the root (one worker iteration) and returns the source with $label. */
    private function catalogued(string $label): CivilImportSource
    {
        $this->workOnce();

        return CivilImportSource::query()->where('label', $label)->firstOrFail();
    }

    private function apiPost(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/civil-registry'.$uri, $body, $headers);
    }

    private function apiGet(string $uri): TestResponse
    {
        return $this->actingAs($this->admin)->getJson('/api/v1/civil-registry'.$uri);
    }

    /** Queues through the API (202), runs one worker iteration, returns the finished request. */
    private function queueAndWork(string $uri, array $body = []): CivilImportRequest
    {
        $id = $this->apiPost($uri, $body)->assertStatus(202)->assertJsonPath('data.status', 'QUEUED')->json('data.id');
        $this->workOnce();

        return CivilImportRequest::query()->findOrFail($id);
    }

    private function assertRedacted(string $json, string $context): void
    {
        foreach (['t03maindata_part', $this->sourceRoot, 'famboook-u2-root', '900000401', 'اسم-تجريبي', 'sha256', 'manifest_sha256'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, "{$context}: {$needle}");
        }
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $json, $context);
    }

    public function test_the_full_workflow_runs_through_the_worker_and_never_changes_the_active_release(): void
    {
        $this->placeSource($this->generatedExport(), 'export-2026-11-01');
        $source = $this->catalogued('export-2026-11-01');
        $this->assertSame([CivilImportSource::VALID, 2, 8], [$source->manifest_status, $source->file_count, $source->total_rows]);

        // The overview shows the worker's heartbeat (it just ran an iteration).
        $this->apiGet('/overview')->assertOk()->assertJsonPath('data.worker.state', 'IDLE');

        $sources = $this->apiGet('/sources')->assertOk()->assertJsonPath('data.0.label', 'export-2026-11-01')->assertJsonPath('data.0.files', 2);
        $this->assertRedacted($sources->getContent(), 'sources');

        $validation = $this->queueAndWork("/sources/{$source->id}/validate");
        $this->assertSame([CivilImportRequest::SUCCEEDED, null], [$validation->status, $validation->reason_code]);
        $this->assertSame(['files' => 2, 'manifest' => 'VALID', 'problems' => [], 'total_rows' => 8], $validation->result);

        $stage = $this->queueAndWork('/imports', ['source_id' => $source->id]);
        $this->assertSame(CivilImportRequest::SUCCEEDED, $stage->status, (string) $stage->reason_code);
        $this->assertSame(8, $stage->result['rows_staged']);
        $runId = $stage->run_id;
        $this->assertNotNull($runId);

        $detail = $this->apiGet("/imports/{$runId}")->assertOk()->assertJsonPath('data.phase', 'STAGED')
            ->assertJsonPath('data.allowed_actions', ['ASSESS', 'PREPARE', 'ABORT'])
            ->assertJsonPath('data.source.label', 'export-2026-11-01')
            ->assertJsonPath('data.requested_by.name', $this->admin->name);
        $this->assertRedacted($detail->getContent(), 'detail');

        $assess = $this->queueAndWork("/imports/{$runId}/assess");
        $this->assertSame(CivilImportRequest::SUCCEEDED, $assess->status, (string) $assess->reason_code);
        $this->assertTrue($assess->result['publishable']);
        $this->assertSame(8, $assess->result['counts']['new']);
        $this->assertSame([], $assess->result['fatal']);

        $prepare = $this->queueAndWork("/imports/{$runId}/prepare");
        $this->assertSame(CivilImportRequest::SUCCEEDED, $prepare->status, (string) $prepare->reason_code);
        $releaseId = $prepare->release_id;
        $this->apiGet("/imports/{$runId}")->assertJsonPath('data.phase', 'PREPARED')->assertJsonPath('data.allowed_actions', ['VERIFY', 'ASSESS', 'ABORT']);

        $verify = $this->queueAndWork("/releases/{$releaseId}/verify");
        $this->assertSame(CivilImportRequest::SUCCEEDED, $verify->status, (string) $verify->reason_code);
        // U3: a VERIFIED run can be put forward for (two-person) publication.
        $this->apiGet("/imports/{$runId}")->assertJsonPath('data.phase', 'VERIFIED')->assertJsonPath('data.allowed_actions', ['REQUEST_PUBLICATION', 'ASSESS', 'ABORT']);

        // No publication in U2: nothing is active, the release stays BUILDING and VERIFIED.
        $this->assertNull($this->activeReleaseId());
        $this->assertSame('BUILDING', DB::selectOne('SELECT status FROM identity_ref.dataset_releases WHERE id = ?', [$releaseId])->status);
        $this->apiPost("/releases/{$releaseId}/verify")->assertStatus(409)->assertJsonPath('code', 'PHASE_NOT_ALLOWED');

        $requests = $this->apiGet("/requests?run={$runId}")->assertOk();
        $this->assertCount(4, $requests->json('data'));
        $this->assertRedacted($requests->getContent(), 'requests');

        $types = CivilImportAuditEvent::query()->orderBy('id')->pluck('event_type')->all();
        $this->assertContains('SOURCE_CATALOGUED', $types);
        $this->assertSame(5, count(array_keys($types, 'REQUEST_SUCCEEDED')));
        $this->assertRedacted(CivilImportAuditEvent::query()->get()->toJson(), 'audit');
        $this->assertRedacted((string) json_encode(CivilImportRequest::query()->get()->toArray()), 'requests table');
    }

    public function test_an_interrupted_staging_resumes_from_its_checkpoints_without_duplicates(): void
    {
        $this->placeSource($this->generatedExport(2, 5), 'export-resume');
        $source = $this->catalogued('export-resume');
        $id = $this->apiPost('/imports', ['source_id' => $source->id])->assertStatus(202)->json('data.id');

        $chunks = 0;
        $this->workOnce($this->worker()->withTestHook(function (string $event) use (&$chunks) {
            if ($event === 'chunk.committed' && ++$chunks === 2) {
                throw new RuntimeException('simulated crash');
            }
        }));
        $failed = CivilImportRequest::query()->findOrFail($id);
        $this->assertSame([CivilImportRequest::FAILED, 'STAGING_INTERRUPTED'], [$failed->status, $failed->reason_code]);
        $runId = $failed->run_id;
        $this->assertSame('RUNNING', $this->importRun($runId)->status);
        $this->apiGet("/imports/{$runId}")->assertJsonPath('data.phase', 'STAGING_INTERRUPTED')->assertJsonPath('data.allowed_actions', ['STAGE', 'ABORT']);
        $this->assertGreaterThan(0, $this->stagedCount($runId));

        $resumed = $this->queueAndWork('/imports', ['source_id' => $source->id]);
        $this->assertSame(CivilImportRequest::SUCCEEDED, $resumed->status, (string) $resumed->reason_code);
        $this->assertTrue($resumed->result['resumed']);
        $this->assertSame($runId, $resumed->run_id);
        $this->assertSame(10, $this->stagedCount($runId));
        $this->assertSame(10, (int) DB::selectOne('SELECT count(DISTINCT mainid) AS n FROM identity_ref_staging.rows')->n);
    }

    public function test_cancellation_stops_at_a_committed_chunk_and_the_run_can_resume_or_be_aborted(): void
    {
        $this->placeSource($this->generatedExport(2, 5), 'export-cancel');
        $source = $this->catalogued('export-cancel');

        // QUEUED: cancelled at once; the worker never runs it.
        $queued = $this->apiPost('/imports', ['source_id' => $source->id])->assertStatus(202)->json('data.id');
        $this->actingAs($this->admin)->deleteJson("/api/v1/civil-registry/requests/{$queued}")->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertNull($this->workOnce());

        // RUNNING: recorded, honoured at the next committed chunk.
        $id = $this->apiPost('/imports', ['source_id' => $source->id])->assertStatus(202)->json('data.id');
        $this->workOnce($this->worker()->withTestHook(function (string $event) use ($id) {
            static $sent = false;
            if ($event === 'chunk.committed' && ! $sent) {
                // The operator cancels through the API (the web application's own connection).
                $sent = true;
                DB::setDefaultConnection('pgsql');
                try {
                    $this->actingAs($this->admin)->deleteJson("/api/v1/civil-registry/requests/{$id}")
                        ->assertStatus(202)->assertJsonPath('data.cancel_requested', true)->assertJsonPath('data.status', 'RUNNING');
                } finally {
                    DB::setDefaultConnection($this->controlConnection);
                }
            }
        }));
        $cancelled = CivilImportRequest::query()->findOrFail($id);
        $this->assertSame([CivilImportRequest::CANCELLED, 'CANCELLED_BY_OPERATOR'], [$cancelled->status, $cancelled->reason_code]);
        $runId = $cancelled->run_id;
        $this->assertSame('RUNNING', $this->importRun($runId)->status);
        $this->assertLessThan(10, $this->stagedCount($runId));

        $abort = $this->queueAndWork("/imports/{$runId}/abort", ['reason_code' => 'OPERATOR_DECISION']);
        $this->assertSame(CivilImportRequest::SUCCEEDED, $abort->status, (string) $abort->reason_code);
        $this->assertSame(['FAILED', 'ABORTED'], [$this->importRun($runId)->status, $this->importRun($runId)->error_code]);
        $this->assertSame(0, $this->stagedCount($runId));
        $this->apiGet("/imports/{$runId}")->assertJsonPath('data.phase', 'ABORTED')->assertJsonPath('data.allowed_actions', []);
    }

    /** Decision 1: an ABORT whose purge stops still SUCCEEDS (the run is aborted) and says the purge is incomplete. */
    public function test_an_abort_with_an_interrupted_purge_succeeds_and_reports_it(): void
    {
        $this->placeSource($this->generatedExport(), 'export-abort-purge');
        $source = $this->catalogued('export-abort-purge');
        $runId = $this->queueAndWork('/imports', ['source_id' => $source->id])->run_id;
        $this->assertSame(8, $this->stagedCount($runId));

        $id = $this->apiPost("/imports/{$runId}/abort", ['reason_code' => 'OPERATOR_DECISION'])->assertStatus(202)->json('data.id');
        config(['civil_import.worker.purge_batch_size' => 3]);
        $this->workOnce($this->worker()->withTestHook(function (string $event, array $context) {
            if ($event === 'purge.batch_committed' && $context['rows'] > 0) {
                throw new RuntimeException('simulated crash during the staging purge');
            }
        }));
        $abort = CivilImportRequest::query()->findOrFail($id);
        $this->assertSame(CivilImportRequest::SUCCEEDED, $abort->status, (string) $abort->reason_code);
        $this->assertSame([3, false, $runId], [$abort->result['rows_purged'], $abort->result['staging_purge_complete'], $abort->result['aborted_run']]);
        $this->assertSame(['FAILED', 'ABORTED'], [$this->importRun($runId)->status, $this->importRun($runId)->error_code]);
        $this->assertSame(5, $this->stagedCount($runId));
        $this->apiGet("/imports/{$runId}")->assertJsonPath('data.phase', 'ABORTED')->assertJsonPath('data.allowed_actions', []);
    }

    public function test_a_lost_worker_is_detected_and_the_operation_stays_resumable(): void
    {
        $this->placeSource($this->generatedExport(), 'export-lost');
        $source = $this->catalogued('export-lost');
        $id = $this->apiPost("/sources/{$source->id}/validate")->assertStatus(202)->json('data.id');

        // A worker claims the request and dies.
        $this->asWorker(function () {
            $worker = $this->worker(instance: 'worker-dead');
            $worker->boot();
            $worker->claim();
        });
        DB::table('civil_import_requests')->where('id', $id)->update(['heartbeat_at' => now()->subMinutes(30)]);
        $this->apiPost("/sources/{$source->id}/validate")->assertStatus(409)->assertJsonPath('code', 'REQUEST_ALREADY_ACTIVE');

        // The next worker closes it at start-up as WORKER_LOST; a new request then runs.
        $this->workOnce($this->worker(instance: 'worker-new'));
        $lost = CivilImportRequest::query()->findOrFail($id);
        $this->assertSame([CivilImportRequest::FAILED, 'WORKER_LOST', 1], [$lost->status, $lost->reason_code, $lost->attempts]);
        $this->assertSame(CivilImportRequest::SUCCEEDED, $this->queueAndWork("/sources/{$source->id}/validate")->status);
        $this->assertTrue(CivilImportAuditEvent::query()->where('event_type', 'REQUEST_FAILED')->where('outcome_code', 'WORKER_LOST')->exists());
    }

    public function test_concurrent_workers_never_claim_the_same_request_and_the_engine_lock_serializes_imports(): void
    {
        $this->placeSource($this->generatedExport(), 'export-lock');
        $source = $this->catalogued('export-lock');
        $id = $this->apiPost("/sources/{$source->id}/validate")->assertStatus(202)->json('data.id');

        // Session A holds the row lock of the claim; session B (and the worker) skip it.
        $a = $this->connection('famboook_civil_control');
        $b = $this->connection('famboook_civil_control');
        $claim = "SELECT id FROM civil_import_requests WHERE status = 'QUEUED' ORDER BY queued_at, id LIMIT 1 FOR UPDATE SKIP LOCKED";
        $a->beginTransaction();
        $this->assertSame($id, $a->query($claim)->fetchColumn());
        $b->beginTransaction();
        $this->assertFalse($b->query($claim)->fetchColumn());
        $this->assertNull($this->asWorker(function () {
            $worker = $this->worker();
            $worker->boot();

            return $worker->claim();
        }));
        $b->rollBack();
        $a->rollBack();

        // The FB-CIVIL-01A advisory lock held by an operator shell: the STAGE request fails CONCURRENT_IMPORT.
        $this->workOnce();   // runs the validation
        $shell = $this->connection();
        $shell->query('SELECT pg_advisory_lock('.IdentityImportSession::LOCK_KEY.')');
        $stage = $this->queueAndWork('/imports', ['source_id' => $source->id]);
        $shell->query('SELECT pg_advisory_unlock('.IdentityImportSession::LOCK_KEY.')');
        $this->assertSame([CivilImportRequest::FAILED, 'CONCURRENT_IMPORT'], [$stage->status, $stage->reason_code]);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.import_runs')->n);
    }

    public function test_retries_are_idempotent(): void
    {
        $this->placeSource($this->generatedExport(), 'export-idem');
        $source = $this->catalogued('export-idem');
        $key = ['Idempotency-Key' => 'retry-key-0001'];
        $first = $this->apiPost("/sources/{$source->id}/validate", [], $key)->assertStatus(202)->json('data.id');
        $this->apiPost("/sources/{$source->id}/validate", [], $key)->assertOk()->assertJsonPath('data.id', $first);
        $this->apiPost('/imports', ['source_id' => $source->id], $key)->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->apiPost("/sources/{$source->id}/validate")->assertStatus(409)->assertJsonPath('code', 'REQUEST_ALREADY_ACTIVE');
        $this->assertSame(1, CivilImportRequest::query()->count());

        $this->workOnce();
        // After completion the same key still names the same (finished) request: nothing runs twice.
        $this->apiPost("/sources/{$source->id}/validate", [], $key)->assertOk()->assertJsonPath('data.status', 'SUCCEEDED');
        $this->assertSame(1, CivilImportRequest::query()->count());

        // A staged run is not staged again (resume applies to an incomplete staging only).
        $stage = $this->queueAndWork('/imports', ['source_id' => $source->id]);
        $this->apiPost('/imports', ['source_id' => $source->id])->assertStatus(409)->assertJsonPath('code', 'PHASE_NOT_ALLOWED');
        $this->assertSame(8, $this->stagedCount($stage->run_id));
    }

    public function test_source_integrity_failures_are_reported_as_codes_only(): void
    {
        $export = $this->generatedExport();
        $dir = $this->placeSource($export, 'export-tampered');
        // Content changed, manifest kept: the manifest is valid, the checksum is not.
        file_put_contents($dir.DIRECTORY_SEPARATOR.'t03maindata_part_00002.csv', "\xEF\xBB\xBF".implode(',', IdentityManifest::COLUMNS)."\r\n");
        $source = $this->catalogued('export-tampered');
        $this->assertSame(CivilImportSource::VALID, $source->manifest_status);

        $validation = $this->queueAndWork("/sources/{$source->id}/validate");
        $this->assertSame([CivilImportRequest::FAILED, 'SOURCE_INVALID'], [$validation->status, $validation->reason_code]);
        $this->assertSame(1, array_sum($validation->result['problems']));
        $response = $this->apiGet("/requests/{$validation->id}")->assertOk()->assertJsonPath('data.reason_code', 'SOURCE_INVALID');
        $this->assertRedacted($response->getContent(), 'validation');

        // A manifest that becomes invalid: the source is not ready, nothing can be queued for it.
        file_put_contents($dir.DIRECTORY_SEPARATOR.'manifest.json', '{"table": "t03maindata"}');
        $source = $this->catalogued('export-tampered');
        $this->assertSame(CivilImportSource::INVALID, $source->manifest_status);
        $this->apiPost('/imports', ['source_id' => $source->id])->assertStatus(409)->assertJsonPath('code', 'SOURCE_NOT_READY');
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.import_runs')->n);
    }

    public function test_the_worker_refuses_logins_that_are_not_separated(): void
    {
        // The test's own (superuser) session as the control plane.
        $this->assertRefusedToBoot('pgsql');
        // A control login that can also act as the identity writer.
        $both = $this->loginConnection('famboook_civil_control');
        DB::unprepared('GRANT famboook_identity_writer TO '.$both->getConfig()['username']);
        $this->assertRefusedToBoot($both->getName());

        // The control login used as the import login: every request fails WRONG_ROLE, nothing is imported.
        $this->placeSource($this->generatedExport(), 'export-sep');
        $source = $this->catalogued('export-sep');
        $id = $this->apiPost('/imports', ['source_id' => $source->id])->assertStatus(202)->json('data.id');
        $this->workOnce($this->worker($this->controlConnection));
        $this->assertSame([CivilImportRequest::FAILED, 'WRONG_ROLE'], [CivilImportRequest::query()->find($id)->status, CivilImportRequest::query()->find($id)->reason_code]);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.import_runs')->n);

        // An import login that is also a control-plane member.
        $mixed = $this->loginConnection('famboook_identity_writer');
        DB::unprepared('GRANT famboook_civil_control TO '.$mixed->getConfig()['username']);
        $id = $this->apiPost('/imports', ['source_id' => $source->id])->assertStatus(202)->json('data.id');
        $this->workOnce($this->worker($mixed->getName()));
        $this->assertSame('WRONG_ROLE', CivilImportRequest::query()->find($id)->reason_code);

        // Without a target or with publication enabled nothing changes in U2's guarantees.
        config(['civil_import.worker.target' => null]);
        $id = $this->apiPost("/sources/{$source->id}/validate")->assertStatus(202)->json('data.id');
        $this->workOnce();
        $this->assertSame('TARGET_NOT_CONFIGURED', CivilImportRequest::query()->find($id)->reason_code);
    }

    private function assertRefusedToBoot(string $connection): void
    {
        try {
            $this->asWorker(fn () => $this->worker()->boot(), $connection);
            $this->fail("booted on {$connection}");
        } catch (CivilImportWorkerException $e) {
            $this->assertContains($e->reason, ['CONTROL_LOGIN_NOT_ISOLATED', 'CONTROL_LOGIN_REFUSED']);
        } finally {
            DB::connection($connection)->unprepared('RESET ROLE');
        }
    }

    private static function pgsqlTestDatabaseIsReachable(): bool
    {
        $env = static fn (string $key): ?string => ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key)) ?: null;
        $database = (string) $env('DB_DATABASE');
        if ($env('DB_CONNECTION') !== 'pgsql' || ! str_ends_with($database, '_test')) {
            return false;
        }
        try {
            new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s;connect_timeout=3', $env('DB_HOST') ?? '127.0.0.1', $env('DB_PORT') ?? '5432', $database),
                $env('DB_USERNAME'), $env('DB_PASSWORD'));

            return true;
        } catch (\PDOException) {
            return false;
        }
    }
}
