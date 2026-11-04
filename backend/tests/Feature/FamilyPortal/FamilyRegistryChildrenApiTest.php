<?php

namespace Tests\Feature\FamilyPortal;

use App\Exceptions\CivilLookupException;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Support\CivilLookup\CivilRecord;
use App\Support\CivilLookup\CivilRecordPage;
use App\Support\CivilLookup\CivilRegistrySource;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildSource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\Support\FamilyPortal\SyntheticRegistryChildSource;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 ADD-WP-06B-3: GET /api/v1/family/household/registry-children, on
 * both drivers. Synthetic sources and synthetic data only. The permission is given
 * DIRECTLY to the test account (no role receives it), and the flag is opened only
 * inside these tests. ADD-WP-06B-3R: the too-broad refusal and release integrity.
 */
class FamilyRegistryChildrenApiTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/household/registry-children';

    private const HEAD = '123456789';

    private const PERMISSION = 'family-member.admit-from-registry';

    private array $head;

    private SyntheticRegistryChildSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        $this->head = $this->activatedHead(self::HEAD);
        $this->source = new SyntheticRegistryChildSource;
        // The real registry must never be reached, whatever happens.
        $this->app->instance(CivilRegistrySource::class, new class
        {
            public function __call(string $name, array $arguments): never
            {
                throw new RuntimeException('The civil registry must not be read.');
            }
        });
    }

    /** The feature opened for this test only: a synthetic source, the flag, the permission. */
    private function open(string $mode = 'ON', ?User $user = null): void
    {
        $this->app->instance(RegistryChildSource::class, $this->source);
        config(['family_registry_admission.mode' => $mode]);
        ($user ?? $this->head['user'])->givePermissionTo(self::PERMISSION);
    }

    private function fetch(string $query = '', ?User $as = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs(($as ?? $this->head['user'])->fresh())->getJson(self::URI.$query);
    }

    private function events(): int
    {
        return DB::table('family_registry_admission_events')->count();
    }

    private function assertUnavailable(TestResponse $response, int $status = 404, string $code = 'ADMISSION_UNAVAILABLE'): void
    {
        $response->assertStatus($status)->assertExactJson(['message' => $response->json('message'), 'code' => $code]);
        $this->assertNoStore($response);
    }

    private function assertNoStore(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_guest_and_a_staff_account_are_refused(): void
    {
        $this->open();
        $this->getJson(self::URI)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson(self::URI)->assertForbidden();
        $this->assertSame([], $this->source->calls);
    }

    public function test_a_family_account_without_a_household_context_is_refused(): void
    {
        $this->open();
        $member = $this->familyUser();
        $member->givePermissionTo(self::PERMISSION);
        $this->fetch(as: $member)->assertForbidden()->assertJsonPath('code', 'FAMILY_CONTEXT_UNAVAILABLE');
        $this->assertSame([], $this->source->calls);
    }

    public function test_off_missing_or_invalid_mode_is_unavailable_without_reading_or_recording(): void
    {
        $this->open();
        foreach (['OFF', null, '', 'ONN', 'YES', 'pilot-ish', 1, true] as $mode) {
            config(['family_registry_admission.mode' => $mode]);
            $this->assertUnavailable($this->fetch());
        }
        $this->assertSame([], $this->source->calls);
        $this->assertSame(0, $this->events());
    }

    public function test_the_default_configuration_is_off(): void
    {
        $this->app->instance(RegistryChildSource::class, $this->source);
        $this->head['user']->givePermissionTo(self::PERMISSION);
        $this->assertSame('OFF', config('family_registry_admission.mode'));
        $this->assertUnavailable($this->fetch());
        $this->assertSame([], $this->source->calls);
    }

    public function test_without_the_permission_the_answer_is_the_same_unavailable(): void
    {
        $this->app->instance(RegistryChildSource::class, $this->source);
        config(['family_registry_admission.mode' => 'ON']);
        $off = $this->fetch();
        $this->assertUnavailable($off);
        config(['family_registry_admission.mode' => 'OFF']);
        $this->assertSame($off->json(), $this->fetch()->json());
        $this->assertSame([], $this->source->calls);

        // No role holds it, FAMILY_USER included.
        foreach (RolePermissionSeeder::ROLES as $role) {
            $this->assertNotContains(self::PERMISSION, RolePermissionSeeder::rolePermissions($role), $role);
        }
    }

    public function test_pilot_admits_only_the_configured_families(): void
    {
        $this->open('PILOT');
        $this->source->add('401234567', ['fatherid' => self::HEAD]);
        foreach ([[], [999999], ['x'], null] as $ids) {
            config(['family_registry_admission.pilot_family_ids' => $ids]);
            $this->assertUnavailable($this->fetch());
        }
        $this->assertSame([], $this->source->calls);

        config(['family_registry_admission.pilot_family_ids' => [$this->head['family']->id]]);
        $this->fetch()->assertOk()->assertJsonCount(1, 'data.children');
    }

    public function test_on_lists_the_heads_children_with_the_allowlisted_fields_only(): void
    {
        $this->open();
        $this->source->add('401234567', ['fatherid' => self::HEAD, 'fullname' => 'اسم-أول اسم-الأب عائلة-تجريبية', 'datebirth' => '2010-01-15T00:00:00'])
            ->add('402234567', ['motherid' => self::HEAD, 'gender' => 'أنثى', 'fullname' => 'اسم-ثان اسم-الأب عائلة-تجريبية', 'datebirth' => ''])
            ->add('403234567', ['fatherid' => '999999998', 'fullname' => 'ليس-ابنًا']);

        $response = $this->fetch()->assertOk();
        $this->assertNoStore($response);
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(['children', 'meta', 'declaration'], array_keys($response->json('data')));
        // RR-02: the shipped catalogue holds a draft only, so no declaration is delivered.
        $this->assertNull($response->json('data.declaration'));
        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 2], $response->json('data.meta'));
        $this->assertSame([
            ['display_name' => 'اسم-أول', 'birth_year' => 2010, 'status' => 'NOT_ELIGIBLE', 'ref' => null],
            ['display_name' => 'اسم-ثان', 'birth_year' => null, 'status' => 'NOT_ELIGIBLE', 'ref' => null],
        ], $response->json('data.children'));
        $this->assertSame([['childrenOf', self::HEAD, 0, 100], ['recordOf', self::HEAD, 0, 1]], $this->source->calls);
    }

    public function test_nothing_sensitive_leaks(): void
    {
        $this->open();
        $other = FamilyMembership::factory()->householdHead()->create()->family;
        $elsewhere = Person::factory()->create(['national_id' => '402234567']);
        FamilyMembership::factory()->create(['family_id' => $other->id, 'person_id' => $elsewhere->id, 'is_household_head' => false, 'is_active' => true]);
        $this->source->add('401234567', ['fatherid' => self::HEAD, 'fullname' => 'اسم-أول ب ج', 'deathdate' => '2020-05-01T00:00:00'])
            ->add('402234567', ['fatherid' => self::HEAD, 'fullname' => 'اسم-ثان ب ج', 'householdid' => '777777770', 'partnerid' => '888888880']);

        $body = (string) $this->fetch()->assertOk()->getContent();
        foreach (['401234567', '402234567', '4567', self::HEAD, '2010-01-15', '777777770', '888888880', '0590000000', $other->family_code, $elsewhere->person_code,
            'ACTIVE_MEMBERSHIP_ELSEWHERE', 'DECEASED', 'LIFE_UNVERIFIED', 'GENDER', 'reasons', 'national', 'masked', 'relationship', 'family_id',
            'membership', 'fatherid', 'motherid', 'deathdate', 'payload', 'record', 'release', 'ب ج'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret);
        }
        $this->assertSame(['NOT_ELIGIBLE', 'NOT_ELIGIBLE'], array_column(json_decode($body, true)['data']['children'], 'status'));
    }

    public function test_each_head_sees_only_their_own_children(): void
    {
        $this->open();
        $other = $this->activatedHead('223456789');
        $other['user']->givePermissionTo(self::PERMISSION);
        $this->source->add('401234567', ['fatherid' => self::HEAD, 'fullname' => 'ابن-الأول'])->add('402234567', ['fatherid' => '223456789', 'fullname' => 'ابن-الثاني']);

        $this->assertSame(['ابن-الأول'], array_column($this->fetch()->json('data.children'), 'display_name'));
        $this->assertSame(['ابن-الثاني'], array_column($this->fetch(as: $other['user'])->json('data.children'), 'display_name'));
        $this->assertSame([self::HEAD, '223456789'], array_values(array_unique(array_column(array_filter($this->source->calls, fn ($c) => $c[0] === 'childrenOf'), 1))));
        $this->assertSame([$this->head['family']->id, $other['family']->id], DB::table('family_registry_admission_events')->orderBy('id')->pluck('family_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_only_page_is_accepted(): void
    {
        $this->open();
        // More requests than the default minute limit: refused requests still count (the gate passed).
        config(['family_registry_admission.limits.discovery_user_minute' => 100]);
        foreach (['?national_id=401234567', '?family_id=1', '?per_page=100', '?page=1&per_page=5', '?head=123456789', '?page=0', '?page=5', '?page=abc', '?page[]=1', '?PAGE=1'] as $query) {
            $this->fetch($query)->assertUnprocessable();
        }
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->head['user']->fresh())->json('GET', self::URI, ['national_id' => '401234567'])->assertUnprocessable();
        $this->assertSame([], $this->source->calls);
        $this->assertSame(0, $this->events());

        for ($i = 1; $i <= 30; $i++) {
            $this->source->add(sprintf('41%07d', $i), ['fatherid' => self::HEAD]);
        }
        $this->assertCount(25, $this->fetch()->json('data.children'));
        $this->assertCount(5, $this->fetch('?page=2')->json('data.children'));
        $this->assertSame([], $this->fetch('?page=4')->assertOk()->json('data.children'));
    }

    public function test_a_successful_listing_is_recorded_without_identifying_data(): void
    {
        $this->open();
        $this->source->add('401234567', ['fatherid' => self::HEAD])->add('402234567', ['fatherid' => self::HEAD]);
        $this->fetch()->assertOk();

        $row = (array) DB::table('family_registry_admission_events')->sole();
        $this->assertSame('DISCOVERY_LISTED', $row['event_type']);
        $this->assertSame('LISTED', $row['outcome_code']);
        $this->assertSame([(int) $this->head['user']->id, (int) $this->head['family']->id, 1, 2],
            [(int) $row['actor_user_id'], (int) $row['family_id'], (int) $row['release_id'], (int) $row['result_count']]);
        $this->assertNull($row['reference_fingerprint']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $row['ip_hash']);
        $this->assertStringNotContainsString('127.0.0.1', implode('|', array_map('strval', $row)));
        foreach (['401234567', '402234567', self::HEAD, 'اسم'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($row, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_an_audit_failure_returns_nothing(): void
    {
        $this->open();
        $this->source->add('401234567', ['fatherid' => self::HEAD, 'fullname' => 'اسم-سري']);
        DB::statement('DROP TABLE family_registry_admission_events');

        $response = $this->fetch();
        $this->assertUnavailable($response, 503, 'REGISTRY_UNAVAILABLE');
        $this->assertStringNotContainsString('اسم-سري', (string) $response->getContent());
    }

    public function test_the_audit_stays_append_only(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The append-only trigger exists on PostgreSQL only.');
        }
        $this->open();
        $this->fetch()->assertOk();
        $this->expectException(QueryException::class);
        DB::table('family_registry_admission_events')->update(['outcome_code' => 'CHANGED']);
    }

    public function test_the_limits_are_per_user_and_independent(): void
    {
        $this->open();
        config(['family_registry_admission.limits.discovery_user_minute' => 3, 'family_registry_admission.limits.discovery_user_hour' => 5]);
        for ($i = 0; $i < 3; $i++) {
            $this->fetch()->assertOk();
        }
        $limited = $this->fetch();
        $this->assertUnavailable($limited, 429, 'RATE_LIMITED');
        $this->assertNotNull($limited->headers->get('Retry-After'));
        $this->assertSame(3, $this->events());

        // Another head is not affected; the civil lookup limiter is not touched.
        $other = $this->activatedHead('223456789');
        $other['user']->givePermissionTo(self::PERMISSION);
        $this->fetch(as: $other['user'])->assertOk();
        $this->assertSame(0, RateLimiter::attempts('civil-lookup|user|'.$this->head['user']->id.'|minute'));

        // The hour window holds after the minute window resets.
        $this->travel(61)->seconds();
        $this->fetch()->assertOk();
        $this->fetch()->assertOk();
        $this->assertUnavailable($this->fetch(), 429, 'RATE_LIMITED');
    }

    public function test_a_closed_gate_counts_nothing(): void
    {
        $this->open();
        config(['family_registry_admission.limits.discovery_user_minute' => 1, 'family_registry_admission.mode' => 'OFF']);
        for ($i = 0; $i < 5; $i++) {
            $this->assertUnavailable($this->fetch());
        }
        config(['family_registry_admission.mode' => 'ON']);
        $this->fetch()->assertOk();
    }

    public function test_an_unavailable_source_is_503_and_unrecorded(): void
    {
        $this->open();
        $this->app->instance(RegistryChildSource::class, new class implements RegistryChildSource
        {
            public function childrenOf(string $parentNationalId, int $offset, int $limit): CivilRecordPage
            {
                throw new CivilLookupException(CivilLookupException::UNAVAILABLE);
            }

            public function recordOf(string $nationalId, int $releaseId): ?CivilRecord
            {
                throw new CivilLookupException(CivilLookupException::UNAVAILABLE);
            }
        });

        $this->assertUnavailable($this->fetch(), 503, 'REGISTRY_UNAVAILABLE');
        $this->assertSame(0, $this->events());
    }

    private function tooBroad(): void
    {
        for ($i = 1; $i <= 101; $i++) {
            $this->source->add(sprintf('41%07d', $i), ['fatherid' => self::HEAD, 'fullname' => 'اسم-سري '.$i]);
        }
    }

    // ADD-WP-06B-3R: an explicit refusal, never an empty or partial list.
    public function test_more_matches_than_the_cap_are_409_with_nothing_listed(): void
    {
        $this->open();
        $this->tooBroad();
        $response = $this->fetch();

        $this->assertUnavailable($response, 409, 'RESULT_SET_TOO_BROAD');
        $body = (string) $response->getContent();
        foreach (['children', 'meta', 'total', '101', '100', 'اسم-سري', '41000', self::HEAD, 'reason', 'NOT_ELIGIBLE', 'release', 'payload'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret);
        }
        $row = (array) DB::table('family_registry_admission_events')->sole();
        $this->assertSame(['DISCOVERY_LISTED', 'RESULT_SET_TOO_BROAD', 0, 1], [$row['event_type'], $row['outcome_code'], (int) $row['result_count'], (int) $row['release_id']]);
        $this->assertNull($row['reference_fingerprint']);
        // Nothing past the source page is read: no head record, no registry facts.
        $this->assertSame([['childrenOf', self::HEAD, 0, 100]], $this->source->calls);
    }

    public function test_a_too_broad_answer_whose_audit_fails_is_503(): void
    {
        $this->open();
        $this->tooBroad();
        DB::statement('DROP TABLE family_registry_admission_events');

        $this->assertUnavailable($this->fetch(), 503, 'REGISTRY_UNAVAILABLE');
    }

    // ------------------------------------------------------------ release integrity (ADD-WP-06B-3R)

    /** The synthetic source, with its page and the head's record rewritten as a faulty source would. */
    private function faulty(?callable $page = null, ?callable $record = null): void
    {
        $this->app->instance(RegistryChildSource::class, new class($this->source, $page, $record) implements RegistryChildSource
        {
            public function __construct(private SyntheticRegistryChildSource $inner, private $page, private $record) {}

            public function childrenOf(string $parentNationalId, int $offset, int $limit): CivilRecordPage
            {
                $found = $this->inner->childrenOf($parentNationalId, $offset, $limit);

                return $this->page ? ($this->page)($found) : $found;
            }

            public function recordOf(string $nationalId, int $releaseId): ?CivilRecord
            {
                // The inner source has one release (1); the rewrite below stands for the faulty source.
                $found = $this->inner->recordOf($nationalId, 1);

                return $this->record ? ($this->record)($found) : $found;
            }
        });
    }

    private static function inRelease(CivilRecord $record, int $release): CivilRecord
    {
        return new CivilRecord($release, $record->recordId, $record->mainid, $record->payload);
    }

    private function assertRefusedWithoutDisclosure(): void
    {
        $response = $this->fetch();
        $this->assertUnavailable($response, 503, 'REGISTRY_UNAVAILABLE');
        $this->assertStringNotContainsString('اسم-سري', (string) $response->getContent());
        $this->assertSame(0, $this->events());
    }

    public function test_the_recorded_release_is_the_one_the_source_reported(): void
    {
        $this->open();
        $this->source->add('401234567', ['fatherid' => self::HEAD]);
        $this->faulty(
            fn (CivilRecordPage $p) => new CivilRecordPage(7, $p->total, array_map(fn ($r) => self::inRelease($r, 7), $p->rows), $p->cap),
            fn (?CivilRecord $r) => $r === null ? null : self::inRelease($r, 7),
        );

        $this->fetch('?page=1')->assertOk();
        $this->assertSame(7, (int) DB::table('family_registry_admission_events')->value('release_id'));
    }

    public function test_a_client_cannot_supply_the_release(): void
    {
        $this->open();
        $this->source->add('401234567', ['fatherid' => self::HEAD]);
        foreach (['?release_id=2', '?release=2', '?releaseId=2', '?page=1&release_id=2'] as $query) {
            $this->fetch($query)->assertUnprocessable();
        }
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->head['user']->fresh())->json('GET', self::URI, ['release_id' => 2])->assertUnprocessable();
        $this->assertSame([], $this->source->calls);
        $this->assertSame(0, $this->events());
    }

    public function test_a_page_without_a_valid_release_shows_nothing(): void
    {
        $this->open();
        $this->source->add('401234567', ['fatherid' => self::HEAD, 'fullname' => 'اسم-سري']);
        foreach ([null, 0, -1] as $release) {
            $this->faulty(fn (CivilRecordPage $p) => new CivilRecordPage($release, $p->total, $p->rows, $p->cap));
            $this->assertRefusedWithoutDisclosure();
        }
        $this->assertNotContains('recordOf', array_column($this->source->calls, 0));
    }

    public function test_a_row_from_another_release_shows_nothing(): void
    {
        $this->open();
        $this->source->add('401234567', ['fatherid' => self::HEAD, 'fullname' => 'اسم-سري'])->add('402234567', ['fatherid' => self::HEAD, 'fullname' => 'اسم-سري']);
        $this->faulty(fn (CivilRecordPage $p) => new CivilRecordPage($p->releaseId, $p->total, [$p->rows[0], self::inRelease($p->rows[1], 2)], $p->cap));

        $this->assertRefusedWithoutDisclosure();
    }

    public function test_the_heads_record_from_another_release_shows_nothing(): void
    {
        $this->open();
        $this->source->add(self::HEAD)->add('401234567', ['fatherid' => self::HEAD, 'fullname' => 'اسم-سري']);
        $this->faulty(record: fn (?CivilRecord $r) => self::inRelease($r, 2));

        $this->assertRefusedWithoutDisclosure();
    }

    public function test_inconsistent_counts_show_nothing(): void
    {
        $this->open();
        $this->source->add('401234567', ['fatherid' => self::HEAD, 'fullname' => 'اسم-سري'])->add('402234567', ['fatherid' => self::HEAD, 'fullname' => 'اسم-سري']);
        $cap = CivilRegistrySource::LINKS_CAP;
        foreach ([
            fn (CivilRecordPage $p) => new CivilRecordPage($p->releaseId, $cap + 1, $p->rows, $cap),       // too broad, yet rows
            fn (CivilRecordPage $p) => new CivilRecordPage($p->releaseId, 5, $p->rows, $cap),              // a partial page
            fn (CivilRecordPage $p) => new CivilRecordPage($p->releaseId, 1, $p->rows, $cap),              // more rows than the total
            fn (CivilRecordPage $p) => new CivilRecordPage($p->releaseId, $p->total, $p->rows, $cap + 50), // another cap
        ] as $rewrite) {
            $this->faulty($rewrite);
            $this->assertRefusedWithoutDisclosure();
        }
    }

    public function test_no_source_is_bound_by_the_application(): void
    {
        $this->assertFalse($this->app->bound(RegistryChildSource::class));
        config(['family_registry_admission.mode' => 'ON']);
        $this->head['user']->givePermissionTo(self::PERMISSION);

        // Even with the gate open, nothing is read and nothing is recorded.
        $this->assertUnavailable($this->fetch(), 503, 'REGISTRY_UNAVAILABLE');
        $this->assertSame(0, $this->events());
    }

    public function test_discovery_writes_only_its_audit_row_and_never_identity_ref(): void
    {
        $this->open();
        $this->source->add('401234567', ['fatherid' => self::HEAD]);
        $counts = fn () => [DB::table('persons')->count(), DB::table('family_memberships')->count(), DB::table('change_requests')->count(),
            DB::table('family_registry_admissions')->count(), DB::table('families')->count()];
        $before = $counts();

        DB::enableQueryLog();
        $this->fetch()->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');

        $this->assertSame($before, $counts());
        $writes = $queries->filter(fn (string $q) => preg_match('/^\s*(insert|update|delete)\b/i', $q) === 1)->values();
        $this->assertSame(1, $writes->count(), $writes->implode("\n"));
        $this->assertStringContainsString('family_registry_admission_events', $writes->first());
        $this->assertSame([], $queries->filter(fn (string $q) => str_contains($q, 'identity_ref'))->values()->all());
    }
}
