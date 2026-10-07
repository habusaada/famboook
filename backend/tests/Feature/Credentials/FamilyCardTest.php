<?php

namespace Tests\Feature\Credentials;

use App\Actions\RevokeFamilyCredentialAction;
use App\Enums\CredentialRevokeReason;
use App\Enums\UserPersonLinkStatus;
use App\Http\Controllers\Api\V1\Family\FamilyCardController;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Models\DigitalCredential;
use App\Models\FamilyActivity;
use App\Models\User;
use App\Support\Credentials\CredentialTokens;
use App\Support\StaffRoles;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-8.2: POST /api/v1/family/card — «بطاقة الأسرة الرقمية» for the signed-in
 * household head. Ensures (lazily issues once) and returns the Family's
 * ACTIVE card with its QR; the Family comes only from family.context. The
 * only PWA-8.2 path that decrypts the stored token. Synthetic data only.
 */
class FamilyCardTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/card';

    private const KEYS = ['credential_number', 'family_code', 'issued_at', 'clan', 'branch', 'head_name', 'verification_url', 'qr', 'qr_available'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->useFamilyAuthKey();
        config(['credentials.verify_base_url' => 'https://famboook.test/verify/']);
    }

    private function ensure(User $as, string $uri = self::URI): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->postJson($uri);
    }

    public function test_the_first_open_issues_the_card_and_later_opens_return_the_same_one(): void
    {
        $head = $this->activatedHead();

        $first = $this->ensure($head['user'])->assertOk();
        $second = $this->ensure($head['user'])->assertOk();

        $this->assertSame(self::KEYS, array_keys($first->json('data')));
        $this->assertSame(1, DigitalCredential::count());
        $credential = DigitalCredential::sole();
        $this->assertSame($credential->credential_number, $first->json('data.credential_number'));
        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame($head['family']->family_code, $first->json('data.family_code'));
        $this->assertSame($head['person']->full_name, $first->json('data.head_name'));
        $this->assertNull($credential->issued_by);
        $this->assertSame(1, FamilyActivity::where('event_type', 'FAMILY_CARD_ISSUED')->count());
        $this->assertStringContainsString('no-store', (string) $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $first->headers->get('Cache-Control'));
    }

    public function test_the_qr_encodes_the_verification_url_of_the_stored_token(): void
    {
        $head = $this->activatedHead();

        $data = $this->ensure($head['user'])->assertOk()->json('data');

        $token = CredentialTokens::reveal(DigitalCredential::sole());
        $this->assertSame('https://famboook.test/verify/'.$token, $data['verification_url']);
        $this->assertTrue($data['qr_available']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $data['qr']);
        $this->assertStringContainsString('<svg', base64_decode(substr($data['qr'], strlen('data:image/svg+xml;base64,'))));
    }

    public function test_an_unreadable_token_returns_the_card_without_a_qr(): void
    {
        $head = $this->activatedHead();
        $this->ensure($head['user'])->assertOk();
        DigitalCredential::query()->toBase()->update(['token_encrypted' => 'not-a-valid-payload']);

        $response = $this->ensure($head['user'])->assertOk()
            ->assertJsonPath('data.qr_available', false)->assertJsonPath('data.qr', null)->assertJsonPath('data.verification_url', null);
        $this->assertNotNull($response->json('data.credential_number'));
    }

    public function test_the_issuance_switch_blocks_a_first_card_but_shows_an_existing_one(): void
    {
        $withCard = $this->activatedHead('123456789');
        $this->ensure($withCard['user'])->assertOk();
        $without = $this->activatedHead('222222222');
        config(['credentials.family_card_issuance_enabled' => false]);

        $this->ensure($withCard['user'])->assertOk();
        $this->ensure($without['user'])->assertStatus(503)->assertJsonPath('code', 'ISSUANCE_DISABLED');
        $this->assertSame(1, DigitalCredential::count());
    }

    public function test_a_revoked_card_is_replaced_by_a_new_one_on_the_next_open(): void
    {
        $head = $this->activatedHead();
        $old = $this->ensure($head['user'])->json('data.credential_number');
        app(RevokeFamilyCredentialAction::class)->handle($head['family'], CredentialRevokeReason::COMPROMISED, User::factory()->create()->id);

        $new = $this->ensure($head['user'])->assertOk()->json('data.credential_number');

        $this->assertNotSame($old, $new);
        $this->assertSame(1, DigitalCredential::where('status', 'ACTIVE')->count());
    }

    public function test_only_the_context_family_is_used_whatever_the_request_says(): void
    {
        $head = $this->activatedHead('123456789');
        $other = $this->activatedHead('222222222');

        $this->ensure($head['user'], self::URI.'?family='.$other['family']->family_code)->assertOk()
            ->assertJsonPath('data.family_code', $head['family']->family_code);

        $this->assertSame([$head['family']->id], DigitalCredential::pluck('family_id')->all());
    }

    public function test_coordinator_scope_never_widens_it(): void
    {
        $head = $this->activatedHead('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $clan = $this->clan();
        $assigned = $this->familyIn($clan, $this->branch($clan), members: 1);
        $this->assign($head['user'], $clan);

        $this->ensure($head['user'])->assertOk()->assertJsonPath('data.family_code', $head['family']->family_code);

        $this->assertSame(0, DigitalCredential::where('family_id', $assigned->id)->count());
    }

    public function test_the_route_is_the_family_boundary_and_post_only(): void
    {
        $route = Route::getRoutes()->getByAction(FamilyCardController::class.'@ensure');

        $this->assertSame('api/v1/family/card', $route->uri());
        $this->assertSame([], $route->parameterNames());
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(['api', 'auth:sanctum', 'family.side', 'can:family-portal.access', 'family.context', 'throttle:family-card'], $route->gatherMiddleware());
    }

    public function test_guests_staff_mixed_and_coordinator_only_accounts_are_refused(): void
    {
        $this->postJson(self::URI)->assertUnauthorized();

        $accounts = ['coordinator only' => $this->familyUser(['COORDINATOR']), 'role-less' => User::factory()->create()];
        foreach (StaffRoles::ALL as $role) {
            $accounts[$role] = tap(User::factory()->create(), fn (User $u) => $u->assignRole($role));
        }
        $mixed = $this->activatedHead('222222222');
        $mixed['user']->assignRole('ADMINISTRATOR');
        $accounts['mixed'] = $mixed['user'];

        foreach ($accounts as $label => $user) {
            $this->ensure($user)->assertForbidden()->assertExactJson(['message' => EnsureFamilySideAccount::MESSAGE]);
        }
        $this->assertSame(0, DigitalCredential::count(), 'no card for a refused account');
    }

    public function test_a_lost_family_context_gets_the_generic_403_and_issues_nothing(): void
    {
        $head = $this->activatedHead();
        $head['link']->forceFill(['status' => UserPersonLinkStatus::SUSPENDED->value, 'suspended_at' => now()])->save();

        $this->ensure($head['user'])->assertForbidden()
            ->assertExactJson(['message' => EnsureFamilyContext::MESSAGE, 'code' => EnsureFamilyContext::CODE]);
        $this->assertSame(0, DigitalCredential::count());
    }

    public function test_a_non_active_family_cannot_reach_the_card(): void
    {
        $head = $this->activatedHead();
        $head['family']->forceFill(['status' => 'INACTIVE'])->save();

        $this->ensure($head['user'])->assertForbidden();
        $this->assertSame(0, DigitalCredential::count());
    }
}
