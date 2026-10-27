<?php

namespace Tests\Feature\CivilRegistry;

use App\Actions\DecideCivilImportPublicationAction;
use App\Exceptions\CivilImportRequestException;
use App\Models\CivilImportAuditEvent;
use App\Models\CivilImportPublicationRequest;
use App\Models\CivilImportRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-UI U3 / D-UI-12a — single-operator publication rules that need
 * no identity schema (default SQLite suite too): permission boundaries, an
 * active SUPER_ADMIN confirming their OWN request only, fresh password
 * re-authentication (audited, rate-limited), the closed-by-default gate, the
 * typed confirmation, the 24-hour expiry, no reuse of closed requests,
 * fail-closed confirmation without the monitor, the model's state machine,
 * redaction and the policy-change migration. The confirmation-to-activation
 * path runs in CivilImportPublicationPostgresTest. SYNTHETIC data only.
 */
class CivilImportPublicationApiTest extends TestCase
{
    use RefreshDatabase;

    private const RUN = '00000000-0000-4000-8000-0000000000a9';

    /** UserFactory's synthetic password. */
    private const PASSWORD = 'password';

    private User $requester;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['civil_import.monitor_credentials_file' => base_path('storage/framework/testing/no-such-identity-monitor.php')]);
        // The publication gate open in THIS process only.
        config(['civil_import.publish_enabled' => true]);
        $this->seed(RolePermissionSeeder::class);
        $this->requester = User::factory()->create();
        $this->requester->assignRole('SUPER_ADMIN');
        $this->otherAdmin = User::factory()->create();
        $this->otherAdmin->assignRole('SUPER_ADMIN');
    }

    protected function tearDown(): void
    {
        RateLimiter::clear(DecideCivilImportPublicationAction::reauthKey($this->requester));
        parent::tearDown();
    }

    private function publication(array $overrides = []): CivilImportPublicationRequest
    {
        return CivilImportPublicationRequest::query()->create($overrides + [
            'release_id' => 4, 'run_id' => self::RUN, 'base_release_id' => 3, 'requested_by' => $this->requester->id,
            'justification_code' => 'ROUTINE_REFRESH', 'snapshot' => ['expected_presence' => 1200, 'new' => 10],
            'expires_at' => Carbon::now()->addHours(24),
        ])->refresh();
    }

    private function confirm(User $user, CivilImportPublicationRequest $p, array $body = []): TestResponse
    {
        return $this->actingAs($user)->postJson("/api/v1/civil-registry/publication-requests/{$p->id}/confirm",
            $body + ['password' => self::PASSWORD, 'confirm_release_id' => 4, 'confirm_expected_presence' => 1200]);
    }

    public function test_each_publication_endpoint_needs_its_own_permission(): void
    {
        $p = $this->publication();
        $paths = [['get', '/publication-requests'], ['get', "/publication-requests/{$p->id}"], ['post', '/releases/4/publication-requests'],
            ['post', "/publication-requests/{$p->id}/withdraw"], ['post', "/publication-requests/{$p->id}/confirm"]];
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
        // Viewing does not allow requesting or confirming.
        $viewer = User::factory()->create();
        $viewer->assignRole('REPORTS_VIEWER');
        $viewer->givePermissionTo('civil-import.view');
        $this->actingAs($viewer)->getJson('/api/v1/civil-registry/publication-requests')->assertOk();
        foreach (array_slice($paths, 2) as [$method, $uri]) {
            $this->actingAs($viewer)->json($method, '/api/v1/civil-registry'.$uri)->assertForbidden();
        }
        // The former approval and rejection endpoints are gone.
        $this->actingAs($this->otherAdmin)->postJson("/api/v1/civil-registry/publication-requests/{$p->id}/approve", [])->assertNotFound();
        $this->actingAs($this->otherAdmin)->postJson("/api/v1/civil-registry/publication-requests/{$p->id}/reject", [])->assertNotFound();
        $this->assertSame(CivilImportPublicationRequest::PENDING, $p->fresh()->status);
    }

    public function test_only_an_active_super_admin_holding_the_permission_confirms(): void
    {
        // The confirm permission alone (a direct grant to another role) is not enough: SUPER_ADMIN is required too.
        $notAdmin = User::factory()->create();
        $notAdmin->assignRole('ADMINISTRATOR');
        $notAdmin->givePermissionTo(['civil-import.view', 'civil-import.publish.request', 'civil-import.publish.confirm']);
        $own = $this->publication(['requested_by' => $notAdmin->id]);
        $this->confirm($notAdmin, $own)->assertForbidden()->assertJsonPath('code', 'NOT_ALLOWED');
        $own->forceFill(['status' => 'WITHDRAWN', 'finished_at' => now()])->save();

        // Requesting does not allow confirming.
        $this->requester->roles()->detach();
        $this->requester->givePermissionTo(['civil-import.view', 'civil-import.publish.request']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $p = $this->publication();
        $this->confirm($this->requester->fresh(), $p)->assertForbidden();
        $this->requester->syncPermissions([]);
        $this->requester->assignRole('SUPER_ADMIN');

        // A SUPER_ADMIN deactivated during the session is signed out before anything happens (EnsureUserIsActive)…
        $this->actingAs($this->requester)->getJson("/api/v1/civil-registry/publication-requests/{$p->id}")->assertOk();
        User::whereKey($this->requester->id)->update(['is_active' => false]);
        Auth::guard('web')->setUser($this->requester->fresh());
        $this->postJson("/api/v1/civil-registry/publication-requests/{$p->id}/confirm",
            ['password' => self::PASSWORD, 'confirm_release_id' => 4, 'confirm_expected_presence' => 1200])->assertUnauthorized();
        Auth::forgetGuards();
        // …and the action refuses an inactive account on its own too.
        try {
            app(DecideCivilImportPublicationAction::class)->confirm($this->requester->fresh(), $p->id, self::PASSWORD, 4, 1200);
            $this->fail('An inactive SUPER_ADMIN must not confirm.');
        } catch (CivilImportRequestException $e) {
            $this->assertSame('NOT_ALLOWED', $e->reason);
        }
        User::whereKey($this->requester->id)->update(['is_active' => true]);

        $this->assertSame(CivilImportPublicationRequest::PENDING, $p->fresh()->status);
        $this->assertSame(0, CivilImportRequest::query()->count());
        $this->assertFalse(CivilImportAuditEvent::query()->where('event_type', 'PUBLICATION_CONFIRMED')->exists());
    }

    public function test_only_the_requester_confirms_their_own_request(): void
    {
        $p = $this->publication();
        // Another SUPER_ADMIN cannot confirm it — there is no second approver any more.
        $this->confirm($this->otherAdmin, $p)->assertForbidden()->assertJsonPath('code', 'CONFIRMATION_BY_REQUESTER_ONLY');
        $this->assertSame(CivilImportPublicationRequest::PENDING, $p->fresh()->status);

        // The model refuses a confirmation by anybody else (and so does the database, PostgreSQL test).
        $this->expectException(LogicException::class);
        $p->forceFill(['status' => 'APPROVED', 'decided_by' => $this->otherAdmin->id, 'decided_at' => now()])->save();
    }

    public function test_a_wrong_password_is_refused_audited_and_rate_limited(): void
    {
        $p = $this->publication();
        $this->confirm($this->requester, $p, ['password' => ''])->assertStatus(422)->assertJsonValidationErrors('password');
        $response = $this->confirm($this->requester, $p, ['password' => 'not-the-password'])->assertStatus(422)->assertJsonPath('code', 'REAUTH_FAILED');
        $this->assertStringNotContainsString('not-the-password', $response->getContent());
        $failure = CivilImportAuditEvent::query()->where('event_type', 'PUBLICATION_REAUTH_FAILED')->sole();
        $this->assertSame([$this->requester->id, $p->id, 'REAUTH_FAILED', null],
            [$failure->actor_user_id, $failure->publication_request_id, $failure->outcome_code, $failure->details]);
        $this->assertSame(CivilImportPublicationRequest::PENDING, $p->fresh()->status);

        // After five wrong passwords even the right one is refused for 15 minutes.
        for ($i = 2; $i <= DecideCivilImportPublicationAction::REAUTH_MAX_ATTEMPTS; $i++) {
            $this->confirm($this->requester, $p, ['password' => "wrong-{$i}"])->assertStatus(422);
        }
        $this->confirm($this->requester, $p)->assertStatus(429)->assertJsonPath('code', 'REAUTH_LOCKED');
        $this->assertSame(DecideCivilImportPublicationAction::REAUTH_MAX_ATTEMPTS,
            CivilImportAuditEvent::query()->where('event_type', 'PUBLICATION_REAUTH_FAILED')->count());
        $this->travel(DecideCivilImportPublicationAction::REAUTH_DECAY_SECONDS + 1)->seconds();
        // Unlocked: the password is accepted; the request then fails closed on the missing monitor.
        $this->confirm($this->requester, $p)->assertStatus(503)->assertJsonPath('code', 'MONITOR_NOT_CONFIGURED');
        $this->travelBack();

        $this->assertSame(0, CivilImportRequest::query()->count());
    }

    public function test_confirmation_needs_the_gate_the_typed_confirmation_and_fails_closed_without_the_monitor(): void
    {
        $p = $this->publication();
        config(['civil_import.publish_enabled' => false]);
        $this->confirm($this->requester, $p)->assertStatus(409)->assertJsonPath('code', 'PUBLICATION_GATE_CLOSED');
        config(['civil_import.publish_enabled' => true]);
        $this->confirm($this->requester, $p, ['confirm_expected_presence' => 1199])->assertStatus(422)->assertJsonPath('code', 'CONFIRMATION_MISMATCH');
        $this->confirm($this->requester, $p, ['confirm_release_id' => 5])->assertStatus(422)->assertJsonPath('code', 'CONFIRMATION_MISMATCH');
        // Baseline and release cannot be re-checked without the monitor: no confirmation.
        $this->confirm($this->requester, $p)->assertStatus(503)->assertJsonPath('code', 'MONITOR_NOT_CONFIGURED');
        $this->assertSame(CivilImportPublicationRequest::PENDING, $p->fresh()->status);
        $this->assertSame(0, CivilImportRequest::query()->count());
    }

    public function test_requests_expire_after_24_hours_and_closed_requests_are_never_reused(): void
    {
        $p = $this->publication();
        $this->travel(24)->hours();
        $this->actingAs($this->requester)->getJson("/api/v1/civil-registry/publication-requests/{$p->id}")->assertOk()
            ->assertJsonPath('data.status', 'EXPIRED')->assertJsonPath('data.outcome_code', 'TIMEOUT')->assertJsonPath('data.can_confirm', false);
        $this->confirm($this->requester, $p)->assertStatus(409)->assertJsonPath('code', 'PUBLICATION_EXPIRED');
        $this->travelBack();
        $this->assertTrue(CivilImportAuditEvent::query()->where('event_type', 'PUBLICATION_EXPIRED')->exists());

        $withdrawn = $this->publication();
        $this->actingAs($this->otherAdmin)->postJson("/api/v1/civil-registry/publication-requests/{$withdrawn->id}/withdraw")
            ->assertForbidden()->assertJsonPath('code', 'NOT_ALLOWED');   // only the requester withdraws
        $this->actingAs($this->requester)->postJson("/api/v1/civil-registry/publication-requests/{$withdrawn->id}/withdraw")
            ->assertOk()->assertJsonPath('data.status', 'WITHDRAWN');
        $this->confirm($this->requester, $withdrawn)->assertStatus(409)->assertJsonPath('code', 'PUBLICATION_NOT_PENDING');
        $this->actingAs($this->requester)->postJson("/api/v1/civil-registry/publication-requests/{$withdrawn->id}/withdraw")
            ->assertStatus(409)->assertJsonPath('code', 'PUBLICATION_NOT_PENDING');
        // A closed request is not even re-authenticated against: no password attempt is spent or audited.
        $this->assertFalse(CivilImportAuditEvent::query()->where('event_type', 'PUBLICATION_REAUTH_FAILED')->exists());

        $this->expectException(LogicException::class);
        $withdrawn->fresh()->forceFill(['status' => 'PENDING_APPROVAL'])->save();
    }

    public function test_rejection_no_longer_exists_in_the_state_machine(): void
    {
        $p = $this->publication();
        $this->expectException(LogicException::class);
        $p->forceFill(['status' => 'REJECTED', 'decided_by' => $this->otherAdmin->id, 'decided_at' => now(), 'finished_at' => now()])->save();
    }

    public function test_responses_hold_codes_counts_and_names_only(): void
    {
        $p = $this->publication();
        $json = $this->actingAs($this->otherAdmin)->getJson('/api/v1/civil-registry/publication-requests')->assertOk()
            ->assertJsonPath('data.0.id', $p->id)->assertJsonPath('data.0.snapshot.expected_presence', 1200)
            ->assertJsonPath('data.0.can_confirm', false)->assertJsonPath('data.0.can_withdraw', false)->getContent();
        $this->actingAs($this->requester)->getJson("/api/v1/civil-registry/publication-requests/{$p->id}")
            ->assertJsonPath('data.is_own', true)->assertJsonPath('data.can_confirm', true)->assertJsonPath('data.can_withdraw', true);
        $keys = array_keys(json_decode($json, true)['data'][0]);
        sort($keys);
        $this->assertSame(['base_release_id', 'can_confirm', 'can_withdraw', 'decided_at', 'decided_by', 'decision_reason_code',
            'expires_at', 'finished_at', 'id', 'is_own', 'justification_code', 'outcome_code', 'publish_request', 'release_id', 'requested_at',
            'requested_by', 'run_id', 'snapshot', 'status'], $keys);
        $this->assertStringNotContainsString($this->requester->email, $json);
        // A gate closed in this process: nobody may confirm, the UI is told so.
        config(['civil_import.publish_enabled' => false]);
        $this->actingAs($this->requester)->getJson("/api/v1/civil-registry/publication-requests/{$p->id}")->assertJsonPath('data.can_confirm', false);
    }

    public function test_a_publish_request_cannot_be_queued_through_the_execution_api(): void
    {
        $this->actingAs($this->requester)->postJson('/api/v1/civil-registry/releases/4/publish')->assertNotFound();
        $this->assertNotContains(CivilImportRequest::PUBLISH, CivilImportRequest::TYPES);
        $this->assertSame(0, CivilImportRequest::query()->count());
    }

    public function test_the_policy_migration_expires_pending_two_person_requests_and_removes_the_approver_permission(): void
    {
        $migration = require database_path('migrations/2026_10_27_090000_civil_import_single_operator_publication.php');
        $pending = $this->publication();
        Permission::findOrCreate('civil-import.publish.approve', 'web')->assignRole('SUPER_ADMIN');

        $migration->up();

        // Never confirmed or published: expired with its cause, audited as a system event.
        $this->assertSame(['EXPIRED', 'POLICY_CHANGED', null], [$pending->fresh()->status, $pending->fresh()->outcome_code, $pending->fresh()->decided_by]);
        $event = CivilImportAuditEvent::query()->where('event_type', 'PUBLICATION_EXPIRED')->where('publication_request_id', $pending->id)->sole();
        $this->assertSame(['SYSTEM', 'POLICY_CHANGED'], [$event->actor_side, $event->outcome_code]);
        $this->assertSame(0, CivilImportRequest::query()->count());
        $this->assertFalse(Permission::query()->where('name', 'civil-import.publish.approve')->exists());

        // A direct grant of the old permission stops the migration (it may only come from the role).
        Permission::findOrCreate('civil-import.publish.approve', 'web');
        $this->otherAdmin->givePermissionTo('civil-import.publish.approve');
        try {
            $migration->up();
            $this->fail('A direct grant must stop the migration.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('granted directly', $e->getMessage());
        }
        $this->assertTrue(DB::table('model_has_permissions')->exists());
    }
}
