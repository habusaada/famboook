<?php

namespace Tests\Feature\CivilRegistry;

use App\Models\CivilImportAuditEvent;
use App\Models\CivilImportPublicationRequest;
use App\Models\CivilImportRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-UI U3 — publication approval rules that need no identity
 * schema (default SQLite suite too): permission boundaries, requester /
 * approver separation, the closed-by-default gate, the 24-hour expiry, no
 * reuse of closed requests, fail-closed approval without the monitor, the
 * model's state machine and redaction. The approval-to-activation path runs
 * in CivilImportPublicationPostgresTest. SYNTHETIC data only.
 */
class CivilImportPublicationApiTest extends TestCase
{
    use RefreshDatabase;

    private const RUN = '00000000-0000-4000-8000-0000000000a9';

    private User $requester;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        config(['civil_import.monitor_credentials_file' => base_path('storage/framework/testing/no-such-identity-monitor.php')]);
        // Two SUPER_ADMIN accounts (O-3), with the publication gate open in THIS process only.
        config(['civil_import.publish_enabled' => true]);
        $this->seed(RolePermissionSeeder::class);
        $this->requester = User::factory()->create();
        $this->requester->assignRole('SUPER_ADMIN');
        $this->approver = User::factory()->create();
        $this->approver->assignRole('SUPER_ADMIN');
    }

    private function publication(array $overrides = []): CivilImportPublicationRequest
    {
        return CivilImportPublicationRequest::query()->create($overrides + [
            'release_id' => 4, 'run_id' => self::RUN, 'base_release_id' => 3, 'requested_by' => $this->requester->id,
            'justification_code' => 'ROUTINE_REFRESH', 'snapshot' => ['expected_presence' => 1200, 'new' => 10],
            'expires_at' => Carbon::now()->addHours(24),
        ])->refresh();
    }

    private function approve(User $user, CivilImportPublicationRequest $p, array $body = []): TestResponse
    {
        return $this->actingAs($user)->postJson("/api/v1/civil-registry/publication-requests/{$p->id}/approve",
            $body + ['confirm_release_id' => 4, 'confirm_expected_presence' => 1200]);
    }

    public function test_each_publication_endpoint_needs_its_own_permission(): void
    {
        $p = $this->publication();
        $paths = [['get', '/publication-requests'], ['get', "/publication-requests/{$p->id}"], ['post', '/releases/4/publication-requests'],
            ['post', "/publication-requests/{$p->id}/withdraw"], ['post', "/publication-requests/{$p->id}/approve"], ['post', "/publication-requests/{$p->id}/reject"]];
        foreach ($paths as [$method, $uri]) {
            $this->json($method, '/api/v1/civil-registry'.$uri)->assertUnauthorized();
        }
        foreach (['ADMINISTRATOR', 'DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            foreach ($paths as [$method, $uri]) {
                $this->actingAs($user)->json($method, '/api/v1/civil-registry'.$uri)->assertForbidden();
            }
        }
        // Viewing does not allow requesting or deciding (no release information is inferred beyond the 403).
        $viewer = User::factory()->create();
        $viewer->assignRole('REPORTS_VIEWER');
        $viewer->givePermissionTo('civil-import.view');
        $this->actingAs($viewer)->getJson('/api/v1/civil-registry/publication-requests')->assertOk();
        foreach (array_slice($paths, 2) as [$method, $uri]) {
            $this->actingAs($viewer)->json($method, '/api/v1/civil-registry'.$uri)->assertForbidden();
        }
        // Requesting does not allow approving.
        $onlyRequest = User::factory()->create();
        $onlyRequest->assignRole('REPORTS_VIEWER');
        $onlyRequest->givePermissionTo(['civil-import.view', 'civil-import.publish.request']);
        $this->approve($onlyRequest, $p)->assertForbidden();
        $this->assertSame(CivilImportPublicationRequest::PENDING, $p->fresh()->status);
    }

    public function test_the_requester_can_never_decide_their_own_request(): void
    {
        $p = $this->publication();
        $this->approve($this->requester, $p)->assertStatus(409)->assertJsonPath('code', 'SAME_USER_APPROVAL');
        $this->actingAs($this->requester)->postJson("/api/v1/civil-registry/publication-requests/{$p->id}/reject", ['reason_code' => 'NEEDS_REVIEW'])
            ->assertStatus(409)->assertJsonPath('code', 'SAME_USER_APPROVAL');
        // The database refuses it too (and the model).
        $this->expectException(LogicException::class);
        $p->forceFill(['status' => 'APPROVED', 'decided_by' => $this->requester->id, 'decided_at' => now()])->save();
    }

    public function test_approval_needs_the_gate_the_typed_confirmation_and_fails_closed_without_the_monitor(): void
    {
        $p = $this->publication();
        config(['civil_import.publish_enabled' => false]);
        $this->approve($this->approver, $p)->assertStatus(409)->assertJsonPath('code', 'PUBLICATION_GATE_CLOSED');
        config(['civil_import.publish_enabled' => true]);
        $this->approve($this->approver, $p, ['confirm_expected_presence' => 1199])->assertStatus(422)->assertJsonPath('code', 'CONFIRMATION_MISMATCH');
        $this->approve($this->approver, $p, ['confirm_release_id' => 5])->assertStatus(422)->assertJsonPath('code', 'CONFIRMATION_MISMATCH');
        // Baseline and release cannot be checked without the monitor: no approval.
        $this->approve($this->approver, $p)->assertStatus(503)->assertJsonPath('code', 'MONITOR_NOT_CONFIGURED');
        $this->assertSame(CivilImportPublicationRequest::PENDING, $p->fresh()->status);
        $this->assertSame(0, CivilImportRequest::query()->count());
    }

    public function test_requests_expire_after_24_hours_and_closed_requests_are_never_reused(): void
    {
        $p = $this->publication();
        $this->travel(24)->hours();
        $this->actingAs($this->approver)->getJson("/api/v1/civil-registry/publication-requests/{$p->id}")->assertOk()
            ->assertJsonPath('data.status', 'EXPIRED')->assertJsonPath('data.outcome_code', 'TIMEOUT')->assertJsonPath('data.can_approve', false);
        $this->approve($this->approver, $p)->assertStatus(409)->assertJsonPath('code', 'PUBLICATION_EXPIRED');
        $this->travelBack();
        $this->assertTrue(CivilImportAuditEvent::query()->where('event_type', 'PUBLICATION_EXPIRED')->exists());

        $rejected = $this->publication();
        $this->actingAs($this->approver)->postJson("/api/v1/civil-registry/publication-requests/{$rejected->id}/reject", ['reason_code' => 'COUNTS_UNEXPECTED'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED')->assertJsonPath('data.decided_by.name', $this->approver->name);
        $this->approve($this->approver, $rejected)->assertStatus(409)->assertJsonPath('code', 'PUBLICATION_NOT_PENDING');
        $this->actingAs($this->requester)->postJson("/api/v1/civil-registry/publication-requests/{$rejected->id}/withdraw")
            ->assertStatus(409)->assertJsonPath('code', 'PUBLICATION_NOT_PENDING');

        $withdrawn = $this->publication();
        $this->actingAs($this->approver)->postJson("/api/v1/civil-registry/publication-requests/{$withdrawn->id}/withdraw")
            ->assertForbidden()->assertJsonPath('code', 'NOT_ALLOWED');   // only the requester withdraws
        $this->actingAs($this->requester)->postJson("/api/v1/civil-registry/publication-requests/{$withdrawn->id}/withdraw")
            ->assertOk()->assertJsonPath('data.status', 'WITHDRAWN');
        $this->approve($this->approver, $withdrawn)->assertStatus(409)->assertJsonPath('code', 'PUBLICATION_NOT_PENDING');

        $this->expectException(LogicException::class);
        $withdrawn->fresh()->forceFill(['status' => 'PENDING_APPROVAL'])->save();
    }

    public function test_responses_hold_codes_counts_and_names_only(): void
    {
        $p = $this->publication();
        $json = $this->actingAs($this->approver)->getJson('/api/v1/civil-registry/publication-requests')->assertOk()
            ->assertJsonPath('data.0.id', $p->id)->assertJsonPath('data.0.snapshot.expected_presence', 1200)
            ->assertJsonPath('data.0.can_approve', true)->assertJsonPath('data.0.can_withdraw', false)->getContent();
        $this->actingAs($this->requester)->getJson("/api/v1/civil-registry/publication-requests/{$p->id}")
            ->assertJsonPath('data.is_own', true)->assertJsonPath('data.can_approve', false)->assertJsonPath('data.can_withdraw', true);
        $keys = array_keys(json_decode($json, true)['data'][0]);
        sort($keys);
        $this->assertSame(['base_release_id', 'can_approve', 'can_reject', 'can_withdraw', 'decided_at', 'decided_by', 'decision_reason_code',
            'expires_at', 'finished_at', 'id', 'is_own', 'justification_code', 'outcome_code', 'publish_request', 'release_id', 'requested_at',
            'requested_by', 'run_id', 'snapshot', 'status'], $keys);
        $this->assertStringNotContainsString($this->requester->email, $json);
        // A gate closed in this process: nobody may approve, the UI is told so.
        config(['civil_import.publish_enabled' => false]);
        $this->actingAs($this->approver)->getJson("/api/v1/civil-registry/publication-requests/{$p->id}")->assertJsonPath('data.can_approve', false);
    }

    public function test_a_publish_request_cannot_be_queued_through_the_execution_api(): void
    {
        $this->actingAs($this->approver)->postJson('/api/v1/civil-registry/releases/4/publish')->assertNotFound();
        $this->assertNotContains(CivilImportRequest::PUBLISH, CivilImportRequest::TYPES);
        $this->assertSame(0, CivilImportRequest::query()->count());
    }
}
