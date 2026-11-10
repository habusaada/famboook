<?php

namespace Tests\Feature\FamilyPortal;

use App\Actions\FamilyPortal\AdmitRegistryChildAction;
use App\Models\User;
use App\Support\CivilLookup\CivilRegistrySource;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyPortal\RegistryAdmission\PostgresRegistryReleasePin;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionRefs as Refs;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildAdmissibility;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildSource;
use App\Support\FamilyPortal\RegistryAdmission\RegistryReleasePinVerifier;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\Support\FamilyPortal\AdmissionRegistrySource;
use Tests\Support\FamilyPortal\PendingGatesLiftedAdmissibility;
use Tests\Support\FamilyPortal\RevokesFamilyUserAdmissionGrant;
use Tests\Support\FamilyPortal\SyntheticDeclaration;
use Tests\Support\FamilyPortal\SyntheticReleasePinFixture as Fixture;
use Tests\Support\FamilyPortal\SyntheticReleasePinVerifier as Verifier;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 ADD-WP-07C: POST /api/v1/family/household/registry-children/admissions.
 * The route, its gate and limits, the request, and the answers of AdmitRegistryChildAction
 * over HTTP. Synthetic sources, the synthetic pin fixture and a test-only admissibility
 * are bound for these tests only; the application binds none of them. Admissions that
 * reach the pin need PostgreSQL.
 */
class FamilyRegistryAdmissionApiTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase, RevokesFamilyUserAdmissionGrant;

    private const URI = '/api/v1/family/household/registry-children/admissions';

    private const HEAD = '123456789';

    private const CHILD = '401234567';

    private const RELEASE = 7;

    private const DECLARATION = '2026-11-v1';

    private array $head;

    private AdmissionRegistrySource $source;

    private ?Fixture $fixture = null;

    private ?Connection $admin = null;

    /** @var list<array{string, string}> every message logged during the test, with its context */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        // With APP_DEBUG on, Laravel's renderer records every query with its bindings, and the action refuses to run.
        app('events')->forget(QueryExecuted::class);
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->head = $this->activatedHead(self::HEAD);
        $this->source = new AdmissionRegistrySource(self::RELEASE);
        $this->source->add(self::CHILD, ['fatherid' => self::HEAD, 'fullname' => 'ابن-تجريبي']);
        $this->source->add('402234567', ['fatherid' => self::HEAD]);
        // The real registry must never be reached, whatever happens.
        $this->app->instance(CivilRegistrySource::class, new class
        {
            public function __call(string $name, array $arguments): never
            {
                throw new RuntimeException('The civil registry must not be read.');
            }
        });
        if (DB::getDriverName() === 'pgsql') {
            $this->fixture = new Fixture;
            $this->admin = Fixture::connection('admin');
            $this->fixture->install($this->admin, self::RELEASE);
        }
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = [$event->message, (string) json_encode($event->context)];
        });
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            DB::disconnect('synthetic_pin_lookup');
            $this->fixture->uninstall($this->admin);
            DB::disconnect('synthetic_pin_admin');
        }
        parent::tearDown();
    }

    /** The feature opened for this test only: synthetic source and verifier, the flag, the permission, the declaration. */
    private function open(string $mode = 'ON', bool $liftPendingGates = true, ?User $user = null): void
    {
        $this->app->instance(RegistryChildSource::class, $this->source);
        $this->app->instance(RegistryReleasePinVerifier::class, new Verifier(Fixture::connection('lookup')));
        if ($liftPendingGates) {
            $this->app->instance(RegistryChildAdmissibility::class, new PendingGatesLiftedAdmissibility);
        }
        config(['family_registry_admission.mode' => $mode, ...SyntheticDeclaration::approved(self::DECLARATION)]);
        ($user ?? $this->head['user'])->givePermissionTo(AdmitRegistryChildAction::PERMISSION);
    }

    private function ref(string $child = self::CHILD): string
    {
        return Refs::issue(app(FamilyAccessResolver::class)->familyContext($this->head['user']), self::RELEASE, $child, 'FATHER');
    }

    private function body(array $override = []): array
    {
        return ['ref' => $this->ref(), 'client_reference' => (string) Str::uuid(), 'declaration_version' => self::DECLARATION,
            'declaration_accepted' => true, ...$override];
    }

    private function submit(array $body, ?User $as = null, string $query = ''): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs(($as ?? $this->head['user'])->fresh())->postJson(self::URI.$query, $body);
    }

    private function assertNoStore(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    /** A refusal: exactly {message, code}, no-store, and nothing that names the child. */
    private function assertRefused(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertExactJson(['message' => $response->json('message'), 'code' => $code]);
        $this->assertNoStore($response);
        $this->assertStringNotContainsString(self::CHILD, (string) $response->getContent());
    }

    private function events(): int
    {
        return DB::table('family_registry_admission_events')->count();
    }

    private function pgsql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('An admission takes the release pin, which needs PostgreSQL.');
        }
    }

    // ---------------------------------------------------------------- boundary and gate

    public function test_a_guest_a_staff_account_and_a_non_head_are_refused(): void
    {
        $this->open();
        // The shared boundary's own refusals are no-store on this route too (ADD-WP-07C-C).
        $this->assertNoStore($this->postJson(self::URI, $this->body())->assertUnauthorized());
        $this->assertNoStore($this->actingAs(User::factory()->create())->postJson(self::URI, $this->body())->assertForbidden());
        $member = $this->familyUser();
        $member->givePermissionTo(AdmitRegistryChildAction::PERMISSION);
        $this->assertNoStore($this->submit($this->body(), $member)->assertForbidden()->assertJsonPath('code', 'FAMILY_CONTEXT_UNAVAILABLE'));
        $withoutPortal = User::factory()->familySide()->create();
        $this->assertNoStore($this->submit($this->body(), $withoutPortal)->assertForbidden());
        $this->assertSame(0, $this->source->reads);
        $this->assertSame(0, $this->events());
    }

    public function test_a_closed_flag_or_a_missing_permission_is_404_and_counts_nothing(): void
    {
        $this->open();
        foreach (['OFF', 'PILOT', 'invalid', ''] as $mode) {
            config(['family_registry_admission.mode' => $mode, 'family_registry_admission.pilot_family_ids' => [999999]]);
            $this->assertRefused($this->submit($this->body()), 404, 'ADMISSION_UNAVAILABLE');
        }
        config(['family_registry_admission.mode' => 'ON']);
        $this->revokeFamilyUserAdmissionGrant();
        $this->head['user']->revokePermissionTo(AdmitRegistryChildAction::PERMISSION);
        $this->assertRefused($this->submit($this->body()), 404, 'ADMISSION_UNAVAILABLE');

        $this->assertSame(0, RateLimiter::attempts('family-registry-admission|user|'.$this->head['user']->id.'|minute'));
        $this->assertSame(0, $this->source->reads);
        $this->assertSame(0, $this->events());
    }

    public function test_the_shipped_configuration_admits_nothing(): void
    {
        // As installed: the flag is OFF and nothing is bound.
        $this->assertSame('OFF', config('family_registry_admission.mode'));
        $this->assertNull(config('family_registry_admission.declaration_version'));
        $this->assertFalse(app()->bound(RegistryChildSource::class));
        $this->assertFalse(app()->bound(RegistryReleasePinVerifier::class));
        $this->assertFalse(app()->bound(RegistryChildAdmissibility::class));
        $this->head['user']->givePermissionTo(AdmitRegistryChildAction::PERMISSION);
        $this->assertRefused($this->submit($this->body()), 404, 'ADMISSION_UNAVAILABLE');

        // Flag and permission open, but no source or verifier bound: 503 before anything is read.
        config(['family_registry_admission.mode' => 'ON', 'family_registry_admission.declaration_version' => self::DECLARATION]);
        $this->assertRefused($this->submit($this->body()), 503, 'REGISTRY_UNAVAILABLE');
        $this->assertSame(0, $this->events());
    }

    // ---------------------------------------------------------------- the request

    public function test_an_invalid_request_is_422_and_echoes_no_value(): void
    {
        $this->open();
        $ref = $this->ref();
        $cases = [
            'no ref' => ['ref' => null],
            'ref not a string' => ['ref' => ['x']],
            'ref too long' => ['ref' => str_repeat('A', Refs::MAX_LENGTH + 1)],
            'no key' => ['client_reference' => null],
            'key not a uuid' => ['client_reference' => 'not-a-uuid'],
            'key upper case' => ['client_reference' => strtoupper((string) Str::uuid())],
            'key with braces' => ['client_reference' => '{'.Str::uuid().'}'],
            'no declaration version' => ['declaration_version' => null],
            'declaration version too long' => ['declaration_version' => str_repeat('v', 41)],
            'declaration false' => ['declaration_accepted' => false],
            'declaration "true"' => ['declaration_accepted' => 'true'],
            'declaration 1' => ['declaration_accepted' => 1],
            'declaration "on"' => ['declaration_accepted' => 'on'],
            // RR-02A: the optional life declaration is the JSON true or nothing.
            'life declaration false' => ['life_declaration_accepted' => false],
            'life declaration "true"' => ['life_declaration_accepted' => 'true'],
            'life declaration 1' => ['life_declaration_accepted' => 1],
            'a National ID' => ['national_id' => self::CHILD],
            'a family' => ['family_id' => $this->head['family']->id],
            'a release' => ['release_id' => self::RELEASE],
        ];
        foreach ($cases as $label => $override) {
            $body = array_filter($this->body(['ref' => $ref, ...$override]), fn ($v) => $v !== null);
            RateLimiter::clear('family-registry-admission|user|'.$this->head['user']->id.'|minute');
            RateLimiter::clear('family-registry-admission|user|'.$this->head['user']->id.'|hour');
            $response = $this->submit($body);
            $response->assertStatus(422);
            $this->assertNoStore($response);
            // {message, errors}: field names only.
            $this->assertSame(['message', 'errors'], array_keys($response->json()), $label);
            $this->assertSame([], array_diff(array_keys($response->json('errors')),
                ['ref', 'client_reference', 'declaration_version', 'declaration_accepted', 'life_declaration_accepted', 'parameters']), $label);
            foreach ([self::CHILD, $ref] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $response->getContent(), $label);
            }
        }
        RateLimiter::clear('family-registry-admission|user|'.$this->head['user']->id.'|minute');
        $this->submit($this->body(), query: '?page=1')->assertStatus(422);
        $this->assertSame(0, $this->source->reads, 'nothing reached the action');
        $this->assertSame(0, $this->events());
    }

    // ---------------------------------------------------------------- limits

    public function test_attempts_are_limited_per_minute_and_per_hour_before_validation(): void
    {
        $this->open();
        $id = $this->head['user']->id;
        $invalid = ['declaration_accepted' => false];

        for ($i = 1; $i <= 5; $i++) {
            $this->submit($this->body($invalid))->assertStatus(422);
        }
        $limited = $this->submit($this->body());
        $this->assertRefused($limited, 429, 'RATE_LIMITED');
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        $this->assertSame(0, $this->source->reads, 'the sixth valid attempt never reached the action');

        // The hourly window: 20 attempts, spread over minutes.
        for ($minute = 1; $minute <= 3; $minute++) {
            $this->travel(61)->seconds();
            for ($i = 1; $i <= 5; $i++) {
                $this->submit($this->body($invalid))->assertStatus(422);
            }
        }
        $this->travel(61)->seconds();
        $this->assertRefused($this->submit($this->body()), 429, 'RATE_LIMITED');
        $this->assertSame(20, RateLimiter::attempts("family-registry-admission|user|{$id}|hour"));

        // Independent of discovery; and the keys name the account id only.
        $this->assertSame(0, RateLimiter::attempts("family-registry-discovery|user|{$id}|minute"));
        $this->assertSame(0, RateLimiter::attempts('family-registry-admission|user|'.self::HEAD.'|minute'));
    }

    // ---------------------------------------------------------------- answers

    public function test_reference_release_and_declaration_refusals(): void
    {
        $this->open();
        $this->assertRefused($this->submit($this->body(['ref' => 'not-a-reference'])), 422, 'REFERENCE_INVALID');
        $this->assertRefused($this->submit($this->body(['declaration_version' => '2025-01-v0'])), 422, 'DECLARATION_REQUIRED');
        $this->source->publish(self::RELEASE + 1);
        $this->assertRefused($this->submit($this->body()), 409, 'RELEASE_CHANGED');
        // Refused attempts count toward the limits, like invalid ones.
        $this->assertSame(3, RateLimiter::attempts('family-registry-admission|user|'.$this->head['user']->id.'|minute'));
    }

    public function test_an_admission_is_201_and_its_replay_200(): void
    {
        $this->pgsql();
        $this->open();
        $body = $this->body();

        $created = $this->submit($body)->assertCreated();
        $this->assertNoStore($created);
        $this->assertSame(['data'], array_keys($created->json()));
        $this->assertSame(['member_ref', 'replayed'], array_keys($created->json('data')));
        $this->assertFalse($created->json('data.replayed'));
        $this->assertIsString($created->json('data.member_ref'));

        $replayed = $this->submit($body)->assertOk();
        $this->assertNoStore($replayed);
        $this->assertSame(['member_ref' => $created->json('data.member_ref'), 'replayed' => true], $replayed->json('data'));

        // The same key for another child: a conflict. Another key for the same child: not eligible.
        $this->assertRefused($this->submit($this->body(['ref' => $this->ref('402234567'), 'client_reference' => $body['client_reference']])), 409, 'ADMISSION_IDEMPOTENCY_CONFLICT');
        $this->assertRefused($this->submit($this->body()), 422, 'NOT_ELIGIBLE');

        foreach ([$created, $replayed] as $response) {
            foreach ([self::CHILD, 'ابن-تجريبي', $body['ref']] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $response->getContent());
            }
        }
        $this->assertSame(1, DB::table('family_registry_admissions')->count());
    }

    public function test_the_operational_eligibility_answers_not_eligible_without_a_reason(): void
    {
        $this->pgsql();
        $this->open(liftPendingGates: false);
        $this->assertRefused($this->submit($this->body()), 422, 'NOT_ELIGIBLE');
        $this->assertSame('LIFE_UNVERIFIED', DB::table('family_registry_admission_events')->orderByDesc('id')->value('outcome_code'),
            'the reason is recorded, never answered');
        $this->assertSame(0, DB::table('family_registry_admissions')->count());
    }

    public function test_a_failure_is_a_sanitized_503(): void
    {
        $this->open();
        if (DB::getDriverName() === 'pgsql') {
            $publisher = Fixture::connection('publisher');
            $publisher->beginTransaction();
            $publisher->select('SELECT pg_advisory_xact_lock(?::bigint)', [PostgresRegistryReleasePin::PIN_KEY]);
        }
        $response = $this->submit($this->body());
        if (isset($publisher)) {
            $publisher->rollBack();
            DB::disconnect('synthetic_pin_publisher');
        }

        $response->assertStatus(503);
        $this->assertNoStore($response);
        $this->assertSame(['message', 'code', 'correlation_id'], array_keys($response->json()));
        $this->assertSame('REGISTRY_UNAVAILABLE', $response->json('code'));
        // PIN_BUSY on PostgreSQL is retryable; on SQLite the pin cannot exist at all.
        $this->assertSame(DB::getDriverName() === 'pgsql' ? '5' : null, $response->headers->get('Retry-After'));
        $this->assertSame(0, DB::table('family_registry_admissions')->count());
    }

    public function test_nothing_sensitive_is_logged(): void
    {
        $this->open();
        $bodies = [$this->body(['ref' => 'not-a-reference']), $this->body()];
        foreach ($bodies as $body) {
            $this->submit($body);
        }
        $everything = json_encode($this->logged);
        foreach ([self::CHILD, 'ابن-تجريبي', $bodies[1]['ref'], '127.0.0.1'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $everything);
        }
        foreach ($this->logged as [$message]) {
            $this->assertSame('Registry admission failed closed.', $message, 'the only line the admission ever logs');
        }
    }
}
