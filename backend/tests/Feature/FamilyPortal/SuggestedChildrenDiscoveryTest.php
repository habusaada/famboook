<?php

namespace Tests\Feature\FamilyPortal;

use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\FamilyAccessDenial;
use App\Enums\RegistryChildStatus as Status;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Support\CivilLookup\CivilRegistrySource;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildSource;
use App\Support\FamilyPortal\RegistryAdmission\SuggestedChild;
use App\Support\FamilyPortal\RegistryAdmission\SuggestedChildrenDiscovery;
use App\Support\FamilyPortal\RegistryAdmission\SuggestedChildrenPage;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\Support\FamilyPortal\SyntheticRegistryChildSource;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 ADD-WP-06A: internal read-only discovery of suggested children, on
 * both drivers. Synthetic records and synthetic National IDs only. While gates G-03,
 * G-04 and G-05 are open nothing can be ELIGIBLE, and nothing is admitted.
 */
class SuggestedChildrenDiscoveryTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const HEAD = '123456789';

    private FamilyAccessResult $context;

    private SyntheticRegistryChildSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->context = app(FamilyAccessResolver::class)->familyContext($this->activatedHead(self::HEAD)['user']);
        $this->assertTrue($this->context->hasFamilyContext());
        $this->source = new SyntheticRegistryChildSource;
        // The real registry must never be reached.
        $this->app->instance(CivilRegistrySource::class, new class
        {
            public function __call(string $name, array $arguments): never
            {
                throw new RuntimeException('The civil registry must not be read.');
            }
        });
    }

    private function discover(int $page = 1, int $perPage = SuggestedChildrenDiscovery::PER_PAGE): SuggestedChildrenPage
    {
        return (new SuggestedChildrenDiscovery($this->source))->discover($this->context, $page, $perPage);
    }

    private function onlyChild(): SuggestedChild
    {
        $page = $this->discover();
        $this->assertCount(1, $page->children);

        return $page->children[0];
    }

    private function member(string $nationalId, Family $family, bool $active = true): Person
    {
        $person = Person::factory()->create(['national_id' => $nationalId]);
        FamilyMembership::factory()->create(['family_id' => $family->id, 'person_id' => $person->id, 'is_household_head' => false, 'is_active' => $active,
            ...($active ? [] : ['started_at' => '2001-01-01', 'ended_at' => '2002-01-01'])]);

        return $person;
    }

    private function openRequestFor(string $nationalId): void
    {
        ChangeRequest::factory()->create([
            'family_id' => $this->context->family->id, 'type' => ChangeRequestType::ADD_FAMILY_MEMBER, 'status' => ChangeRequestStatus::SUBMITTED,
            'submitted_data' => ['full_name' => 'اسم-تجريبي', 'national_id' => $nationalId, 'gender' => 'MALE', 'relationship' => 'SON'],
        ]);
    }

    // 1, 2
    public function test_children_are_found_through_the_father_or_the_mother_field(): void
    {
        $this->source->add('401234567', ['fatherid' => self::HEAD])->add('402234567', ['motherid' => self::HEAD, 'gender' => 'أنثى']);

        $page = $this->discover();
        $this->assertSame(['*****4567', '*****4567'], array_column($page->children, 'nationalIdMasked'));
        $this->assertSame(['اسم-تجريبي 401234567', 'اسم-تجريبي 402234567'], array_column($page->children, 'fullName'));
        $this->assertSame(['2010-01-15', '2010-01-15'], array_column($page->children, 'birthDate'));
        $this->assertSame(2, $page->total);
    }

    public function test_a_record_naming_the_head_as_neither_parent_is_dropped_even_if_the_source_returns_it(): void
    {
        $this->source->add('401234567', ['fatherid' => self::HEAD]);
        $this->source->stray[] = $this->source->make('409234567', ['fatherid' => '', 'motherid' => '', 'family' => 'عائلة-رب-الأسرة']);
        $this->source->stray[] = $this->source->make('408234567', ['fatherid' => ' '.self::HEAD, 'motherid' => '']);

        $page = $this->discover();
        $this->assertSame(['اسم-تجريبي 401234567'], array_column($page->children, 'fullName'));
        $this->assertSame(1, $page->total);
    }

    public function test_both_parent_fields_naming_the_head_is_listed_but_not_eligible(): void
    {
        $this->source->add('401234567', ['fatherid' => self::HEAD, 'motherid' => self::HEAD]);
        $this->openRequestFor('401234567');

        $this->assertSame(Status::NOT_ELIGIBLE, $this->onlyChild()->status);
    }

    // 3, 4
    public function test_a_married_child_or_one_heading_another_household_stays_discoverable(): void
    {
        $this->source->add('401234567', ['fatherid' => self::HEAD, 'maritalst' => 'متزوج', 'partnerid' => '501234567'])
            ->add('402234567', ['fatherid' => self::HEAD, 'maritalst' => 'متعدد الزوجات', 'householdid' => '402234567']);
        $own = Family::factory()->create();
        $head = Person::factory()->create(['national_id' => '402234567']);
        FamilyMembership::factory()->create(['family_id' => $own->id, 'person_id' => $head->id, 'is_household_head' => true, 'is_active' => true]);

        $page = $this->discover();
        $this->assertCount(2, $page->children);
        $this->assertSame([Status::NOT_ELIGIBLE, Status::NOT_ELIGIBLE], array_column($page->children, 'status'));
    }

    // 5, 6, 7
    public function test_membership_classification(): void
    {
        $this->source->add('401234567', ['fatherid' => self::HEAD])->add('402234567', ['fatherid' => self::HEAD])->add('403234567', ['fatherid' => self::HEAD]);
        $this->member('401234567', $this->context->family);
        $this->member('402234567', Family::factory()->create());
        $this->member('403234567', Family::factory()->create(), active: false);
        $this->openRequestFor('403234567');

        $statuses = array_column($this->discover()->children, 'status', 'fullName');
        $this->assertSame(Status::ALREADY_MEMBER, $statuses['اسم-تجريبي 401234567']);
        $this->assertSame(Status::NOT_ELIGIBLE, $statuses['اسم-تجريبي 402234567']);
        // A historical membership alone refuses nothing: only the open request shows.
        $this->assertSame(Status::REQUEST_OPEN, $statuses['اسم-تجريبي 403234567']);
    }

    // 8
    public function test_invalid_national_ids_are_listed_masked_and_not_eligible(): void
    {
        foreach (['12', '40123456a', '000000000', '٤٠١٢٣٤٥٦٧'] as $id) {
            $this->source->add($id, ['fatherid' => self::HEAD]);
        }
        $page = $this->discover();

        $this->assertCount(4, $page->children);
        foreach ($page->children as $child) {
            $this->assertSame(Status::NOT_ELIGIBLE, $child->status);
            $this->assertStringStartsWith('*****', (string) $child->nationalIdMasked);
        }
    }

    // 9
    public function test_duplicate_source_rows_are_listed_once_and_refused(): void
    {
        $this->source->add('401234567', ['fatherid' => self::HEAD, 'fullname' => 'اسم-تجريبي أول'])
            ->add('401234567', ['fatherid' => self::HEAD, 'fullname' => 'اسم-تجريبي ثان']);
        $this->openRequestFor('401234567');

        $page = $this->discover();
        $this->assertSame(1, $page->total);
        // The lowest record id stands for the group; the open request does not win.
        $this->assertSame('اسم-تجريبي أول', $page->children[0]->fullName);
        $this->assertSame(Status::NOT_ELIGIBLE, $page->children[0]->status);
    }

    // 10
    public function test_reciprocal_parentage_anomalies_are_refused(): void
    {
        // The head's own record names 401… as the head's father.
        $this->source->add(self::HEAD, ['fatherid' => '401234567'])
            ->add('401234567', ['fatherid' => self::HEAD])
            // 403… names the head as father and the head's other child 402… as mother.
            ->add('402234567', ['motherid' => self::HEAD, 'gender' => 'أنثى'])
            ->add('403234567', ['fatherid' => self::HEAD, 'motherid' => '402234567'])
            // A record naming itself as its other parent.
            ->add('404234567', ['fatherid' => self::HEAD, 'motherid' => '404234567']);
        foreach (['401234567', '403234567', '404234567'] as $id) {
            $this->openRequestFor($id);
        }
        $this->openRequestFor('402234567');

        $statuses = array_column($this->discover()->children, 'status', 'fullName');
        $this->assertSame(Status::NOT_ELIGIBLE, $statuses['اسم-تجريبي 401234567']);
        $this->assertSame(Status::NOT_ELIGIBLE, $statuses['اسم-تجريبي 403234567']);
        $this->assertSame(Status::NOT_ELIGIBLE, $statuses['اسم-تجريبي 404234567']);
        // 402… itself is consistent: its open request is shown.
        $this->assertSame(Status::REQUEST_OPEN, $statuses['اسم-تجريبي 402234567']);
        $this->assertArrayNotHasKey('اسم-تجريبي '.self::HEAD, $statuses);
    }

    // 11, 12, 19
    public function test_unknown_values_and_missing_proof_of_life_never_yield_eligible(): void
    {
        $this->source->add('401234567', ['fatherid' => self::HEAD])
            ->add('402234567', ['fatherid' => self::HEAD, 'gender' => 'M'])
            ->add('403234567', ['fatherid' => self::HEAD, 'gender' => 'غير معروف', 'maritalst' => 'X'])
            ->add('404234567', ['fatherid' => self::HEAD, 'presonstate' => 'حي'])
            ->add('405234567', ['fatherid' => self::HEAD, 'deathdate' => '2020-05-01T00:00:00'])
            ->add('406234567', ['fatherid' => self::HEAD, 'gender' => 'أنثى', 'datebirth' => '15/01/2010']);

        $page = $this->discover();
        $this->assertCount(6, $page->children);
        foreach ($page->children as $child) {
            $this->assertSame(Status::NOT_ELIGIBLE, $child->status, (string) $child->fullName);
            $this->assertNull($child->relationship, (string) $child->fullName);
        }
        $this->assertNull(array_column($page->children, 'birthDate', 'fullName')['اسم-تجريبي 406234567']);
    }

    // 13, 14
    public function test_every_refusal_is_one_status_and_nothing_about_another_household_leaks(): void
    {
        $other = Family::factory()->create();
        $elsewhere = $this->member('402234567', $other);
        $deleted = Person::factory()->create(['national_id' => '403234567']);
        $deleted->delete();
        // Names without the ID: the fixture default embeds it.
        $this->source->add('401234567', ['fatherid' => self::HEAD, 'deathdate' => '2020-05-01T00:00:00', 'fullname' => 'اسم-تجريبي أ'])
            ->add('402234567', ['fatherid' => self::HEAD, 'householdid' => '777777770', 'fullname' => 'اسم-تجريبي ب'])
            ->add('403234567', ['fatherid' => self::HEAD, 'fullname' => 'اسم-تجريبي ج']);

        $page = $this->discover();
        $this->assertSame([Status::NOT_ELIGIBLE, Status::NOT_ELIGIBLE, Status::NOT_ELIGIBLE], array_column($page->children, 'status'));

        $dump = serialize($page).json_encode($page, JSON_UNESCAPED_UNICODE);
        foreach ([$other->family_code, '"family_id"', 'membership', $elsewhere->person_code, 'ACTIVE_MEMBERSHIP_ELSEWHERE', 'DECEASED',
            'SOFT_DELETED', 'reasons', '401234567', '402234567', self::HEAD, '777777770', '0590000000', 'fatherid', 'motherid', 'householdid',
            'deathdate', 'presonstate', 'recordId', 'payload'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump, $secret);
        }
        $this->assertSame(['fullName', 'nationalIdMasked', 'birthDate', 'relationship', 'status'], array_keys(get_object_vars($page->children[0])));
    }

    // 15
    public function test_pagination_is_deterministic_and_complete(): void
    {
        foreach (['407234567', '401234567', '405234567', '403234567', '402234567', '406234567', '404234567'] as $id) {
            $this->source->add($id, ['fatherid' => self::HEAD]);
        }

        $names = fn (int $p) => array_column($this->discover($p, 3)->children, 'fullName');
        $all = [...$names(1), ...$names(2), ...$names(3)];
        $this->assertSame(array_map(fn ($n) => 'اسم-تجريبي 40'.$n.'234567', range(1, 7)), $all);
        $this->assertSame($names(2), $names(2));
        $this->assertSame([], $names(4));
        $this->assertSame(7, $this->discover(4, 3)->total);
    }

    // 16
    public function test_page_size_and_source_reads_are_bounded(): void
    {
        foreach ([[0, 25], [1, 0], [1, -1], [1, SuggestedChildrenDiscovery::MAX_PER_PAGE + 1]] as [$page, $size]) {
            try {
                $this->discover($page, $size);
                $this->fail("page {$page} size {$size} was accepted");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(100, SuggestedChildrenDiscovery::MAX_PER_PAGE);
        $this->assertSame(25, SuggestedChildrenDiscovery::PER_PAGE);

        for ($i = 0; $i < 30; $i++) {
            $this->source->add(sprintf('41%07d', $i + 1), ['fatherid' => self::HEAD]);
        }
        $page = $this->discover();
        $this->assertCount(25, $page->children);
        $this->assertSame(30, $page->total);
        $this->assertSame([['childrenOf', self::HEAD, 0, 100], ['recordOf', self::HEAD, 0, 1]], $this->source->calls);
    }

    public function test_more_matches_than_the_cap_lists_nothing(): void
    {
        for ($i = 0; $i < 101; $i++) {
            $this->source->add(sprintf('41%07d', $i + 1), ['fatherid' => self::HEAD]);
        }
        $page = $this->discover();

        $this->assertTrue($page->tooBroad);
        $this->assertSame([], $page->children);
        $this->assertSame(0, $page->total);
    }

    public function test_only_the_requested_page_is_evaluated(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->source->add(sprintf('41%07d', $i + 1), ['fatherid' => self::HEAD]);
        }
        $count = function (int $size): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->discover(1, $size);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $one = $count(1);
        $five = $count(5);
        $perChild = intdiv($five - $one, 4);
        $this->assertSame(0, ($five - $one) % 4);
        $this->assertSame($one + 29 * $perChild, $count(30));

        // More source records do not add queries: only the page is evaluated.
        for ($i = 30; $i < 60; $i++) {
            $this->source->add(sprintf('41%07d', $i + 1), ['fatherid' => self::HEAD]);
        }
        $this->assertSame($five, $count(5));
    }

    // 17
    public function test_the_parent_comes_only_from_the_context_and_the_real_registry_is_never_used(): void
    {
        $this->source->add('401234567', ['fatherid' => self::HEAD]);
        $this->discover();

        $this->assertSame([self::HEAD], array_values(array_unique(array_column($this->source->calls, 1))));
        $this->expectException(LogicException::class);
        (new SuggestedChildrenDiscovery($this->source))->discover(FamilyAccessResult::denied(FamilyAccessDenial::NO_LINK));
    }

    public function test_the_source_is_not_bound_so_discovery_cannot_reach_a_registry_by_accident(): void
    {
        $this->assertFalse($this->app->bound(RegistryChildSource::class));
        $this->expectException(BindingResolutionException::class);
        $this->app->make(SuggestedChildrenDiscovery::class);
    }

    // 18
    public function test_discovery_writes_nothing(): void
    {
        $this->source->add('401234567', ['fatherid' => self::HEAD])->add('402234567', ['fatherid' => self::HEAD]);
        $this->member('402234567', Family::factory()->create());
        $tables = ['persons', 'family_memberships', 'family_activities', 'change_requests', 'family_registry_admissions', 'family_registry_admission_events'];
        $counts = fn () => array_map(fn (string $t) => DB::table($t)->count(), $tables);
        $before = $counts();

        DB::enableQueryLog();
        $this->discover();
        $queries = collect(DB::getQueryLog())->pluck('query');

        $this->assertSame($before, $counts());
        $this->assertSame([], $queries->filter(fn (string $q) => preg_match('/\b(insert|update|delete)\b/i', $q) === 1)->values()->all());
        $this->assertSame([], $queries->filter(fn (string $q) => str_contains($q, 'identity_ref'))->values()->all());
    }

    // 19
    public function test_flag_stays_off_and_nothing_is_admissible(): void
    {
        $this->assertSame('OFF', config('family_registry_admission.mode'));
        $this->source->add('401234567', ['fatherid' => self::HEAD]);
        $this->assertNotSame(Status::ELIGIBLE, $this->onlyChild()->status);
    }

    // 20
    // ADD-WP-06B-3: one guarded GET route reaches discovery, and no source is bound for it.
    public function test_only_the_guarded_route_reaches_discovery(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'registry-children')
            || str_contains((string) $route->getActionName(), 'RegistryChildren') || str_contains((string) $route->getActionName(), 'RegistryAdmission'));
        $this->assertSame(['api/v1/family/household/registry-children'], $routes->map(fn ($route) => $route->uri())->values()->all());
        $this->assertSame(['GET', 'HEAD'], $routes->first()->methods());
        $this->assertContains('family.context', $routes->first()->gatherMiddleware());
        $this->assertContains('family.registry-discovery', $routes->first()->gatherMiddleware());
        $this->assertFalse($this->app->bound(RegistryChildSource::class));
    }
}
