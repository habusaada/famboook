<?php

namespace Tests\Feature\FamilyPortal;

use App\Exceptions\CivilLookupException;
use App\Models\User;
use App\Support\CivilLookup\CivilRegistrySource;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildSource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\Support\FamilyPortal\RevokesFamilyUserAdmissionGrant;
use Tests\Support\FamilyPortal\SyntheticRegistryChildAdapter as Adapter;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 ADD-WP-06B-4: the synthetic RegistryChildSource adapter, its
 * contract, release integrity and bounded cost, on both drivers. Synthetic data
 * only; the permission is given DIRECTLY to the test account and the flag is
 * opened only inside these tests. Set FAMBOOOK_PERF_REPORT=1 to print the costs.
 */
class SyntheticRegistryChildAdapterTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase, RevokesFamilyUserAdmissionGrant;

    private const URI = '/api/v1/family/household/registry-children';

    private const HEAD = '123456789';

    private const PERMISSION = 'family-member.admit-from-registry';

    private array $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        $this->head = $this->activatedHead(self::HEAD);
        // The real registry must never be reached, whatever happens.
        $this->app->instance(CivilRegistrySource::class, new class
        {
            public function __call(string $name, array $arguments): never
            {
                throw new RuntimeException('The civil registry must not be read.');
            }
        });
    }

    private function open(Adapter $adapter, string $mode = 'ON'): Adapter
    {
        $this->app->instance(RegistryChildSource::class, $adapter);
        config(['family_registry_admission.mode' => $mode]);
        $this->head['user']->givePermissionTo(self::PERMISSION);

        return $adapter;
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

    // ---------------------------------------------------------------- the adapter

    public function test_it_cannot_be_built_outside_the_test_runner(): void
    {
        $this->app['env'] = 'production';
        try {
            Adapter::household(self::HEAD, 1);
            $this->fail('The synthetic adapter was built outside the test runner.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_the_probe_is_bounded_and_never_reports_a_larger_total(): void
    {
        foreach ([0 => [0, 0], 1 => [1, 1], 25 => [25, 25], 100 => [100, 100], 101 => [101, 0], 5000 => [101, 0]] as $children => [$total, $rows]) {
            $adapter = Adapter::household(self::HEAD, $children, others: 3);
            $page = $adapter->childrenOf(self::HEAD, 0, CivilRegistrySource::LINKS_CAP);

            $this->assertSame([3, $total, $rows, 100], [$page->releaseId, $page->total, count($page->rows), $page->cap], (string) $children);
            $this->assertLessThanOrEqual(100, $adapter->held, (string) $children);
            foreach ($page->rows as $row) {
                $this->assertSame(3, $row->releaseId);
            }
        }
        // The probe stops at the 101st match: the head, 3 others and 2 rows (two releases) per child.
        $this->assertSame(1 + 3 + 2 * 101, $adapter->scanned);
    }

    public function test_it_refuses_an_unbounded_call(): void
    {
        $adapter = Adapter::household(self::HEAD, 1);
        foreach ([[0, 0], [0, 101], [-1, 10]] as [$offset, $limit]) {
            try {
                $adapter->childrenOf(self::HEAD, $offset, $limit);
                $this->fail("offset {$offset}, limit {$limit} was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $adapter->calls);
    }

    public function test_it_reads_only_the_published_release(): void
    {
        $adapter = Adapter::household(self::HEAD, 3);
        $page = $adapter->childrenOf(self::HEAD, 0, 100);
        $this->assertSame([3, 3, 3], array_map(fn ($r) => $r->releaseId, $page->rows));
        $this->assertSame(3, $adapter->recordOf(self::HEAD, 3)?->releaseId);

        $adapter->publish(2);
        $this->assertSame([2, 2, 2], array_map(fn ($r) => $r->releaseId, $adapter->childrenOf(self::HEAD, 0, 100)->rows));
        $this->assertNull($adapter->recordOf(self::HEAD, 2), 'The head exists only in release 3.');
        try {
            $adapter->recordOf(self::HEAD, 3);
            $this->fail('A record of an unpublished release was read.');
        } catch (CivilLookupException $e) {
            $this->assertSame(CivilLookupException::RELEASE_CHANGED, $e->reason);
        }

        $adapter->publish(null);
        $this->assertSame([null, 0, []], [($p = $adapter->childrenOf(self::HEAD, 0, 100))->releaseId, $p->total, $p->rows]);
    }

    // ---------------------------------------------------------------- through the API

    public function test_listing_through_the_adapter(): void
    {
        foreach ([1 => 1, 25 => 25, 100 => 25] as $children => $shown) {
            $this->open(Adapter::household(self::HEAD, $children));
            $response = $this->fetch()->assertOk();
            $this->assertCount($shown, $response->json('data.children'), (string) $children);
            $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => $children], $response->json('data.meta'));
        }
        $this->assertCount(25, $this->fetch('?page=4')->assertOk()->json('data.children'));
        $this->assertSame(['ابن-تجريبي'], array_values(array_unique(array_column($this->fetch('?page=2')->json('data.children'), 'display_name'))));
        $this->assertSame([3], DB::table('family_registry_admission_events')->pluck('release_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
    }

    public function test_more_than_the_cap_is_409_and_the_count_never_leaks(): void
    {
        foreach ([101, 5000] as $children) {
            $adapter = $this->open(Adapter::household(self::HEAD, $children));
            $response = $this->fetch();
            $response->assertStatus(409)->assertExactJson(['message' => $response->json('message'), 'code' => 'RESULT_SET_TOO_BROAD']);
            foreach (['5000', '101', '500000', 'ابن-تجريبي', 'total', 'children'] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $response->getContent(), "{$children}: {$secret}");
            }
            $this->assertSame([['childrenOf', self::HEAD]], $adapter->calls, 'No head record is read for a refusal.');
        }
        $this->assertSame([0, 0], DB::table('family_registry_admission_events')->pluck('result_count')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(['RESULT_SET_TOO_BROAD'], DB::table('family_registry_admission_events')->distinct()->pluck('outcome_code')->all());
    }

    public function test_no_published_release_is_503_without_children(): void
    {
        $adapter = $this->open(Adapter::household(self::HEAD, 3));
        $adapter->publish(null);

        $response = $this->fetch();
        $response->assertStatus(503)->assertJsonPath('code', 'REGISTRY_UNAVAILABLE');
        $this->assertStringNotContainsString('ابن-تجريبي', (string) $response->getContent());
        $this->assertSame([['childrenOf', self::HEAD]], $adapter->calls);
        $this->assertSame(0, $this->events());
    }

    public function test_a_release_published_between_the_two_reads_is_503(): void
    {
        $adapter = $this->open(Adapter::household(self::HEAD, 3));
        $adapter->afterChildren = fn (Adapter $a) => $a->publish(4);

        $response = $this->fetch();
        $response->assertStatus(503)->assertJsonPath('code', 'REGISTRY_UNAVAILABLE');
        $this->assertStringNotContainsString('ابن-تجريبي', (string) $response->getContent());
        $this->assertSame([['childrenOf', self::HEAD], ['recordOf', self::HEAD]], $adapter->calls);
        $this->assertSame(0, $this->events());
    }

    // ---------------------------------------------------------------- cost (synthetic; not a Production measurement)

    public function test_the_cost_is_bounded_whatever_the_number_of_children(): void
    {
        config(['family_registry_admission.limits.discovery_user_minute' => 100]);
        // One unmeasured request first, so class loading is not counted as memory.
        $this->open(Adapter::household(self::HEAD, 1));
        $this->fetch()->assertOk();
        $costs = [];
        foreach ([1, 25, 100, 101, 5000] as $children) {
            $adapter = $this->open(Adapter::household(self::HEAD, $children, others: 200));
            DB::flushQueryLog();
            DB::enableQueryLog();
            memory_reset_peak_usage();
            $before = memory_get_usage();
            $status = $this->fetch()->status();
            $peak = memory_get_peak_usage() - $before;
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            $costs[$children] = compact('status', 'queries', 'peak') + ['calls' => count($adapter->calls), 'scanned' => $adapter->scanned, 'held' => $adapter->held];
            $this->report(sprintf("children=%d status=%d queries=%d source_calls=%d last_scan=%d held=%d peak_kb=%d\n",
                $children, $status, $queries, count($adapter->calls), $adapter->scanned, $adapter->held, intdiv($peak, 1024)));
        }

        $this->assertSame([200, 200, 200, 409, 409], array_column($costs, 'status'));
        $this->assertSame([2, 2, 2, 1, 1], array_column($costs, 'calls'));
        // The registry facts of a page are read in one batch: the same queries for 1 or 25 children.
        $this->assertSame($costs[1]['queries'], $costs[25]['queries']);
        $this->assertSame($costs[25]['queries'], $costs[100]['queries']);
        $this->assertSame($costs[101]['queries'], $costs[5000]['queries']);
        $this->assertLessThan($costs[1]['queries'], $costs[101]['queries']);
        foreach ($costs as $children => $cost) {
            $this->assertLessThanOrEqual(100, $cost['held'], (string) $children);
        }
        // The probe stops at the 101st match: 5000 children cost the same scan as 101.
        $this->assertSame($costs[101]['scanned'], $costs[5000]['scanned']);
    }

    private function report(string $line): void
    {
        if (getenv('FAMBOOOK_PERF_REPORT') === '1') {
            fwrite(STDERR, $line);
        }
    }

    // ---------------------------------------------------------------- security

    public function test_off_and_a_missing_permission_never_read_the_adapter(): void
    {
        $this->revokeFamilyUserAdmissionGrant();
        $adapter = Adapter::household(self::HEAD, 3);
        $this->app->instance(RegistryChildSource::class, $adapter);

        config(['family_registry_admission.mode' => 'ON']);
        $this->fetch()->assertNotFound()->assertJsonPath('code', 'ADMISSION_UNAVAILABLE');
        $this->head['user']->givePermissionTo(self::PERMISSION);
        foreach (['OFF', null, 'INVALID'] as $mode) {
            config(['family_registry_admission.mode' => $mode]);
            $this->fetch()->assertNotFound()->assertJsonPath('code', 'ADMISSION_UNAVAILABLE');
        }
        $this->assertSame([], $adapter->calls);
        $this->assertSame(0, $this->events());
    }

    public function test_another_households_children_never_appear(): void
    {
        $other = $this->activatedHead('223456789');
        $other['user']->givePermissionTo(self::PERMISSION);
        $this->open(new Adapter(function () {
            yield Adapter::make(3, 1, '500000001', ['fatherid' => self::HEAD, 'fullname' => 'ابن-الأول']);
            yield Adapter::make(3, 2, '500000002', ['fatherid' => '223456789', 'fullname' => 'ابن-الثاني']);
            yield Adapter::make(3, 3, '500000003', ['motherid' => '223456789', 'fullname' => 'ابنة-الثاني']);
        }, 3));

        $this->assertSame(['ابن-الأول'], array_column($this->fetch()->json('data.children'), 'display_name'));
        $this->assertSame(['ابن-الثاني', 'ابنة-الثاني'], array_column($this->fetch(as: $other['user'])->json('data.children'), 'display_name'));
    }

    public function test_the_response_carries_no_identifiers_or_reasons_and_changes_nothing(): void
    {
        $this->open(Adapter::household(self::HEAD, 3, fields: ['deathdate' => '2020-05-01T00:00:00', 'householdid' => '777777770']));
        $counts = fn () => [DB::table('persons')->count(), DB::table('family_memberships')->count(), DB::table('change_requests')->count(),
            DB::table('family_registry_admissions')->count(), DB::table('families')->count()];
        $before = $counts();

        DB::enableQueryLog();
        $response = $this->fetch()->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');

        $body = (string) $response->getContent();
        foreach (['500000001', '500000002', '0001', self::HEAD, '777777770', '0590000000', '2010-01-15', 'اسم-الأب',
            'DECEASED', 'LIFE_UNVERIFIED', 'reason', 'national', 'masked', 'relationship', 'release', 'payload', 'record'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret);
        }
        $this->assertSame(['NOT_ELIGIBLE'], array_values(array_unique(array_column($response->json('data.children'), 'status'))));
        $this->assertSame($before, $counts());
        $writes = $queries->filter(fn (string $q) => preg_match('/^\s*(insert|update|delete)\b/i', $q) === 1)->values();
        $this->assertSame(1, $writes->count(), $writes->implode("\n"));
        $this->assertStringContainsString('family_registry_admission_events', $writes->first());
        $this->assertSame([], $queries->filter(fn (string $q) => str_contains($q, 'identity_ref'))->values()->all());
    }

    public function test_the_application_binds_no_source_by_default_and_ships_only_the_civil_registry_adapter(): void
    {
        $this->assertFalse($this->app->bound(RegistryChildSource::class));
        $this->assertStringStartsWith(realpath(base_path('tests')), (string) (new ReflectionClass(Adapter::class))->getFileName());

        // RR-02: the approved adapter over the published registry, and the ONE place that may
        // bind it (behind family_registry_admission.civil_registry_binding, false by default).
        $allowed = array_map('realpath', [
            app_path('Support/FamilyPortal/RegistryAdmission/CivilRegistryChildSource.php'),
            app_path('Support/FamilyPortal/RegistryAdmission/RegistryAdmissionBindings.php'),
            app_path('Providers/AppServiceProvider.php'),
        ]);
        $shipped = [];
        foreach (['app', 'bootstrap', 'config', 'routes', 'database'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                if (preg_match('/Synthetic\w*Registry/', $code) === 1
                    || (preg_match('/implements\s+[^{]*RegistryChildSource\b|CivilRegistryChildSource|(bind|singleton|instance|scoped)\(\s*RegistryChildSource::class/', $code) === 1
                        && ! in_array(realpath($file->getPathname()), $allowed, true))) {
                    $shipped[] = $file->getPathname();
                }
            }
        }
        $this->assertSame([], $shipped);
    }
}
