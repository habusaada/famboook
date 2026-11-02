<?php

namespace Tests\Feature\FamilyPortal;

use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\FamilyAccessDenial;
use App\Enums\LifeStatus;
use App\Enums\RegistryChildReason as R;
use App\Enums\RegistryChildStatus as Status;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Support\CivilLookup\CivilRecord;
use App\Support\CivilLookup\CivilRegistrySource;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildAssessment;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\Support\LegacyNationalIds;
use Tests\TestCase;

/**
 * FB-FAMILY-ADD-01 ADD-WP-05: the read-only evaluation of a suggested child, on both
 * drivers. Synthetic data and synthetic National IDs only. No record can be ELIGIBLE
 * while gates G-04 and G-05 are open.
 */
class RegistryChildEligibilityTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;
    use LegacyNationalIds;

    private const HEAD = '123456789';

    private const CHILD = '401234567';

    private FamilyAccessResult $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->context = app(FamilyAccessResolver::class)->familyContext($this->activatedHead(self::HEAD)['user']);
        $this->assertTrue($this->context->hasFamilyContext());
        // Any registry read here would be a defect: the record is passed in.
        $this->app->instance(CivilRegistrySource::class, $this->createMock(CivilRegistrySource::class));
    }

    /** A complete synthetic child record of the head, with values as observed. */
    private function child(array $payload = [], string $mainid = self::CHILD): CivilRecord
    {
        return new CivilRecord(3, 77, $mainid, [
            'fullname' => 'اسم-تجريبي ابن-تجريبي', 'gender' => 'ذكر', 'maritalst' => 'اعزب', 'datebirth' => '2010-01-15T00:00:00',
            'fatherid' => self::HEAD, 'motherid' => '', 'deathdate' => '', 'presonstate' => '', ...$payload,
        ]);
    }

    private function evaluate(CivilRecord $record): RegistryChildAssessment
    {
        return RegistryChildEligibility::evaluate($this->context, $record);
    }

    private function person(array $attributes = [], ?Family $activeIn = null, string $nationalId = self::CHILD): Person
    {
        $person = Person::factory()->create(['national_id' => $nationalId, ...$attributes]);
        if ($activeIn !== null) {
            FamilyMembership::factory()->create(['family_id' => $activeIn->id, 'person_id' => $person->id, 'is_household_head' => false, 'is_active' => true]);
        }

        return $person;
    }

    public function test_a_complete_living_looking_record_is_still_not_eligible_while_gates_are_open(): void
    {
        $assessment = $this->evaluate($this->child());

        $this->assertSame([R::GENDER_MAPPING_PROVISIONAL, R::LIFE_UNVERIFIED], $assessment->reasons);
        $this->assertSame(Status::NOT_ELIGIBLE, $assessment->publicStatus());
        $this->assertFalse($assessment->admissible());
        $this->assertSame('FATHER', $assessment->parentLink);
    }

    public function test_no_combination_of_values_yields_eligible(): void
    {
        foreach ([[], ['gender' => 'أنثى', 'fatherid' => '', 'motherid' => self::HEAD], ['maritalst' => 'متزوج'], ['presonstate' => 'حي'],
            ['datebirth' => '1990-01-01T00:00:00'], ['maritalst' => 'متعدد الزوجات']] as $payload) {
            $this->assertFalse($this->evaluate($this->child($payload))->admissible(), json_encode($payload, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_missing_or_conflicting_life_evidence_always_blocks(): void
    {
        $cases = [
            [['deathdate' => ''], R::LIFE_UNVERIFIED],
            [['presonstate' => ''], R::LIFE_UNVERIFIED],
            [['deathdate' => '01/05/2020'], R::LIFE_UNVERIFIED],
            [['deathdate' => '2020-05-01T00:00:00'], R::DECEASED],
            [['deathdate' => '2020-05-01T00:00:00', 'presonstate' => 'حي'], R::DECEASED],
        ];
        foreach ($cases as [$payload, $reason]) {
            $assessment = $this->evaluate($this->child($payload));
            $this->assertTrue($assessment->has($reason), json_encode($payload, JSON_UNESCAPED_UNICODE));
            $this->assertSame(Status::NOT_ELIGIBLE, $assessment->publicStatus());
        }
    }

    public function test_unknown_or_missing_source_values_fail_closed(): void
    {
        foreach (['1', 'M', 'غير معروف', ''] as $gender) {
            $assessment = $this->evaluate($this->child(['gender' => $gender]));
            $this->assertTrue($assessment->has(R::GENDER_UNSUPPORTED), $gender);
            $this->assertNull($assessment->gender, $gender);
        }
        $this->assertTrue($this->evaluate($this->child(['fullname' => '  ']))->has(R::SOURCE_INCOMPLETE));
    }

    public function test_parentage_comes_only_from_the_parent_fields(): void
    {
        $this->assertTrue($this->evaluate($this->child(['fatherid' => '', 'motherid' => '']))->has(R::NOT_A_CHILD_OF_HEAD));
        $this->assertTrue($this->evaluate($this->child(['fatherid' => self::HEAD, 'motherid' => self::HEAD]))->has(R::PARENT_LINK_INDETERMINATE));
        $this->assertSame('MOTHER', $this->evaluate($this->child(['fatherid' => '', 'motherid' => self::HEAD]))->parentLink);
    }

    public function test_invalid_ids_and_the_head_themself_are_refused(): void
    {
        foreach (['12', '40123456a', '000000000', '٤٠١٢٣٤٥٦٧'] as $id) {
            $this->assertTrue($this->evaluate($this->child([], $id))->has(R::INVALID_NATIONAL_ID), $id);
        }
        $this->assertTrue($this->evaluate($this->child([], self::HEAD))->has(R::IS_HEAD));
    }

    public function test_registry_person_states(): void
    {
        $this->person([], $this->context->family);
        $this->assertSame(Status::ALREADY_MEMBER, $this->evaluate($this->child())->publicStatus());
        DB::table('family_memberships')->delete();
        DB::table('persons')->where('national_id', self::CHILD)->delete();

        $this->person([], Family::factory()->create());
        $elsewhere = $this->evaluate($this->child());
        $this->assertTrue($elsewhere->has(R::ACTIVE_MEMBERSHIP_ELSEWHERE));
        $this->assertSame(Status::NOT_ELIGIBLE, $elsewhere->publicStatus());
    }

    public function test_historical_membership_only_is_not_a_refusal_reason(): void
    {
        $person = $this->person();
        FamilyMembership::factory()->create(['family_id' => Family::factory()->create()->id, 'person_id' => $person->id,
            'is_active' => false, 'started_at' => '2001-01-01', 'ended_at' => '2002-01-01']);

        $assessment = $this->evaluate($this->child());
        $this->assertSame([R::GENDER_MAPPING_PROVISIONAL, R::LIFE_UNVERIFIED], $assessment->reasons);
    }

    public function test_unattachable_ambiguous_and_soft_deleted_holders(): void
    {
        foreach (['411234567' => ['life_status' => LifeStatus::DECEASED], '412234567' => ['life_status' => LifeStatus::UNKNOWN], '413234567' => ['is_active' => false]] as $id => $attributes) {
            $this->person($attributes, null, $id);
            $this->assertTrue($this->evaluate($this->child([], $id))->has(R::PERSON_NOT_ATTACHABLE), $id);
        }

        $this->allowLegacyNationalIds();
        Person::factory()->create(['national_id' => '414-234-567']);
        Person::factory()->create(['national_id' => '٤١٤٢٣٤٥٦٧']);
        $this->assertTrue($this->evaluate($this->child([], '414234567'))->has(R::AMBIGUOUS_HOLDERS));

        $this->person([], null, '415234567')->delete();
        $deleted = $this->evaluate($this->child([], '415234567'));
        $this->assertTrue($deleted->has(R::SOFT_DELETED_HOLDER));
        $this->assertSame(Status::NOT_ELIGIBLE, $deleted->publicStatus());
    }

    public function test_an_open_request_in_this_household_is_reported_as_such_and_only_then(): void
    {
        ChangeRequest::factory()->create([
            'family_id' => $this->context->family->id, 'type' => ChangeRequestType::ADD_FAMILY_MEMBER, 'status' => ChangeRequestStatus::SUBMITTED,
            'submitted_data' => ['full_name' => 'اسم-تجريبي', 'national_id' => self::CHILD, 'gender' => 'MALE', 'relationship' => 'SON'],
        ]);
        $this->assertSame(Status::REQUEST_OPEN, $this->evaluate($this->child())->publicStatus());

        // Another refusal the head may not learn wins over the open request.
        $this->person([], Family::factory()->create());
        $this->assertSame(Status::NOT_ELIGIBLE, $this->evaluate($this->child())->publicStatus());
    }

    public function test_every_refusal_is_the_same_public_status_and_reveals_nothing(): void
    {
        $other = Family::factory()->create();
        $this->person(['full_name' => 'اسم-شخص-آخر'], $other);
        $assessment = $this->evaluate($this->child());

        $this->assertSame(Status::NOT_ELIGIBLE, $assessment->publicStatus());
        $public = json_encode(['status' => $assessment->publicStatus()]);
        foreach ([$other->family_code, (string) $other->id, 'ACTIVE_MEMBERSHIP_ELSEWHERE', self::CHILD, 'اسم-شخص-آخر'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $public);
        }
        $this->assertSame(['ELIGIBLE', 'ALREADY_MEMBER', 'REQUEST_OPEN', 'NOT_ELIGIBLE'], array_column(Status::cases(), 'value'));
    }

    public function test_evaluation_writes_nothing_and_never_reads_the_registry(): void
    {
        $this->person([], Family::factory()->create());
        $counts = fn () => [DB::table('persons')->count(), DB::table('family_memberships')->count(), DB::table('family_activities')->count()];
        $before = $counts();

        DB::enableQueryLog();
        $this->evaluate($this->child());
        $queries = collect(DB::getQueryLog())->pluck('query');

        $this->assertSame($before, $counts());
        $this->assertSame([], $queries->filter(fn (string $q) => preg_match('/\b(insert|update|delete)\b/i', $q) === 1)->values()->all());
        $this->assertSame([], $queries->filter(fn (string $q) => str_contains($q, 'identity_ref'))->values()->all());
    }

    public function test_a_context_without_a_household_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        RegistryChildEligibility::evaluate(FamilyAccessResult::denied(FamilyAccessDenial::NO_LINK), $this->child());
    }

    public function test_it_needs_no_registry_connection(): void
    {
        $this->app->instance(CivilRegistrySource::class, new class
        {
            public function __call(string $name, array $arguments): never
            {
                throw new RuntimeException('The registry must not be read.');
            }
        });
        $this->assertSame(Status::NOT_ELIGIBLE, $this->evaluate($this->child())->publicStatus());
    }
}
