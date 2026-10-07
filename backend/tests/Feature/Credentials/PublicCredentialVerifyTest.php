<?php

namespace Tests\Feature\Credentials;

use App\Actions\IssueFamilyCredentialAction;
use App\Actions\ReissueFamilyCredentialAction;
use App\Actions\RevokeFamilyCredentialAction;
use App\Enums\CredentialIssueChannel;
use App\Enums\CredentialRevokeReason;
use App\Http\Controllers\Api\V1\PublicCredentialController;
use App\Models\AuthSecurityEvent;
use App\Models\Branch;
use App\Models\Clan;
use App\Models\DigitalCredential;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Support\Credentials\CredentialTokens;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PWA-8.2: POST /api/v1/credentials/verify — public verification of a Digital
 * Family Card (docs/11 §19, FP-ADR-070). The token travels in the body only;
 * the approved fields only; ONE generic failure for everything that does not
 * verify; per-IP rate limit; no-store; no write; the token never logged.
 * Synthetic data only.
 */
class PublicCredentialVerifyTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/credentials/verify';

    private const KEYS = ['type', 'credential_number', 'family_code', 'issued_at', 'clan', 'branch', 'head_name'];

    private int $branches = 0;

    private function family(string $headName = 'سالم أحمد محمد الاختبار', array $attributes = []): Family
    {
        $clan = Clan::firstOrCreate(['code' => 'TEST_CLAN'], ['name' => 'عشيرة الاختبار']);
        $branch = Branch::create(['clan_id' => $clan->id, 'code' => 'BR_'.(++$this->branches), 'name' => 'فرع الاختبار', 'is_active' => true]);
        $family = Family::factory()->create(['clan_id' => $clan->id, 'branch_id' => $branch->id, ...$attributes]);
        FamilyMembership::factory()->create([
            'family_id' => $family->id, 'is_household_head' => true,
            'person_id' => Person::factory()->create([
                'full_name' => $headName, 'national_id' => '987654321', 'mobile' => '0591234567', 'birth_date' => '1980-01-15',
            ])->id,
        ]);

        return $family;
    }

    /** @return array{0: DigitalCredential, 1: string} */
    private function card(Family $family): array
    {
        $credential = app(IssueFamilyCredentialAction::class)->handle($family, CredentialIssueChannel::FAMILY_PORTAL, null);

        return [$credential, CredentialTokens::reveal($credential)];
    }

    private function verify(mixed $body): TestResponse
    {
        return $this->postJson(self::URI, $body);
    }

    // ---------------------------------------------------------------- success

    public function test_a_valid_card_returns_exactly_the_approved_fields(): void
    {
        $family = $this->family();
        [$credential, $token] = $this->card($family);

        $response = $this->verify(['token' => $token])->assertOk();

        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(self::KEYS, array_keys($response->json('data')));
        $response->assertExactJson(['data' => [
            'type' => 'FAMILY', 'credential_number' => $credential->credential_number, 'family_code' => $family->family_code,
            'issued_at' => $credential->issued_at->toDateString(), 'clan' => 'عشيرة الاختبار', 'branch' => 'فرع الاختبار',
            'head_name' => 'سالم الاختبار',
        ]]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_head_is_shortened_to_first_and_last_word_without_heuristics(): void
    {
        foreach (['محمد أحمد' => 'محمد أحمد', 'عبد الله محمد أحمد' => 'عبد أحمد', 'سالم' => 'سالم', '  سالم   الاختبار  ' => 'سالم الاختبار'] as $full => $short) {
            [, $token] = $this->card($this->family($full));
            $this->verify(['token' => $token])->assertOk()->assertJsonPath('data.head_name', $short);
        }
    }

    public function test_a_family_without_a_current_head_still_verifies_without_the_head(): void
    {
        $family = $this->family();
        [, $token] = $this->card($family);
        FamilyMembership::where('family_id', $family->id)->update(['is_household_head' => false]);

        $this->verify(['token' => $token])->assertOk()->assertJsonPath('data.head_name', null);

        FamilyMembership::where('family_id', $family->id)->update(['is_household_head' => true]);
        FamilyMembership::where('family_id', $family->id)->sole()->person->delete();
        $this->verify(['token' => $token])->assertOk()->assertJsonPath('data.head_name', null);
    }

    public function test_a_head_change_keeps_the_card_valid_and_shows_the_new_head(): void
    {
        $family = $this->family('رب سابق الاختبار');
        [, $token] = $this->card($family);
        FamilyMembership::where('family_id', $family->id)->update(['is_household_head' => false]);
        FamilyMembership::factory()->create([
            'family_id' => $family->id, 'is_household_head' => true, 'person_id' => Person::factory()->create(['full_name' => 'رب جديد الاختبار'])->id,
        ]);

        $this->verify(['token' => $token])->assertOk()->assertJsonPath('data.head_name', 'رب الاختبار');
    }

    public function test_nothing_sensitive_or_internal_is_returned(): void
    {
        $family = $this->family();
        [$credential, $token] = $this->card($family);

        $raw = $this->verify(['token' => $token])->assertOk()->getContent();

        foreach (['987654321', '0591234567', '1980-01-15', $token, $credential->token_hash, '"id"', 'family_id', 'person', 'member',
            'national', 'mobile', 'birth', 'residence', 'health', 'need', 'assist', 'status', 'issued_by', 'revoked', 'uuid'] as $value) {
            $this->assertStringNotContainsString($value, $raw, $value);
        }
    }

    // ------------------------------------------------------- generic failure

    /** @return array<string, array{0: callable}> */
    public static function failures(): array
    {
        return [
            'missing body' => [fn () => []],
            'non-string token' => [fn () => ['token' => ['x']]],
            'malformed token' => [fn () => ['token' => 'not-a-token']],
            'unknown token' => [fn () => ['token' => CredentialTokens::generate()]],
        ];
    }

    #[DataProvider('failures')]
    public function test_malformed_missing_and_unknown_tokens_get_the_one_generic_failure(callable $body): void
    {
        $this->assertGenericFailure($this->verify($body()));
    }

    public function test_revoked_reissued_and_invalid_family_cards_get_the_same_generic_failure(): void
    {
        $staff = User::factory()->create();
        $revoked = $this->family();
        [, $revokedToken] = $this->card($revoked);
        app(RevokeFamilyCredentialAction::class)->handle($revoked, CredentialRevokeReason::ADMINISTRATIVE, $staff->id);

        $reissued = $this->family();
        [, $oldToken] = $this->card($reissued);
        app(ReissueFamilyCredentialAction::class)->handle($reissued, $staff->id);

        $inactive = $this->family();
        [, $inactiveToken] = $this->card($inactive);
        $inactive->forceFill(['status' => 'INACTIVE'])->save();

        $archived = $this->family();
        [, $archivedToken] = $this->card($archived);
        $archived->forceFill(['status' => 'ARCHIVED'])->save();

        $deleted = $this->family();
        [, $deletedToken] = $this->card($deleted);
        $deleted->delete();

        $reference = $this->verify(['token' => CredentialTokens::generate()]);
        foreach ([$revokedToken, $oldToken, $inactiveToken, $archivedToken, $deletedToken] as $token) {
            $response = $this->assertGenericFailure($this->verify(['token' => $token]));
            $this->assertSame($reference->getContent(), $response->getContent());
        }
    }

    public function test_a_family_made_active_again_verifies_again_without_touching_the_card(): void
    {
        $family = $this->family();
        [$credential, $token] = $this->card($family);
        $before = $credential->fresh()->getAttributes();
        $family->forceFill(['status' => 'INACTIVE'])->save();
        $this->assertGenericFailure($this->verify(['token' => $token]));

        $family->forceFill(['status' => 'ACTIVE'])->save();

        $this->verify(['token' => $token])->assertOk();
        $this->assertSame($before, $credential->fresh()->getAttributes());
    }

    // ----------------------------------------------------------------- limits

    public function test_verification_is_rate_limited_per_ip_with_a_generic_429(): void
    {
        config(['credentials.verify_limits.ip_minute' => 2]);

        $this->verify(['token' => CredentialTokens::generate()])->assertNotFound();
        $this->verify(['token' => CredentialTokens::generate()])->assertNotFound();
        $response = $this->verify(['token' => CredentialTokens::generate()])->assertStatus(429)
            ->assertExactJson(['message' => 'تعذّر التحقق الآن. يُرجى المحاولة لاحقًا.', 'code' => 'TOO_MANY_REQUESTS']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // ---------------------------------------------------------------- privacy

    public function test_verification_writes_nothing_and_never_logs_the_token(): void
    {
        $family = $this->family();
        [$credential, $token] = $this->card($family);
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.json_encode($message->context);
        });
        $counts = fn () => [AuthSecurityEvent::count(), FamilyActivity::count(), DigitalCredential::count(), DigitalCredential::max('updated_at')];
        $before = $counts();

        $this->verify(['token' => $token])->assertOk();
        $this->verify(['token' => $token.'x']);

        $this->assertSame($before, $counts());
        $this->assertStringNotContainsString($token, implode("\n", $logged));
        $dontFlash = new \ReflectionProperty(Handler::class, 'dontFlash');
        $this->assertContains('token', $dontFlash->getValue($this->app->make(ExceptionHandler::class)));
        $this->assertNotNull($credential);
    }

    public function test_the_route_is_public_body_only_and_takes_no_url_parameter(): void
    {
        $route = Route::getRoutes()->getByAction(PublicCredentialController::class.'@verify');

        $this->assertSame('api/v1/credentials/verify', $route->uri());
        $this->assertSame([], $route->parameterNames());
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(['api', 'throttle:credential-verify'], $route->gatherMiddleware());
        $this->getJson(self::URI.'?token='.CredentialTokens::generate())->assertStatus(405);
    }

    private function assertGenericFailure(TestResponse $response): TestResponse
    {
        $response->assertNotFound()->assertExactJson([
            'message' => PublicCredentialController::MESSAGE, 'code' => PublicCredentialController::CODE,
        ]);
        $this->assertSame('تعذّر التحقق من هذه البطاقة.', $response->json('message'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        return $response;
    }
}
