<?php

namespace Tests\Feature\Credentials;

use App\Actions\IssueFamilyCredentialAction;
use App\Enums\CredentialIssueChannel;
use App\Http\Controllers\Api\V1\FamilyCredentialController;
use App\Models\DigitalCredential;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\User;
use App\Support\Credentials\CredentialTokens;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-8.2: Staff management of the Digital Family Card — view, issue, revoke,
 * reissue on /api/v1/families/{family_code}/card, each behind its own
 * family-card.* permission and staff.side, through Domain Actions. Never a
 * token or QR in a Staff response. Synthetic data only.
 */
class StaffFamilyCardTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function staff(string $role, string $name = 'موظف تجريبي'): User
    {
        return tap(User::factory()->create(['name' => $name]), fn (User $u) => $u->assignRole($role));
    }

    private function cardRequest(User $as, string $method, Family $family, string $suffix = '', array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as)->json($method, "/api/v1/families/{$family->family_code}/card{$suffix}", $body);
    }

    public function test_the_permission_matrix_follows_the_seeded_roles(): void
    {
        $expected = [
            'SUPER_ADMIN' => [true, true, true, true],
            'ADMINISTRATOR' => [true, true, true, true],
            'DATA_ENTRY' => [true, false, false, false],
            'SOCIAL_WORKER' => [true, false, false, false],
            'REVIEWER' => [false, false, false, false],
            'REPORTS_VIEWER' => [false, false, false, false],
        ];
        $this->assertEqualsCanonicalizing(StaffRoles::ALL, array_keys($expected));

        foreach ($expected as $role => [$view, $issue, $revoke, $reissue]) {
            $user = $this->staff($role);
            $family = Family::factory()->create();
            $this->assertSame($view, $this->cardRequest($user, 'GET', $family)->status() === 200, "{$role} view");
            $this->assertSame($issue, $this->cardRequest($user, 'POST', $family)->status() === 201, "{$role} issue");
            if (! $issue) {
                app(IssueFamilyCredentialAction::class)->handle($family, CredentialIssueChannel::STAFF, null);
            }
            $this->assertSame($reissue, $this->cardRequest($user, 'POST', $family, '/reissue')->status() === 200, "{$role} reissue");
            $this->assertSame($revoke, $this->cardRequest($user, 'POST', $family, '/revoke', ['reason' => 'ADMINISTRATIVE'])->status() === 200, "{$role} revoke");
        }
    }

    public function test_view_returns_the_active_card_and_the_history_without_any_token(): void
    {
        $admin = $this->staff('ADMINISTRATOR', 'مسؤول البطاقات');
        $family = Family::factory()->create();
        $this->cardRequest($admin, 'POST', $family)->assertCreated();
        $this->cardRequest($admin, 'POST', $family, '/reissue')->assertOk();
        $this->cardRequest($admin, 'POST', $family, '/revoke', ['reason' => 'COMPROMISED'])->assertOk();
        $this->cardRequest($admin, 'POST', $family)->assertCreated();

        $response = $this->cardRequest($this->staff('DATA_ENTRY'), 'GET', $family)->assertOk();

        $this->assertSame(['active', 'history'], array_keys($response->json('data')));
        $this->assertSame(['credential_number', 'status', 'issued_at', 'issued_by', 'revoked_at', 'revoked_by', 'revoke_reason'], array_keys($response->json('data.active')));
        $this->assertSame(['ACTIVE', 'REVOKED', 'REVOKED'], array_column($response->json('data.history'), 'status'));
        $this->assertSame([null, 'COMPROMISED', 'REISSUED'], array_column($response->json('data.history'), 'revoke_reason'));
        $this->assertSame('مسؤول البطاقات', $response->json('data.active.issued_by'));
        $raw = $response->getContent();
        foreach (DigitalCredential::all() as $credential) {
            $this->assertStringNotContainsString(CredentialTokens::reveal($credential), $raw);
            $this->assertStringNotContainsString($credential->token_hash, $raw);
        }
        foreach (['token', 'qr', 'verification_url', '"id"', 'family_id', 'svg'] as $key) {
            $this->assertStringNotContainsString($key, $raw, $key);
        }
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_family_portal_issued_card_shows_no_issuer_and_activity_is_recorded(): void
    {
        $family = Family::factory()->create();
        app(IssueFamilyCredentialAction::class)->handle($family, CredentialIssueChannel::FAMILY_PORTAL, null);

        $this->cardRequest($this->staff('ADMINISTRATOR'), 'GET', $family)->assertOk()->assertJsonPath('data.active.issued_by', null);
        $this->assertSame(1, FamilyActivity::where('family_id', $family->id)->where('event_type', 'FAMILY_CARD_ISSUED')->count());
    }

    public function test_refusals_are_409_and_validation_422(): void
    {
        $admin = $this->staff('ADMINISTRATOR');
        $family = Family::factory()->create();

        $this->cardRequest($admin, 'POST', $family, '/revoke', ['reason' => 'ADMINISTRATIVE'])->assertStatus(409)->assertJsonPath('code', 'NO_ACTIVE_CARD');
        $this->cardRequest($admin, 'POST', $family, '/reissue')->assertStatus(409)->assertJsonPath('code', 'NO_ACTIVE_CARD');
        $this->cardRequest($admin, 'POST', $family)->assertCreated();
        $this->cardRequest($admin, 'POST', $family)->assertStatus(409)->assertJsonPath('code', 'CARD_ALREADY_ACTIVE');
        foreach ([[], ['reason' => 'REISSUED'], ['reason' => 'OTHER']] as $body) {
            $this->cardRequest($admin, 'POST', $family, '/revoke', $body)->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $inactive = Family::factory()->create(['status' => 'INACTIVE']);
        $this->cardRequest($admin, 'POST', $inactive)->assertStatus(409)->assertJsonPath('code', 'FAMILY_NOT_ACTIVE');
    }

    public function test_the_issuance_switch_blocks_issue_and_reissue_but_not_revoke(): void
    {
        $admin = $this->staff('ADMINISTRATOR');
        $withCard = Family::factory()->create();
        $this->cardRequest($admin, 'POST', $withCard)->assertCreated();
        config(['credentials.family_card_issuance_enabled' => false]);

        $this->cardRequest($admin, 'POST', Family::factory()->create())->assertStatus(503)->assertJsonPath('code', 'ISSUANCE_DISABLED');
        $this->cardRequest($admin, 'POST', $withCard, '/reissue')->assertStatus(503)->assertJsonPath('code', 'ISSUANCE_DISABLED');
        $this->cardRequest($admin, 'POST', $withCard, '/revoke', ['reason' => 'ADMINISTRATIVE'])->assertOk()->assertJsonPath('data.active', null);
    }

    public function test_family_side_accounts_never_reach_the_staff_card_routes(): void
    {
        $this->useFamilyAuthKey();
        $head = $this->activatedHead();

        foreach ([['GET', ''], ['POST', ''], ['POST', '/revoke'], ['POST', '/reissue']] as [$method, $suffix]) {
            $this->cardRequest($head['user'], $method, $head['family'], $suffix)->assertForbidden();
        }
        $this->assertSame(0, DigitalCredential::count());
    }

    public function test_the_routes_sit_behind_the_staff_boundary_with_their_own_permission(): void
    {
        $expected = [
            'show' => ['GET', 'api/v1/families/{family}/card', 'can:family-card.view'],
            'issue' => ['POST', 'api/v1/families/{family}/card', 'can:family-card.issue'],
            'revoke' => ['POST', 'api/v1/families/{family}/card/revoke', 'can:family-card.revoke'],
            'reissue' => ['POST', 'api/v1/families/{family}/card/reissue', 'can:family-card.reissue'],
        ];
        foreach ($expected as $action => [$method, $uri, $permission]) {
            $route = Route::getRoutes()->getByAction(FamilyCredentialController::class.'@'.$action);
            $this->assertSame($uri, $route->uri());
            $this->assertContains($method, $route->methods());
            foreach (['auth:sanctum', 'staff.side', $permission] as $middleware) {
                $this->assertContains($middleware, $route->gatherMiddleware(), "{$action}: {$middleware}");
            }
        }
    }
}
