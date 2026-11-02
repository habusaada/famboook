<?php

namespace Tests\Feature\FamilyPortal;

use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\LifeStatus;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Support\CivilLookup\CivilRecord;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildEligibility;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\Support\LegacyNationalIds;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 ADD-WP-06B-1: the page's registry facts read in a batch give
 * exactly the reasons of the per-record evaluation, on both drivers and for legacy
 * National ID formats. Synthetic data and synthetic National IDs only.
 */
class RegistryChildFactsTest extends TestCase
{
    use FamilyIdentityFixtures, LegacyNationalIds, RefreshDatabase;

    private const HEAD = '123456789';

    private FamilyAccessResult $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->context = app(FamilyAccessResolver::class)->familyContext($this->activatedHead(self::HEAD)['user']);
    }

    private function record(string $mainid): CivilRecord
    {
        return new CivilRecord(1, 1, $mainid, ['fullname' => 'اسم-تجريبي', 'gender' => 'ذكر', 'fatherid' => self::HEAD, 'motherid' => '', 'deathdate' => '']);
    }

    private function member(Person $person, Family $family, bool $active = true): void
    {
        FamilyMembership::factory()->create(['family_id' => $family->id, 'person_id' => $person->id, 'is_household_head' => false, 'is_active' => $active,
            ...($active ? [] : ['started_at' => '2001-01-01', 'ended_at' => '2002-01-01'])]);
    }

    private function request(string $nationalId, ?Family $family = null, ChangeRequestStatus $status = ChangeRequestStatus::SUBMITTED): void
    {
        ChangeRequest::factory()->create([
            'family_id' => ($family ?? $this->context->family)->id, 'type' => ChangeRequestType::ADD_FAMILY_MEMBER, 'status' => $status,
            'submitted_data' => ['full_name' => 'اسم-تجريبي', 'national_id' => $nationalId, 'gender' => 'MALE', 'relationship' => 'SON'],
            ...($status === ChangeRequestStatus::CANCELLED ? ['cancelled_at' => now(), 'cancelled_by' => $this->context->user->id] : []),
        ]);
    }

    /** The canonical cases, valid under the PostgreSQL constraints. */
    private function canonicalCases(): array
    {
        // A household with a head: a request needs a submitting Person (chk_change_request_submitter_person).
        $other = FamilyMembership::factory()->householdHead()->create()->family;
        $this->member(Person::factory()->create(['national_id' => '401234567']), $this->context->family);
        $this->member(Person::factory()->create(['national_id' => '402234567']), $other);
        $this->member(Person::factory()->create(['national_id' => '403234567']), $other, active: false);
        Person::factory()->create(['national_id' => '404234567'])->delete();
        Person::factory()->create(['national_id' => '405234567', 'life_status' => LifeStatus::DECEASED]);
        Person::factory()->create(['national_id' => '406234567', 'life_status' => LifeStatus::UNKNOWN]);
        Person::factory()->create(['national_id' => '407234567', 'is_active' => false]);
        $this->request('408234567');
        $this->request('409234567', $other);
        $this->request('410234567', status: ChangeRequestStatus::CANCELLED);
        $this->request('403234567');

        // 411… has nothing at all; then malformed and the head, which read no registry.
        return ['401234567', '402234567', '403234567', '404234567', '405234567', '406234567', '407234567', '408234567', '409234567',
            '410234567', '411234567', '12', '40123456a', '000000000', '٤٠١٢٣٤٥٦٧', '401-234-567', self::HEAD];
    }

    /** Legacy stored formats: Arabic digits, separators, ambiguity and more holders than the cap. */
    private function legacyCases(): array
    {
        $this->allowLegacyNationalIds();
        Person::factory()->create(['national_id' => '٤٢١٢٣٤٥٦٧']);
        Person::factory()->create(['national_id' => '422-234-567']);
        Person::factory()->create(['national_id' => '423 234 567']);
        Person::factory()->create(['national_id' => '423.234.567']);
        foreach (['424234567', '424-234567', '424 234567', '424/234/567', '٤٢٤٢٣٤٥٦٧', '424_234_567', '424.234.567'] as $id) {
            Person::factory()->create(['national_id' => $id]);
        }
        $this->member(Person::factory()->create(['national_id' => '۴۲۵۲۳۴۵۶۷']), Family::factory()->create());
        Person::factory()->create(['national_id' => '426234567'])->delete();
        Person::factory()->create(['national_id' => '426-234-567']);
        Person::factory()->create(['national_id' => '427-234-567'])->delete();
        Person::factory()->create(['national_id' => null]);

        return ['421234567', '422234567', '423234567', '424234567', '425234567', '426234567', '427234567', '428234567'];
    }

    /** @param  list<string>  $ids */
    private function assertSameAsPerRecord(array $ids): RegistryChildFacts
    {
        $records = array_map(fn (string $id) => $this->record($id), $ids);
        $facts = RegistryChildFacts::load($this->context->family, array_values(array_map(fn (CivilRecord $r) => $r->mainid,
            array_filter($records, fn (CivilRecord $r) => RegistryChildEligibility::readsRegistry($this->context, $r)))));

        foreach ($records as $record) {
            $this->assertSame(
                RegistryChildEligibility::evaluate($this->context, $record)->reasons,
                RegistryChildEligibility::evaluate($this->context, $record, $facts)->reasons,
                $record->mainid,
            );
        }

        return $facts;
    }

    public function test_batched_reasons_equal_per_record_reasons_for_canonical_data(): void
    {
        $facts = $this->assertSameAsPerRecord($this->canonicalCases());

        // On PostgreSQL the constraints are present and validated: indexed exact matching.
        $this->assertSame(DB::getDriverName() === 'pgsql' ? RegistryChildFacts::INDEXED_EXACT : RegistryChildFacts::NORMALIZED_SCAN, $facts->strategy);
    }

    public function test_batched_reasons_equal_per_record_reasons_for_legacy_formats(): void
    {
        $facts = $this->assertSameAsPerRecord($this->legacyCases());

        // Without the constraints (dropped for this test) PostgreSQL is NOT trusted either.
        $this->assertSame(RegistryChildFacts::NORMALIZED_SCAN, $facts->strategy);
        $this->assertCount(6, $facts->holders('424234567'), 'capped at MAX_MATCHES + 1, as equivalentMatches()');
        $this->assertCount(1, $facts->holders('421234567'));
        $this->assertCount(2, $facts->holders('423234567'));
    }

    public function test_a_not_valid_constraint_is_not_trusted(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The constraint catalog exists on PostgreSQL only.');
        }
        $this->allowLegacyNationalIds();
        DB::statement("ALTER TABLE persons ADD CONSTRAINT chk_person_national_id_format CHECK (national_id ~ '^[0-9]{9}$' AND national_id !~ '^([0-9])\\1{8}$') NOT VALID");
        DB::statement('ALTER TABLE persons ADD CONSTRAINT uq_persons_national_id UNIQUE (national_id)');

        $this->assertSame(RegistryChildFacts::NORMALIZED_SCAN, RegistryChildFacts::load($this->context->family, ['401234567'])->strategy);
        DB::statement('ALTER TABLE persons VALIDATE CONSTRAINT chk_person_national_id_format');
        $this->assertSame(RegistryChildFacts::INDEXED_EXACT, RegistryChildFacts::load($this->context->family, ['401234567'])->strategy);
    }

    public function test_facts_refuse_what_they_were_not_read_for(): void
    {
        $facts = RegistryChildFacts::load($this->context->family, ['401234567']);
        $this->expectException(LogicException::class);
        $facts->requestOpen('402234567');
    }

    public function test_facts_of_another_household_are_refused(): void
    {
        $facts = RegistryChildFacts::load(Family::factory()->create(), ['401234567']);
        $this->expectException(LogicException::class);
        RegistryChildEligibility::evaluate($this->context, $this->record('401234567'), $facts);
    }

    public function test_input_is_bounded_and_canonical(): void
    {
        foreach ([['12'], ['٤٠١٢٣٤٥٦٧'], ['401-234-567'], array_map(fn ($i) => sprintf('41%07d', $i), range(1, RegistryChildFacts::MAX_IDS + 1))] as $ids) {
            try {
                RegistryChildFacts::load($this->context->family, $ids);
                $this->fail('accepted '.count($ids).' ids');
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, $this->queries(fn () => RegistryChildFacts::load($this->context->family, [])) - $this->catalogQueries());
    }

    /** Measurements for the work-package report, printed only on request (FAMBOOOK_PERF_REPORT=1). */
    private function report(string $line): void
    {
        if (getenv('FAMBOOOK_PERF_REPORT') === '1') {
            fwrite(STDERR, $line);
        }
    }

    private function catalogQueries(): int
    {
        return DB::getDriverName() === 'pgsql' ? 1 : 0;
    }

    private function queries(\Closure $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** Synthetic Persons in bulk (raw rows, not the domain): enough to span several scan chunks. */
    private function bulkPersons(int $count): void
    {
        $now = now();
        foreach (array_chunk(range(1, $count), 500) as $chunk) {
            DB::table('persons')->insert(array_map(fn (int $i) => ['person_code' => sprintf('PER-B%06d', $i), 'full_name' => 'اسم-تجريبي', 'national_id' => sprintf('96%07d', $i),
                'life_status' => LifeStatus::ALIVE->value, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now], $chunk));
        }
    }

    public function test_query_counts_do_not_grow_with_the_page(): void
    {
        $this->canonicalCases();
        $this->bulkPersons(2500);
        $one = ['401234567'];
        $page = array_merge(['401234567', '402234567', '403234567', '404234567', '405234567', '406234567', '407234567', '408234567'],
            array_map(fn ($i) => sprintf('43%07d', $i), range(1, 17)));
        $this->assertCount(25, $page);

        $batchOne = $this->queries(fn () => RegistryChildFacts::load($this->context->family, $one));
        $batchPage = $this->queries(fn () => RegistryChildFacts::load($this->context->family, $page));
        $perRecord = $this->queries(function () use ($page) {
            foreach ($page as $id) {
                RegistryChildEligibility::evaluate($this->context, $this->record($id));
            }
        });

        // Bounded by the page: 1 and 25 children cost the same number of queries.
        $this->assertSame($batchOne, $batchPage);
        if (DB::getDriverName() === 'pgsql') {
            // catalog, holders, holder rows, memberships, families, relationship types, deleted, requests.
            $this->assertLessThanOrEqual(8, $batchPage);
        }
        // The per-record path pays one Person scan per child: here at least 3 chunk queries each.
        $this->assertGreaterThanOrEqual(25 * 3, $perRecord);
        $this->assertLessThan($perRecord, $batchPage);
        $this->report(sprintf("\n[ADD-WP-06B-1 %s] persons=%d batch(1)=%d batch(25)=%d per-record(25)=%d\n",
            DB::getDriverName(), Person::withTrashed()->count(), $batchOne, $batchPage, $perRecord));
    }

    public function test_the_scan_fallback_still_grows_with_the_persons_table(): void
    {
        $this->allowLegacyNationalIds();
        $page = array_map(fn ($i) => sprintf('43%07d', $i), range(1, 25));
        $small = $this->queries(fn () => RegistryChildFacts::load($this->context->family, $page));
        $this->bulkPersons(2500);
        $large = $this->queries(fn () => RegistryChildFacts::load($this->context->family, $page));

        // One chunked pass per page, not per child: still one query per 1000 Persons.
        $this->assertSame(RegistryChildFacts::NORMALIZED_SCAN, RegistryChildFacts::load($this->context->family, $page)->strategy);
        $this->assertGreaterThan($small, $large);
        $this->assertLessThanOrEqual($small + 3, $large);
    }

    public function test_the_indexed_path_uses_the_unique_index(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('EXPLAIN is checked on PostgreSQL only.');
        }
        $this->bulkPersons(2500);
        DB::statement('ANALYZE persons');
        $ids = array_map(fn ($i) => sprintf('96%07d', $i), range(1, 25));
        $in = implode(',', array_fill(0, 25, '?'));
        $plan = fn () => implode("\n", array_map(fn ($r) => $r->{'QUERY PLAN'},
            DB::select("EXPLAIN SELECT id, national_id FROM persons WHERE national_id IN ({$in}) AND deleted_at IS NULL ORDER BY id", $ids)));

        $default = $plan();
        $this->report("\n[ADD-WP-06B-1 EXPLAIN default]\n{$default}\n");
        $this->assertStringNotContainsString('Seq Scan on persons', $default);
        $this->assertStringContainsString('uq_persons_national_id', $default);
    }
}
