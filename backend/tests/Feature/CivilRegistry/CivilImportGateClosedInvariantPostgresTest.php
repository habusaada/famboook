<?php

namespace Tests\Feature\CivilRegistry;

use App\Models\CivilImportPublicationRequest;
use App\Models\CivilImportRequest;
use App\Models\CivilImportSource;
use App\Models\User;
use App\Support\IdentityRef\IdentityRefProductionGate;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PDO;
use Tests\Support\CivilImport\CivilImportHarness;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefRelease;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\Support\IdentityRef\SyntheticIdentityExport;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-UI U4 preparation — the gate-closed invariant: with the
 * publication gate in its DEFAULT state (closed) the active identity release
 * cannot change through the UI path, whatever is attempted — not through the
 * API (nobody holds the confirmation permission), not with a forbidden direct
 * grant (the action refuses), and not by forcing a confirmation and a PUBLISH
 * request straight into the database (the worker refuses), even with a
 * real-data authorization reference present. PostgreSQL only; SYNTHETIC data.
 */
class CivilImportGateClosedInvariantPostgresTest extends TestCase
{
    use CivilImportHarness;
    use DatabaseTruncation;
    use IdentityRefDatabase;
    use IdentityRefRelease;
    use IdentityRefStaging;

    protected $exceptTables = ['clans', 'relationship_types'];

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        $this->assertFalse(config('civil_import.publish_enabled'), 'the default configuration keeps the gate closed');
        $this->seed(RolePermissionSeeder::class);
        $this->setUpCivilImport();
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

    private function apiAs(User $user, string $method, string $uri, array $body = []): TestResponse
    {
        return $this->actingAs($user)->json($method, '/api/v1/civil-registry'.$uri, $body);
    }

    public function test_the_active_release_cannot_change_while_the_publication_gate_is_closed(): void
    {
        $requester = User::factory()->create();
        $requester->assignRole('SUPER_ADMIN');

        // Nobody can confirm (D-UI-12a): the permission is not part of any role while the gate is closed.
        $this->assertSame(0, Artisan::call('famboook:verify-permissions'));
        $this->assertStringContainsString('Civil import publication gate: DISABLED', Artisan::output());
        $this->assertFalse($requester->fresh()->can('civil-import.publish.confirm'));

        // A VERIFIED release, through the worker.
        $this->placeSource($this->syntheticExport(SyntheticIdentityExport::generated(2, 4, 900_000_701)), 'export-gate');
        $this->workOnce();
        $source = CivilImportSource::query()->where('label', 'export-gate')->firstOrFail();
        $work = function (TestResponse $response): CivilImportRequest {
            $id = $response->assertStatus(202)->json('data.id');
            $this->workOnce();

            return CivilImportRequest::query()->findOrFail($id);
        };
        $runId = $work($this->apiAs($requester, 'POST', '/imports', ['source_id' => $source->id]))->run_id;
        $releaseId = $work($this->apiAs($requester, 'POST', "/imports/{$runId}/prepare"))->release_id;
        $this->assertSame(CivilImportRequest::SUCCEEDED, $work($this->apiAs($requester, 'POST', "/releases/{$releaseId}/verify"))->status);
        $before = $this->activeReleaseId();

        // Requesting is allowed; confirming through the API is not (403: no one holds the permission),
        // even with the right password.
        $pid = $this->apiAs($requester, 'POST', "/releases/{$releaseId}/publication-requests", ['justification_code' => 'ROUTINE_REFRESH'])
            ->assertStatus(201)->json('data.id');
        $confirm = ['password' => 'password', 'confirm_release_id' => $releaseId, 'confirm_expected_presence' => 8];
        $this->apiAs($requester, 'POST', "/publication-requests/{$pid}/confirm", $confirm)->assertForbidden();
        $this->apiAs($requester, 'GET', "/publication-requests/{$pid}")->assertJsonPath('data.can_confirm', false);

        // A forbidden direct grant does not help: the action refuses with the gate closed (and the verifier flags it).
        $requester->givePermissionTo('civil-import.publish.confirm');
        $this->apiAs($requester->fresh(), 'POST', "/publication-requests/{$pid}/confirm", $confirm)
            ->assertStatus(409)->assertJsonPath('code', 'PUBLICATION_GATE_CLOSED');
        $this->assertSame(1, Artisan::call('famboook:verify-permissions'));
        $requester->revokePermissionTo('civil-import.publish.confirm');
        $this->assertSame(CivilImportPublicationRequest::PENDING, CivilImportPublicationRequest::query()->find($pid)->status);

        // Forcing it into the database as the DBA — confirmation and PUBLISH request — with an
        // authorization reference present: the worker still refuses, its gate is closed.
        putenv(IdentityRefProductionGate::VARIABLE.'=U4-LOCAL-ACCEPTANCE');
        DB::statement("UPDATE civil_import_publication_requests SET status = 'APPROVED', decided_by = ?, decided_at = now() WHERE id = ?", [$requester->id, $pid]);
        $publish = (string) Str::uuid();
        DB::table('civil_import_requests')->insert(['id' => $publish, 'type' => 'PUBLISH', 'requested_by' => $requester->id,
            'release_id' => $releaseId, 'run_id' => $runId, 'publication_request_id' => $pid]);
        $this->workOnce();
        $refused = CivilImportRequest::query()->findOrFail($publish);
        $this->assertSame([CivilImportRequest::FAILED, 'PUBLICATION_GATE_CLOSED'], [$refused->status, $refused->reason_code]);
        $this->assertSame(['FAILED', 'PUBLICATION_GATE_CLOSED'], [CivilImportPublicationRequest::query()->find($pid)->status,
            CivilImportPublicationRequest::query()->find($pid)->outcome_code]);

        // Throughout: the active release never changed, the candidate is still an unpublished BUILDING release.
        $this->assertSame($before, $this->activeReleaseId());
        $this->assertNull($before);
        $this->assertSame('BUILDING', DB::selectOne('SELECT status FROM identity_ref.dataset_releases WHERE id = ?', [$releaseId])->status);
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
