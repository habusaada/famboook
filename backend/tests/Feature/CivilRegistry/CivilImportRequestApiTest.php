<?php

namespace Tests\Feature\CivilRegistry;

use App\Models\CivilImportAuditEvent;
use App\Models\CivilImportRequest;
use App\Models\CivilImportSource;
use App\Models\User;
use App\Support\CivilImport\CivilImportResult;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-UI U2 — the execution request API rules that need no identity
 * schema (runs in the default SQLite suite too): authorization and the
 * staging / validation split, request recording only, idempotency, the
 * single active request, cancellation, redaction, the model's state machine
 * and the result allow-list. The PostgreSQL-only parts are covered by
 * CivilImportWorkerPostgresTest and CivilImportControlPlanePostgresTest.
 * SYNTHETIC data only.
 */
class CivilImportRequestApiTest extends TestCase
{
    use RefreshDatabase;

    private const RUN = '00000000-0000-4000-8000-0000000000b1';

    private CivilImportSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['civil_import.monitor_credentials_file' => base_path('storage/framework/testing/no-such-identity-monitor.php')]);
        $this->source = CivilImportSource::query()->create(['label' => 'export-2026-11-01', 'manifest_status' => 'VALID', 'source_table' => 't03maindata',
            'file_count' => 24, 'total_rows' => 2399891, 'manifest_sha256' => str_repeat('a', 64), 'available' => true]);
    }

    private function user(string $role, array $direct = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $direct === [] || $user->givePermissionTo($direct);

        return $user;
    }

    private function validate(User $user, array $headers = []): TestResponse
    {
        return $this->actingAs($user)->postJson("/api/v1/civil-registry/sources/{$this->source->id}/validate", [], $headers);
    }

    public function test_every_execution_endpoint_requires_authentication_and_its_own_permission(): void
    {
        $writes = [
            ['post', "/sources/{$this->source->id}/validate", 'civil-import.stage'],
            ['post', '/imports', 'civil-import.stage'],
            ['post', '/imports/'.self::RUN.'/abort', 'civil-import.stage'],
            ['post', '/imports/'.self::RUN.'/assess', 'civil-import.validate'],
            ['post', '/imports/'.self::RUN.'/prepare', 'civil-import.validate'],
            ['post', '/releases/4/verify', 'civil-import.validate'],
        ];
        foreach (array_merge($writes, [['get', '/sources', 'civil-import.stage'], ['get', '/requests', 'civil-import.view'],
            ['get', '/requests/'.self::RUN, 'civil-import.view'], ['delete', '/requests/'.self::RUN, 'civil-import.view']]) as [$method, $uri]) {
            $this->json($method, '/api/v1/civil-registry'.$uri)->assertUnauthorized();
        }
        foreach (['ADMINISTRATOR', 'DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $user = $this->user($role);
            foreach ($writes as [$method, $uri]) {
                $this->actingAs($user)->json($method, '/api/v1/civil-registry'.$uri, ['source_id' => $this->source->id, 'reason_code' => 'OPERATOR_DECISION'])->assertForbidden();
            }
        }
        // Staging and validation are separate: each permission opens its own endpoints only.
        $stager = $this->user('REPORTS_VIEWER', ['civil-import.view', 'civil-import.stage']);
        $validator = $this->user('REPORTS_VIEWER', ['civil-import.view', 'civil-import.validate']);
        foreach ($writes as [$method, $uri, $permission]) {
            $other = $permission === 'civil-import.stage' ? $validator : $stager;
            $this->actingAs($other)->json($method, '/api/v1/civil-registry'.$uri, ['source_id' => $this->source->id, 'reason_code' => 'OPERATOR_DECISION'])
                ->assertForbidden();
        }
        $this->actingAs($validator)->getJson('/api/v1/civil-registry/sources')->assertForbidden();
        $this->assertSame(0, CivilImportRequest::query()->count());
    }

    public function test_a_write_only_records_a_queued_request_and_audits_it(): void
    {
        $admin = $this->user('SUPER_ADMIN');
        $response = $this->validate($admin)->assertStatus(202)
            ->assertJsonPath('data.type', 'VALIDATE_SOURCE')->assertJsonPath('data.status', 'QUEUED')
            ->assertJsonPath('data.source.label', 'export-2026-11-01')->assertJsonPath('data.can_cancel', true);
        $request = CivilImportRequest::query()->sole();
        $this->assertSame([CivilImportRequest::QUEUED, 0, null], [$request->status, $request->attempts, $request->started_at]);
        $event = CivilImportAuditEvent::query()->sole();
        $this->assertSame(['REQUEST_QUEUED', 'STAFF', $request->id, ['type' => 'VALIDATE_SOURCE']],
            [$event->event_type, $event->actor_side, $event->request_id, $event->details]);
        $this->assertStringNotContainsString(str_repeat('a', 64), $response->getContent());
    }

    public function test_idempotency_keys_and_the_single_active_request(): void
    {
        $admin = $this->user('SUPER_ADMIN');
        $first = $this->validate($admin, ['Idempotency-Key' => 'wizard-key-01'])->assertStatus(202)->json('data.id');
        $this->validate($admin, ['Idempotency-Key' => 'wizard-key-01'])->assertOk()->assertJsonPath('data.id', $first);
        $this->actingAs($admin)->postJson('/api/v1/civil-registry/imports', ['source_id' => $this->source->id], ['Idempotency-Key' => 'wizard-key-01'])
            ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->validate($admin, ['Idempotency-Key' => 'wizard-key-02'])->assertStatus(409)->assertJsonPath('code', 'REQUEST_ALREADY_ACTIVE');
        $this->validate($admin, ['Idempotency-Key' => 'bad key!'])->assertStatus(422);
        $this->assertSame(1, CivilImportRequest::query()->count());
    }

    public function test_sources_must_be_ready_and_targets_are_opaque_ids_never_paths(): void
    {
        $admin = $this->user('SUPER_ADMIN');
        $this->source->forceFill(['manifest_status' => 'INVALID'])->save();
        $this->validate($admin)->assertStatus(409)->assertJsonPath('code', 'SOURCE_NOT_READY');
        $this->source->forceFill(['manifest_status' => 'VALID', 'available' => false])->save();
        $this->validate($admin)->assertStatus(409)->assertJsonPath('code', 'SOURCE_NOT_READY');
        foreach (['../../etc', '/var/lib/famboook-import/x', 'export-2026-11-01'] as $path) {
            $this->actingAs($admin)->postJson('/api/v1/civil-registry/imports', ['source_id' => $path])->assertStatus(422);
            $this->actingAs($admin)->postJson('/api/v1/civil-registry/sources/'.rawurlencode($path).'/validate')->assertNotFound();
        }
        // A path field is not part of the contract and is ignored; nothing is queued for an unknown source.
        $this->actingAs($admin)->postJson('/api/v1/civil-registry/sources/'.self::RUN.'/validate', ['path' => '/etc'])
            ->assertStatus(409)->assertJsonPath('code', 'SOURCE_NOT_READY');
        $this->assertSame(0, CivilImportRequest::query()->count());
    }

    public function test_phase_checked_writes_fail_closed_without_the_monitor(): void
    {
        $admin = $this->user('SUPER_ADMIN');
        foreach ([['/imports', ['source_id' => $this->source->id]], ['/imports/'.self::RUN.'/assess', []], ['/imports/'.self::RUN.'/prepare', []],
            ['/imports/'.self::RUN.'/abort', ['reason_code' => 'OPERATOR_DECISION']], ['/releases/4/verify', []]] as [$uri, $body]) {
            $this->actingAs($admin)->postJson('/api/v1/civil-registry'.$uri, $body)->assertStatus(503)->assertJsonPath('code', 'MONITOR_NOT_CONFIGURED');
        }
        $this->actingAs($admin)->postJson('/api/v1/civil-registry/imports/'.self::RUN.'/abort', ['reason_code' => 'BECAUSE'])->assertStatus(422);
        $this->assertSame(0, CivilImportRequest::query()->count());
    }

    public function test_cancellation_rules(): void
    {
        $admin = $this->user('SUPER_ADMIN');
        $id = $this->validate($admin)->json('data.id');
        $viewer = $this->user('REPORTS_VIEWER', ['civil-import.view']);
        $this->actingAs($viewer)->deleteJson("/api/v1/civil-registry/requests/{$id}")->assertForbidden()->assertJsonPath('code', 'NOT_ALLOWED');
        $this->actingAs($admin)->deleteJson("/api/v1/civil-registry/requests/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.reason_code', 'CANCELLED_BY_OPERATOR');
        $this->actingAs($admin)->deleteJson("/api/v1/civil-registry/requests/{$id}")->assertStatus(409)->assertJsonPath('code', 'REQUEST_NOT_CANCELLABLE');
        $this->actingAs($admin)->deleteJson('/api/v1/civil-registry/requests/'.self::RUN)->assertNotFound();

        // A RUNNING request: the cancellation is recorded for the worker (202); the request stays RUNNING.
        $running = $this->validate($admin)->json('data.id');
        CivilImportRequest::query()->find($running)->forceFill(['status' => 'RUNNING', 'started_at' => now(), 'attempts' => 1])->save();
        $this->actingAs($admin)->deleteJson("/api/v1/civil-registry/requests/{$running}")->assertStatus(202)
            ->assertJsonPath('data.status', 'RUNNING')->assertJsonPath('data.cancel_requested', true)->assertJsonPath('data.can_cancel', false);
        $this->assertSame(['REQUEST_QUEUED', 'REQUEST_CANCELLED', 'REQUEST_QUEUED', 'REQUEST_CANCELLED'],
            CivilImportAuditEvent::query()->orderBy('id')->pluck('event_type')->all());
    }

    public function test_responses_are_redacted(): void
    {
        $admin = $this->user('SUPER_ADMIN');
        $id = $this->validate($admin)->json('data.id');
        CivilImportRequest::query()->find($id)->forceFill(['status' => 'RUNNING', 'started_at' => now(), 'attempts' => 1])->save();
        CivilImportRequest::query()->find($id)->forceFill(['status' => 'FAILED', 'finished_at' => now(), 'reason_code' => 'SOURCE_INVALID',
            'result' => CivilImportResult::sanitize(['problems' => ['CHECKSUM_MISMATCH' => 1], 'path' => '/var/lib/x', 'file' => 't03maindata_part_00001.csv'])])->save();

        $sources = $this->actingAs($admin)->getJson('/api/v1/civil-registry/sources')->assertOk()
            ->assertJsonPath('data.0.last_validation.status', 'FAILED')->assertJsonPath('data.0.last_validation.problems.CHECKSUM_MISMATCH', 1);
        $request = $this->actingAs($admin)->getJson("/api/v1/civil-registry/requests/{$id}")->assertOk()
            ->assertJsonPath('data.result', ['problems' => ['CHECKSUM_MISMATCH' => 1]]);
        foreach ([$sources->getContent(), $request->getContent()] as $json) {
            foreach ([str_repeat('a', 64), 'manifest_sha256', '/var/lib', 't03maindata_part', 'path'] as $needle) {
                $this->assertStringNotContainsString($needle, $json);
            }
        }
        // The source label is for civil-import.stage holders only (O-2).
        $viewer = $this->user('REPORTS_VIEWER', ['civil-import.view']);
        $this->actingAs($viewer)->getJson("/api/v1/civil-registry/requests/{$id}")->assertOk()
            ->assertJsonPath('data.source.label', null)->assertJsonPath('data.can_cancel', false);
    }

    public function test_the_model_enforces_the_request_state_machine(): void
    {
        $admin = $this->user('SUPER_ADMIN');
        $request = CivilImportRequest::query()->find($this->validate($admin)->json('data.id'));
        $refused = function (array $changes) use ($request) {
            try {
                $request->fresh()->forceFill($changes)->save();
                $this->fail('accepted '.json_encode($changes));
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        };
        $refused(['status' => 'SUCCEEDED', 'finished_at' => now()]);
        $refused(['type' => 'STAGE']);
        $refused(['requested_by' => null]);
        $request->fresh()->forceFill(['status' => 'RUNNING', 'started_at' => now(), 'attempts' => 1])->save();
        $refused(['status' => 'CANCELLED', 'finished_at' => now()]);   // without a cancellation request
        $refused(['status' => 'QUEUED']);
        $request->fresh()->forceFill(['status' => 'FAILED', 'finished_at' => now(), 'reason_code' => 'WORKER_LOST'])->save();
        $refused(['reason_code' => 'OTHER']);
        $this->expectException(LogicException::class);
        $request->fresh()->delete();
    }

    public function test_results_keep_counts_and_codes_only(): void
    {
        $clean = CivilImportResult::sanitize([
            'run_id' => '00000000-0000-4000-8000-0000000000b1', 'rows_staged' => 8, 'resumed' => true,
            'issues' => ['MAINID_DUPLICATE' => 2, 'bad key with t03maindata_part_00001.csv' => 1],
            'problems' => ['FILE_MISSING t03maindata_part_00002.csv' => 1],
            'fatal' => ['STAGING_INCOMPLETE', '/etc/passwd'], 'counts' => ['new' => 3, 'secret' => 9],
            'directory' => '/var/lib/famboook-import/x', 'manifest_sha256' => str_repeat('b', 64), 'rows' => [['mainid' => '900000001']],
            'policy_id' => 'IDREF-POLICY-1', 'release_status' => 'BUILDING DROP TABLE x', 'preparation' => 'prepared; drop', 'seconds' => 1.5, 'duration_seconds' => 2.6,
        ]);
        $this->assertSame([
            'counts' => ['new' => 3],
            'duration_seconds' => 3,
            'fatal' => ['STAGING_INCOMPLETE', 'OTHER'],
            'issues' => ['MAINID_DUPLICATE' => 2, 'OTHER' => 1],
            'policy_id' => 'IDREF-POLICY-1',
            'problems' => ['FILE_MISSING' => 1],
            'release_status' => 'BUILDING',
            'resumed' => true,
            'rows_staged' => 8,
            'run_id' => '00000000-0000-4000-8000-0000000000b1',
        ], $clean);
        $this->assertSame(['FILE_IS_SYMLINK' => 2, 'OTHER' => 1], CivilImportResult::problemCodes(['FILE_IS_SYMLINK a.csv', 'FILE_IS_SYMLINK b.csv', '../x']));
    }
}
