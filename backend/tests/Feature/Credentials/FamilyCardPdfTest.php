<?php

namespace Tests\Feature\Credentials;

use App\Actions\IssueFamilyCredentialAction;
use App\Actions\ReissueFamilyCredentialAction;
use App\Actions\RevokeFamilyCredentialAction;
use App\Enums\CredentialIssueChannel;
use App\Enums\CredentialRevokeReason;
use App\Enums\UserPersonLinkStatus;
use App\Http\Controllers\Api\V1\Family\FamilyCardPdfController;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Models\AuthSecurityEvent;
use App\Models\DigitalCredential;
use App\Models\FamilyActivity;
use App\Models\User;
use App\Support\Credentials\CredentialTokens;
use App\Support\StaffRoles;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-8.3: GET /api/v1/family/card/pdf — the printable Digital Family Card of
 * the signed-in household head's EXISTING ACTIVE credential (docs/11
 * FP-ADR-071). A GET with zero domain side effects: never an issuance. The
 * Family comes only from family.context. Synthetic data only.
 */
class FamilyCardPdfTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/card/pdf';

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->useFamilyAuthKey();
        config(['credentials.verify_base_url' => 'https://famboook.test/verify/']);
        $this->tempDir = storage_path('framework/testing/mpdf-'.getmypid());
        config(['credentials.pdf.temp_dir' => $this->tempDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    private function download(User $as, string $uri = self::URI): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->get($uri, ['Accept' => 'application/pdf']);
    }

    private function issue(array $head): DigitalCredential
    {
        return app(IssueFamilyCredentialAction::class)->handle($head['family'], CredentialIssueChannel::FAMILY_PORTAL, null);
    }

    // ------------------------------------------------------------------ the PDF

    public function test_the_head_downloads_the_pdf_of_the_existing_active_card(): void
    {
        $head = $this->activatedHead();
        $credential = $this->issue($head);

        $response = $this->download($head['user'])->assertOk();
        $pdf = $response->getContent();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="famboook-family-card-'.$credential->credential_number.'.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame((string) strlen($pdf), $response->headers->get('Content-Length'));
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', substr($pdf, -1024));
        $this->assertGreaterThan(5000, strlen($pdf));
        // The bundled Arabic font is embedded and the QR is an image.
        $this->assertStringContainsString('IBMPlexSansArabic', $pdf);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        // The verification URL and token never appear as text.
        $token = CredentialTokens::reveal($credential);
        $this->assertStringNotContainsString($token, $pdf);
        $this->assertStringNotContainsString('famboook.test/verify', $pdf);
    }

    public function test_the_filename_never_carries_a_token_name_or_identifier(): void
    {
        $head = $this->activatedHead();
        $credential = $this->issue($head);

        $disposition = (string) $this->download($head['user'])->assertOk()->headers->get('Content-Disposition');

        $this->assertMatchesRegularExpression('/\Aattachment; filename="famboook-family-card-FC-[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{2}\.pdf"\z/', $disposition);
        // The exact pattern above already excludes any id; a short numeric id
        // could occur by chance inside the random card number, so it is not
        // searched for separately.
        foreach ([CredentialTokens::reveal($credential), $head['person']->national_id, $head['family']->family_code, $head['person']->full_name] as $value) {
            $this->assertStringNotContainsString($value, $disposition);
        }
    }

    public function test_a_reissued_card_downloads_with_the_new_number(): void
    {
        $head = $this->activatedHead();
        $old = $this->issue($head);
        $new = app(ReissueFamilyCredentialAction::class)->handle($head['family'], User::factory()->create()->id);

        $disposition = (string) $this->download($head['user'])->assertOk()->headers->get('Content-Disposition');

        $this->assertStringContainsString($new->credential_number, $disposition);
        $this->assertStringNotContainsString($old->credential_number, $disposition);
    }

    public function test_the_temp_directory_is_created_on_first_use_and_holds_no_pdf_or_token(): void
    {
        $head = $this->activatedHead();
        $credential = $this->issue($head);
        File::deleteDirectory($this->tempDir);

        $this->download($head['user'])->assertOk();

        $this->assertDirectoryExists($this->tempDir);
        $token = CredentialTokens::reveal($credential);
        // Only mPDF's font-metric cache persists: no PDF, no QR image, no token.
        $files = File::allFiles($this->tempDir);
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $this->assertStringStartsWith('mpdf'.DIRECTORY_SEPARATOR.'ttfontdata'.DIRECTORY_SEPARATOR, $file->getRelativePathname());
            $this->assertDoesNotMatchRegularExpression('/\.(pdf|png|jpe?g|gif|svg)\z/i', $file->getFilename());
            $this->assertStringNotContainsString($token, (string) file_get_contents($file->getPathname()));
        }
    }

    // ------------------------------------------------------ never an issuance

    public function test_without_an_active_card_the_get_issues_nothing(): void
    {
        $head = $this->activatedHead();

        $this->download($head['user'])->assertNotFound()->assertExactJson([
            'message' => 'لا توجد بطاقة رقمية سارية لأسرتك. افتح صفحة البطاقة أولًا.', 'code' => 'CARD_NOT_ISSUED',
        ]);

        $this->assertSame(0, DigitalCredential::count());
        $this->assertSame(0, FamilyActivity::count());
    }

    public function test_a_revoked_card_produces_no_pdf_and_nothing_is_reissued(): void
    {
        $head = $this->activatedHead();
        $this->issue($head);
        app(RevokeFamilyCredentialAction::class)->handle($head['family'], CredentialRevokeReason::COMPROMISED, User::factory()->create()->id);

        $this->download($head['user'])->assertNotFound()->assertJsonPath('code', 'CARD_NOT_ISSUED');

        $this->assertSame(0, DigitalCredential::where('status', 'ACTIVE')->count());
        $this->assertSame(1, DigitalCredential::count());
    }

    public function test_an_undecryptable_token_is_a_409_and_never_a_pdf_without_qr(): void
    {
        $head = $this->activatedHead();
        $credential = $this->issue($head);
        DigitalCredential::query()->toBase()->update(['token_encrypted' => 'not-a-valid-payload']);
        $before = $credential->fresh()->getAttributes();

        $response = $this->download($head['user'])->assertStatus(409)->assertJsonPath('code', 'CARD_QR_UNAVAILABLE');

        $this->assertStringNotContainsString('%PDF', $response->getContent());
        $this->assertSame($before, $credential->fresh()->getAttributes());
        $this->assertSame(1, DigitalCredential::count());
    }

    public function test_downloading_has_no_domain_side_effect_and_logs_nothing_secret(): void
    {
        $head = $this->activatedHead();
        $credential = $this->issue($head);
        $token = CredentialTokens::reveal($credential);
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.json_encode($message->context);
        });
        $state = fn () => [DigitalCredential::count(), $credential->fresh()->getAttributes(), FamilyActivity::count(),
            AuthSecurityEvent::count(), $head['family']->fresh()->getAttributes()];
        $before = $state();

        $this->download($head['user'])->assertOk();
        $this->download($head['user'])->assertOk();

        $this->assertSame($before, $state());
        $this->assertSame([], $logged, 'a successful download writes no log entry');
        $this->assertStringNotContainsString($token, implode("\n", $logged));
    }

    // ------------------------------------------------------------ the boundary

    public function test_only_the_context_family_card_is_used_whatever_the_request_says(): void
    {
        $head = $this->activatedHead('123456789');
        $other = $this->activatedHead('222222222');
        $mine = $this->issue($head);
        $theirs = $this->issue($other);

        $disposition = (string) $this->download($head['user'], self::URI.'?family='.$other['family']->family_code.'&credential='.$theirs->credential_number)
            ->assertOk()->headers->get('Content-Disposition');

        $this->assertStringContainsString($mine->credential_number, $disposition);
        $this->assertStringNotContainsString($theirs->credential_number, $disposition);
    }

    public function test_coordinator_scope_never_widens_it(): void
    {
        $head = $this->activatedHead('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $clan = $this->clan();
        $assigned = $this->familyIn($clan, $this->branch($clan), members: 1);
        $this->assign($head['user'], $clan);
        app(IssueFamilyCredentialAction::class)->handle($assigned, CredentialIssueChannel::STAFF, null);

        $this->download($head['user'])->assertNotFound()->assertJsonPath('code', 'CARD_NOT_ISSUED');
    }

    public function test_guests_staff_mixed_and_coordinator_only_accounts_are_refused(): void
    {
        $this->get(self::URI, ['Accept' => 'application/json'])->assertUnauthorized();

        $accounts = ['coordinator only' => $this->familyUser(['COORDINATOR']), 'role-less' => User::factory()->create()];
        foreach (StaffRoles::ALL as $role) {
            $accounts[$role] = tap(User::factory()->create(), fn (User $u) => $u->assignRole($role));
        }
        $mixed = $this->activatedHead('222222222');
        $this->issue($mixed);
        $mixed['user']->assignRole('ADMINISTRATOR');
        $accounts['mixed'] = $mixed['user'];

        foreach ($accounts as $label => $user) {
            $this->download($user)->assertForbidden()->assertExactJson(['message' => EnsureFamilySideAccount::MESSAGE]);
        }
    }

    public function test_a_lost_family_context_and_an_inactive_family_get_the_generic_403(): void
    {
        $suspended = $this->activatedHead('123456789');
        $this->issue($suspended);
        $suspended['link']->forceFill(['status' => UserPersonLinkStatus::SUSPENDED->value, 'suspended_at' => now()])->save();
        $this->download($suspended['user'])->assertForbidden()
            ->assertExactJson(['message' => EnsureFamilyContext::MESSAGE, 'code' => EnsureFamilyContext::CODE]);

        $inactive = $this->activatedHead('222222222');
        $this->issue($inactive);
        $inactive['family']->forceFill(['status' => 'INACTIVE'])->save();
        $this->download($inactive['user'])->assertForbidden();
    }

    public function test_downloads_are_rate_limited_per_user(): void
    {
        config(['credentials.pdf.limits.user_minute' => 2]);
        $head = $this->activatedHead();
        $this->issue($head);

        $this->download($head['user'])->assertOk();
        $this->download($head['user'])->assertOk();
        $this->download($head['user'])->assertStatus(429);
    }

    public function test_the_route_is_get_only_without_parameters_behind_the_family_boundary(): void
    {
        $route = Route::getRoutes()->getByAction(FamilyCardPdfController::class.'@show');

        $this->assertSame('api/v1/family/card/pdf', $route->uri());
        $this->assertSame([], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(['api', 'auth:sanctum', 'family.side', 'can:family-portal.access', 'family.context', 'throttle:family-card-pdf'], $route->gatherMiddleware());
    }

    // ------------------------------------------------------------- regression

    public function test_the_json_card_contract_is_unchanged_and_issuance_stays_idempotent(): void
    {
        $head = $this->activatedHead();
        $this->app['auth']->forgetGuards();

        $first = $this->actingAs($head['user']->fresh())->postJson('/api/v1/family/card')->assertOk();
        $second = $this->actingAs($head['user']->fresh())->postJson('/api/v1/family/card')->assertOk();

        $this->assertSame(
            ['credential_number', 'family_code', 'issued_at', 'clan', 'branch', 'head_name', 'verification_url', 'qr', 'qr_available'],
            array_keys($first->json('data')),
        );
        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $first->json('data.qr'));
        $this->assertSame(1, DigitalCredential::count());
        $this->download($head['user'])->assertOk();
        $this->assertSame(1, DigitalCredential::count());
    }
}
