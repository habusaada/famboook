<?php

namespace Tests\Feature\CivilRegistry;

use App\Models\CivilImportAuditEvent;
use App\Models\CivilImportPublicationRequest;
use App\Models\CivilImportRequest;
use App\Models\CivilImportSource;
use App\Models\User;
use App\Support\IdentityRef\IdentityRefProductionGate;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
 * FB-CIVIL-01A-UI U3 — two-person publication end to end (ADR §9
 * PostgreSQL 6): two real users through the API, the worker with its two
 * separate real logins, the FB-CIVIL-01A engine and its database gate.
 *
 * The publication gate and the real-data authorization reference are set
 * ONLY inside this disposable test process (config / putenv), never in any
 * configuration file. PostgreSQL only; SYNTHETIC data only.
 */
class CivilImportPublicationPostgresTest extends TestCase
{
    use CivilImportHarness;
    use DatabaseTruncation;
    use IdentityRefDatabase;
    use IdentityRefRelease;
    use IdentityRefStaging;

    protected $exceptTables = ['clans', 'relationship_types'];

    private const REFERENCE = 'TEST-LOCAL-0001';

    private User $requester;

    private User $approver;

    private int $exports = 0;

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        config(['civil_import.publish_enabled' => true]);   // this disposable test process only
        $this->seed(RolePermissionSeeder::class);
        $this->setUpCivilImport();
        $this->requester = User::factory()->create();
        $this->requester->assignRole('SUPER_ADMIN');
        $this->approver = User::factory()->create();
        $this->approver->assignRole('SUPER_ADMIN');
        putenv(IdentityRefProductionGate::VARIABLE);
    }

    protected function tearDown(): void
    {
        if ($this->app === null) {
            parent::tearDown();

            return;
        }
        putenv(IdentityRefProductionGate::VARIABLE);
        $this->tearDownCivilImport();
        $this->tearDownIdentityRefStaging();
        $this->tearDownIdentityRef();
        parent::tearDown();
    }

    private function as(User $user, string $method, string $uri, array $body = []): TestResponse
    {
        return $this->actingAs($user)->json($method, '/api/v1/civil-registry'.$uri, $body);
    }

    /** Stages, prepares and verifies a synthetic export through the API and the worker; returns [runId, releaseId]. */
    private function verifiedRelease(int $first = 900_000_501): array
    {
        $label = 'export-'.(++$this->exports);
        $this->placeSource($this->syntheticExport(SyntheticIdentityExport::generated(2, 4, $first)), $label);
        $this->workOnce();
        $source = CivilImportSource::query()->where('label', $label)->firstOrFail();
        $runId = $this->executed($this->as($this->requester, 'POST', '/imports', ['source_id' => $source->id]))->run_id;
        $releaseId = $this->executed($this->as($this->requester, 'POST', "/imports/{$runId}/prepare"))->release_id;
        $this->executed($this->as($this->requester, 'POST', "/releases/{$releaseId}/verify"));

        return [$runId, $releaseId];
    }

    /** Runs one worker iteration for a queued request (202) and asserts it SUCCEEDED. */
    private function executed(TestResponse $response): CivilImportRequest
    {
        $id = $response->assertStatus(202)->json('data.id');
        $this->workOnce();
        $request = CivilImportRequest::query()->findOrFail($id);
        $this->assertSame(CivilImportRequest::SUCCEEDED, $request->status, (string) $request->reason_code);

        return $request;
    }

    private function requestPublication(int $releaseId): string
    {
        return $this->as($this->requester, 'POST', "/releases/{$releaseId}/publication-requests", ['justification_code' => 'ROUTINE_REFRESH'])
            ->assertStatus(201)->assertJsonPath('data.status', 'PENDING_APPROVAL')->json('data.id');
    }

    /** The approver approves (202); returns the queued PUBLISH request id. */
    private function approve(string $publication, int $releaseId, int $expected = 8): string
    {
        return $this->as($this->approver, 'POST', "/publication-requests/{$publication}/approve",
            ['confirm_release_id' => $releaseId, 'confirm_expected_presence' => $expected])
            ->assertStatus(202)->assertJsonPath('data.publication_request.status', 'APPROVED')
            ->assertJsonPath('data.publish_request.type', 'PUBLISH')->assertJsonPath('data.publish_request.status', 'QUEUED')
            ->json('data.publish_request.id');
    }

    private function publication(string $id): CivilImportPublicationRequest
    {
        return CivilImportPublicationRequest::query()->findOrFail($id);
    }

    public function test_two_people_publish_atomically_and_only_after_every_check(): void
    {
        [$runId, $releaseId] = $this->verifiedRelease();
        $this->assertNull($this->activeReleaseId());

        $p1 = $this->requestPublication($releaseId);
        $this->assertSame(['expected_presence' => 8], array_intersect_key($this->publication($p1)->snapshot, ['expected_presence' => 0]));
        $this->as($this->requester, 'GET', "/imports/{$runId}")->assertJsonPath('data.phase', 'AWAITING_APPROVAL');
        $this->as($this->requester, 'GET', '/overview')->assertJsonPath('data.pending_publication_requests', 1);
        $this->as($this->requester, 'POST', "/releases/{$releaseId}/publication-requests", ['justification_code' => 'ROUTINE_REFRESH'])
            ->assertStatus(409)->assertJsonPath('code', 'PUBLICATION_ALREADY_OPEN');
        $this->as($this->requester, 'POST', "/publication-requests/{$p1}/approve", ['confirm_release_id' => $releaseId, 'confirm_expected_presence' => 8])
            ->assertStatus(409)->assertJsonPath('code', 'SAME_USER_APPROVAL');

        // 1. No real-data authorization reference in the worker: refused, nothing changes.
        $publish = $this->approve($p1, $releaseId);
        $this->as($this->requester, 'GET', "/imports/{$runId}")->assertJsonPath('data.phase', 'APPROVED');
        $this->workOnce();
        $this->assertSame([CivilImportRequest::FAILED, 'AUTHORIZATION_MISSING'], [CivilImportRequest::query()->find($publish)->status, CivilImportRequest::query()->find($publish)->reason_code]);
        $this->assertSame(['FAILED', 'AUTHORIZATION_MISSING'], [$this->publication($p1)->status, $this->publication($p1)->outcome_code]);
        $this->assertNull($this->activeReleaseId());
        // A closed request is never reused: approving it again is refused.
        $this->as($this->approver, 'POST', "/publication-requests/{$p1}/approve", ['confirm_release_id' => $releaseId, 'confirm_expected_presence' => 8])
            ->assertStatus(409)->assertJsonPath('code', 'PUBLICATION_NOT_PENDING');

        // 2. A malformed reference is no reference.
        putenv(IdentityRefProductionGate::VARIABLE.'=bad value!');
        $p2 = $this->requestPublication($releaseId);
        $this->approve($p2, $releaseId);
        $this->workOnce();
        $this->assertSame('AUTHORIZATION_MISSING', $this->publication($p2)->outcome_code);
        $this->assertNull($this->activeReleaseId());

        // 3. The gate closed in the worker's process at execution time.
        putenv(IdentityRefProductionGate::VARIABLE.'='.self::REFERENCE);
        $p3 = $this->requestPublication($releaseId);
        $this->approve($p3, $releaseId);
        config(['civil_import.publish_enabled' => false]);
        $this->workOnce();
        config(['civil_import.publish_enabled' => true]);
        $this->assertSame('PUBLICATION_GATE_CLOSED', $this->publication($p3)->outcome_code);
        $this->assertNull($this->activeReleaseId());

        // 4. Every condition holds: the release becomes active, atomically, once.
        $p4 = $this->requestPublication($releaseId);
        $publish = $this->approve($p4, $releaseId);
        $this->workOnce();
        $done = CivilImportRequest::query()->find($publish);
        $this->assertSame([CivilImportRequest::SUCCEEDED, null], [$done->status, $done->reason_code]);
        $this->assertSame(['EXECUTED', 'PUBLISHED'], [$this->publication($p4)->status, $this->publication($p4)->outcome_code]);
        $this->assertSame($releaseId, $this->activeReleaseId());
        $this->assertCount(8, $this->activeSet());
        $this->as($this->requester, 'GET', "/imports/{$runId}")->assertJsonPath('data.phase', 'PUBLISHED')->assertJsonPath('data.allowed_actions', []);
        $this->assertSame(3, CivilImportAuditEvent::query()->where('event_type', 'PUBLICATION_REFUSED')->count());
        $this->assertSame(1, CivilImportAuditEvent::query()->where('event_type', 'RELEASE_PUBLISHED')->where('release_id', $releaseId)->count());

        // No second publication can be queued for an executed request.
        $this->assertSame('23514', $this->sqlStateOf(fn () => DB::table('civil_import_requests')->insert(['id' => (string) Str::uuid(),
            'type' => 'PUBLISH', 'requested_by' => $this->approver->id, 'release_id' => $releaseId, 'publication_request_id' => $p4])));

        // Redaction: nothing but codes, counts, ids and names.
        $json = $this->as($this->requester, 'GET', '/publication-requests')->assertOk()->getContent()
            .CivilImportAuditEvent::query()->get()->toJson().CivilImportPublicationRequest::query()->get()->toJson();
        foreach (['900000501', 'اسم-تجريبي', 't03maindata_part', $this->sourceRoot, self::REFERENCE, $this->requester->email] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_a_baseline_change_invalidates_pending_and_approved_requests(): void
    {
        // Pending: an operator activates the release from the shell; the request expires, it cannot be approved.
        putenv(IdentityRefProductionGate::VARIABLE.'='.self::REFERENCE);
        [, $first] = $this->verifiedRelease();
        $pending = $this->requestPublication($first);
        $this->releases()->publish($this->writerConnection(), $first, 'famboook_test');
        $this->as($this->approver, 'GET', "/publication-requests/{$pending}")->assertJsonPath('data.status', 'EXPIRED')
            ->assertJsonPath('data.outcome_code', 'BASE_CHANGED');
        $this->as($this->approver, 'POST', "/publication-requests/{$pending}/approve", ['confirm_release_id' => $first, 'confirm_expected_presence' => 8])
            ->assertStatus(409)->assertJsonPath('code', 'PUBLICATION_EXPIRED');

        // Approved: the baseline changes before the worker runs; the worker refuses, nothing is replaced.
        [, $second] = $this->verifiedRelease(900_000_601);
        $approved = $this->requestPublication($second);
        $this->assertSame($first, $this->publication($approved)->base_release_id);
        $expected = (int) $this->publication($approved)->snapshot['expected_presence'];
        $this->approve($approved, $second, $expected);
        // An operator publishes the candidate first: the active release is no longer the request's baseline.
        $this->releases()->publish($this->writerConnection(), $second, 'famboook_test');
        $this->workOnce();
        $this->assertSame(['FAILED', 'BASE_CHANGED'], [$this->publication($approved)->status, $this->publication($approved)->outcome_code]);
        $this->assertSame($second, $this->activeReleaseId());
    }

    public function test_concurrent_approvals_queue_one_publication(): void
    {
        [, $releaseId] = $this->verifiedRelease();
        $p = $this->requestPublication($releaseId);
        $third = User::factory()->create();
        $third->assignRole('SUPER_ADMIN');

        // Two sessions race for the same pending row: the second waits for the first, then finds nothing to approve.
        $a = $this->connection();
        $b = $this->connection();
        $approve = "UPDATE civil_import_publication_requests SET status = 'APPROVED', decided_by = %d, decided_at = now()
            WHERE id = '{$p}' AND status = 'PENDING_APPROVAL'";
        $a->beginTransaction();
        $this->assertSame(1, $a->exec(sprintf($approve, $this->approver->id)));
        $b->exec("SET lock_timeout = '500ms'");
        $this->assertSame('55P03', $this->sqlStateOf(fn () => $b->exec(sprintf($approve, $third->id))));
        $a->rollBack();

        // Through the API: one approval wins, the other is refused; exactly one PUBLISH request exists.
        $this->approve($p, $releaseId);
        $this->as($third, 'POST', "/publication-requests/{$p}/approve", ['confirm_release_id' => $releaseId, 'confirm_expected_presence' => 8])
            ->assertStatus(409)->assertJsonPath('code', 'PUBLICATION_NOT_PENDING');
        $this->assertSame(1, CivilImportRequest::query()->where('publication_request_id', $p)->count());
        $this->assertSame('23505', $this->sqlStateOf(fn () => DB::table('civil_import_requests')->insert(['id' => (string) Str::uuid(),
            'type' => 'PUBLISH', 'requested_by' => $this->approver->id, 'release_id' => $releaseId, 'publication_request_id' => $p])));
    }

    public function test_a_failed_activation_rolls_back_and_a_lost_worker_is_reconciled(): void
    {
        putenv(IdentityRefProductionGate::VARIABLE.'='.self::REFERENCE);
        [, $releaseId] = $this->verifiedRelease();

        // The activation fails inside its transaction: rolled back, the previous state stays.
        $p1 = $this->requestPublication($releaseId);
        $this->approve($p1, $releaseId);
        $this->workOnce($this->worker()->withTestHook(function (string $event) {
            if ($event === 'publish.before_commit') {
                throw new RuntimeException('simulated failure before commit');
            }
        }));
        $this->assertSame(['FAILED', 'PUBLICATION_FAILED'], [$this->publication($p1)->status, $this->publication($p1)->outcome_code]);
        $this->assertNull($this->activeReleaseId());
        $this->assertSame(['BUILDING', 'VERIFIED'], [DB::selectOne('SELECT status FROM identity_ref.dataset_releases WHERE id = ?', [$releaseId])->status,
            DB::selectOne('SELECT status FROM identity_ref.release_preparations WHERE release_id = ?', [$releaseId])->status]);

        // The worker dies before activating: the request is lost, the publication fails, nothing is active.
        $p2 = $this->requestPublication($releaseId);
        $lost = $this->approve($p2, $releaseId);
        $this->claimAndDie();
        $this->workOnce($this->worker(instance: 'worker-new'));
        $this->assertSame([CivilImportRequest::FAILED, 'WORKER_LOST'], [CivilImportRequest::query()->find($lost)->status, CivilImportRequest::query()->find($lost)->reason_code]);
        $this->assertSame(['FAILED', 'WORKER_LOST'], [$this->publication($p2)->status, $this->publication($p2)->outcome_code]);
        $this->assertNull($this->activeReleaseId());

        // The worker dies after activating: reconciled from the engine state, never published twice.
        $p3 = $this->requestPublication($releaseId);
        $this->approve($p3, $releaseId);
        $this->claimAndDie(fn () => $this->releases()->publish($this->writerConnection(), $releaseId, 'famboook_test'));
        $this->workOnce($this->worker(instance: 'worker-newer'));
        $this->assertSame(['EXECUTED', 'RECONCILED_PUBLISHED'], [$this->publication($p3)->status, $this->publication($p3)->outcome_code]);
        $this->assertSame($releaseId, $this->activeReleaseId());
        $this->assertSame(1, CivilImportRequest::query()->where('publication_request_id', $p3)->count());
        $this->assertTrue(CivilImportAuditEvent::query()->where('event_type', 'RELEASE_PUBLISHED')->where('outcome_code', 'RECONCILED_PUBLISHED')->exists());
    }

    public function test_the_control_role_can_only_close_publication_requests(): void
    {
        [, $releaseId] = $this->verifiedRelease();
        $p = $this->requestPublication($releaseId);
        $control = $this->loginConnection('famboook_civil_control');
        $control->unprepared('SET ROLE famboook_civil_control');
        $state = fn (string $sql) => $this->sqlStateOf(fn () => $control->unprepared($sql));
        $none = "WHERE id = '".Str::uuid()."'";
        $this->assertNull($state('SELECT status, snapshot FROM civil_import_publication_requests'));
        $this->assertNull($state("UPDATE civil_import_publication_requests SET status = 'EXPIRED', outcome_code = 'TIMEOUT', finished_at = now() {$none}"));
        $this->assertSame('42501', $state("UPDATE civil_import_publication_requests SET decided_by = 1 {$none}"));
        $this->assertSame('42501', $state("UPDATE civil_import_publication_requests SET expires_at = now() {$none}"));
        $this->assertSame('42501', $state("DELETE FROM civil_import_publication_requests {$none}"));
        $this->assertSame('42501', $state("INSERT INTO civil_import_publication_requests (id, release_id, run_id, requested_by, justification_code, snapshot, expires_at)
            VALUES ('".Str::uuid()."', 1, '".Str::uuid()."', 1, 'ROUTINE_REFRESH', '{}', now() + interval '1 hour')"));

        // The database: two people, approved-only PUBLISH, queued by its approver, within the window.
        $sql = fn (string $q, array $b = []) => $this->sqlStateOf(fn () => DB::statement($q, $b));
        $this->assertSame('23514', $sql("UPDATE civil_import_publication_requests SET status = 'APPROVED', decided_by = requested_by, decided_at = now() WHERE id = ?", [$p]));
        $this->assertSame('23514', $sql("INSERT INTO civil_import_requests (id, type, requested_by, release_id, publication_request_id) VALUES (?, 'PUBLISH', ?, ?, ?)",
            [(string) Str::uuid(), $this->approver->id, $releaseId, $p]));   // not approved
        DB::statement("UPDATE civil_import_publication_requests SET status = 'APPROVED', decided_by = ?, decided_at = now() WHERE id = ?", [$this->approver->id, $p]);
        $this->assertSame('23514', $sql("INSERT INTO civil_import_requests (id, type, requested_by, release_id, publication_request_id) VALUES (?, 'PUBLISH', ?, ?, ?)",
            [(string) Str::uuid(), $this->requester->id, $releaseId, $p]));   // not queued by the approver
        $this->assertSame('23001', $sql('UPDATE civil_import_publication_requests SET release_id = 999 WHERE id = ?', [$p]));
        $this->assertSame('23514', $sql("UPDATE civil_import_publication_requests SET status = 'PENDING_APPROVAL' WHERE id = ?", [$p]));
        $this->assertSame('23001', $sql('DELETE FROM civil_import_publication_requests WHERE id = ?', [$p]));

        // The approval window is enforced by the database clock too.
        DB::statement("UPDATE civil_import_publication_requests SET status = 'FAILED', outcome_code = 'TEST_CLOSED', finished_at = now() WHERE id = ?", [$p]);
        $short = (string) Str::uuid();
        DB::statement("INSERT INTO civil_import_publication_requests (id, release_id, run_id, requested_by, justification_code, snapshot, expires_at)
            VALUES (?, ?, ?, ?, 'ROUTINE_REFRESH', '{}', clock_timestamp() + interval '1 second')", [$short, $releaseId, (string) Str::uuid(), $this->requester->id]);
        DB::statement('SELECT pg_sleep(2.1)');   // timestamp(0): expires_at is rounded to the second
        $this->assertSame('23514', $sql("UPDATE civil_import_publication_requests SET status = 'APPROVED', decided_by = ?, decided_at = now() WHERE id = ?",
            [$this->approver->id, $short]));
    }

    /** A worker claims the queued request, optionally does $work, and dies (heartbeat 30 minutes old). */
    private function claimAndDie(?\Closure $work = null): void
    {
        $id = $this->asWorker(function () {
            $worker = $this->worker(instance: 'worker-dead-'.Str::random(4));
            $worker->boot();

            return $worker->claim()->id;
        });
        if ($work !== null) {
            $work();
        }
        DB::table('civil_import_requests')->where('id', $id)->update(['heartbeat_at' => now()->subMinutes(30)]);
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
