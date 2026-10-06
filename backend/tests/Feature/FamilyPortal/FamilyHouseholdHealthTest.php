<?php

namespace Tests\Feature\FamilyPortal;

use App\Enums\UserPersonLinkStatus;
use App\Http\Controllers\Api\V1\Family\FamilyHouseholdController;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Models\AuthSecurityEvent;
use App\Models\DisabilityType;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use App\Models\RelationshipType;
use App\Models\User;
use App\Support\FamilyPortal\HouseholdMemberReference;
use App\Support\StaffRoles;
use Database\Seeders\DisabilityTypeSeeder;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-3B.6: GET /api/v1/family/household/health — the registered health
 * facts of the signed-in head's household, grouped by member_ref. The
 * household comes only from the family.context boundary; nothing in the
 * request chooses a member or Person; the projection is an explicit
 * allow-list (never details, ids, uuids or Staff / audit data). Synthetic
 * data only.
 */
class FamilyHouseholdHealthTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/household/health';

    private const RECORD_KEYS = ['type', 'disability_type', 'condition_name', 'started_at', 'ended_at', 'is_active'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->seed(DisabilityTypeSeeder::class);
        $this->useFamilyAuthKey();
    }

    private function fetch(User $as, string $uri = self::URI): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->getJson($uri);
    }

    private function member(Family $family, string $name, array $person = [], array $membership = []): FamilyMembership
    {
        return FamilyMembership::factory()->create([
            'family_id' => $family->id,
            'person_id' => Person::factory()->create(['full_name' => $name, ...$person])->id,
            'relationship_type_id' => RelationshipType::where('code', 'SON')->value('id'),
            ...$membership,
        ]);
    }

    private function disability(Person $person, string $code, array $attributes = []): PersonHealthRecord
    {
        return PersonHealthRecord::factory()->create([
            'person_id' => $person->id, 'type' => 'DISABILITY', 'condition_name' => null,
            'disability_type_id' => DisabilityType::where('code', $code)->value('id'), ...$attributes,
        ]);
    }

    private function chronic(Person $person, string $name, array $attributes = []): PersonHealthRecord
    {
        return PersonHealthRecord::factory()->create([
            'person_id' => $person->id, 'type' => 'CHRONIC_DISEASE', 'condition_name' => $name, 'disability_type_id' => null, ...$attributes,
        ]);
    }

    private function maternal(Person $person, string $type, array $attributes = []): PersonHealthRecord
    {
        return PersonHealthRecord::factory()->create([
            'person_id' => $person->id, 'type' => $type, 'condition_name' => null, 'disability_type_id' => null, ...$attributes,
        ]);
    }

    private function ref(FamilyMembership $membership): string
    {
        return HouseholdMemberReference::of((int) $membership->family_id, (int) $membership->id);
    }

    /** @return array<string, array<int, array<string, mixed>>> records by member_ref */
    private function byRef(TestResponse $response): array
    {
        return collect($response->assertOk()->json('data.members'))->mapWithKeys(fn ($m) => [$m['member_ref'] => $m['records']])->all();
    }

    // -------------------------------------------------------------- contract

    public function test_the_head_and_household_members_records_are_returned_by_member_ref(): void
    {
        $head = $this->activatedHead();
        $son = $this->member($head['family'], 'ابن تجريبي');
        $this->chronic($head['person'], 'السكري', ['started_at' => '2015-02-01']);
        $this->disability($son->person, 'MOTOR', ['started_at' => '2019-05-01']);

        $response = $this->fetch($head['user'])->assertOk();

        $this->assertSame(['members'], array_keys($response->json('data')));
        $this->assertSame(['member_ref', 'records'], array_keys($response->json('data.members.0')));
        $records = $this->byRef($response);
        $this->assertCount(2, $records);
        $this->assertSame([[
            'type' => 'CHRONIC_DISEASE', 'disability_type' => null, 'condition_name' => 'السكري',
            'started_at' => '2015-02-01', 'ended_at' => null, 'is_active' => true,
        ]], $records[$this->ref($head['membership'])]);
        $this->assertSame([[
            'type' => 'DISABILITY', 'disability_type' => ['code' => 'MOTOR', 'name' => 'حركية'], 'condition_name' => null,
            'started_at' => '2019-05-01', 'ended_at' => null, 'is_active' => true,
        ]], $records[$this->ref($son)]);
    }

    public function test_responses_are_never_cached(): void
    {
        $head = $this->activatedHead();

        $cache = (string) $this->fetch($head['user'])->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
    }

    public function test_a_household_without_records_has_no_members_and_a_member_without_records_is_absent(): void
    {
        $head = $this->activatedHead();
        $son = $this->member($head['family'], 'ابن تجريبي');

        $this->fetch($head['user'])->assertOk()->assertExactJson(['data' => ['members' => []]]);

        $this->chronic($head['person'], 'الربو');
        $records = $this->byRef($this->fetch($head['user']));
        $this->assertArrayHasKey($this->ref($head['membership']), $records);
        $this->assertArrayNotHasKey($this->ref($son), $records);
    }

    public function test_several_disabilities_chronic_diseases_and_maternal_records_are_kept_apart(): void
    {
        $head = $this->activatedHead();
        $wife = $this->member($head['family'], 'زوجة تجريبية', ['gender' => 'FEMALE']);
        $this->disability($wife->person, 'VISUAL');
        $this->disability($wife->person, 'HEARING');
        $this->chronic($wife->person, 'السكري');
        $this->chronic($wife->person, 'ضغط الدم');
        $this->maternal($wife->person, 'PREGNANCY', ['started_at' => '2026-05-01']);
        $this->maternal($wife->person, 'BREASTFEEDING', ['started_at' => '2025-01-01', 'ended_at' => '2025-12-01']);

        $records = $this->byRef($this->fetch($head['user']))[$this->ref($wife)];

        $this->assertCount(6, $records);
        $this->assertEqualsCanonicalizing(['VISUAL', 'HEARING'], array_column(array_column(array_filter($records, fn ($r) => $r['type'] === 'DISABILITY'), 'disability_type'), 'code'));
        $this->assertEqualsCanonicalizing(['السكري', 'ضغط الدم'], array_column(array_filter($records, fn ($r) => $r['type'] === 'CHRONIC_DISEASE'), 'condition_name'));
        foreach ($records as $record) {
            $this->assertSame(self::RECORD_KEYS, array_keys($record));
        }
    }

    public function test_closed_records_stay_as_history_with_their_end_date(): void
    {
        $head = $this->activatedHead();
        $this->chronic($head['person'], 'فقر الدم', ['started_at' => '2018-01-01', 'ended_at' => '2020-06-30']);
        $this->chronic($head['person'], 'السكري', ['started_at' => null]);

        $records = $this->byRef($this->fetch($head['user']))[$this->ref($head['membership'])];

        $this->assertSame(['السكري', 'فقر الدم'], array_column($records, 'condition_name'));
        $this->assertSame([true, false], array_column($records, 'is_active'));
        $this->assertSame([null, '2020-06-30'], array_column($records, 'ended_at'));
        $this->assertSame([null, '2018-01-01'], array_column($records, 'started_at'));
    }

    public function test_the_order_is_deterministic(): void
    {
        $head = $this->activatedHead();
        $p = $head['person'];
        $p->forceFill(['gender' => 'FEMALE'])->save();
        $this->maternal($p, 'BREASTFEEDING', ['started_at' => '2024-01-01']);
        $this->chronic($p, 'مرض قديم', ['started_at' => '2010-01-01', 'ended_at' => '2011-01-01']);
        $this->chronic($p, 'بلا تاريخ', ['started_at' => null]);
        $this->chronic($p, 'أحدث', ['started_at' => '2022-01-01']);
        $this->chronic($p, 'أقدم', ['started_at' => '2012-01-01']);
        $this->disability($p, 'MOTOR', ['started_at' => '2000-01-01']);
        $this->disability($p, 'OTHER', ['started_at' => '1999-01-01', 'ended_at' => '2005-01-01']);

        $records = $this->byRef($this->fetch($head['user']))[$this->ref($head['membership'])];

        $this->assertSame(
            ['DISABILITY:MOTOR', 'CHRONIC_DISEASE:أحدث', 'CHRONIC_DISEASE:أقدم', 'CHRONIC_DISEASE:بلا تاريخ', 'BREASTFEEDING:',
                'DISABILITY:OTHER', 'CHRONIC_DISEASE:مرض قديم'],
            array_map(fn ($r) => $r['type'].':'.($r['disability_type']['code'] ?? $r['condition_name']), $records),
        );
    }

    public function test_a_deactivated_disability_type_is_still_named(): void
    {
        $head = $this->activatedHead();
        $this->disability($head['person'], 'SPEECH_COMMUNICATION');
        DisabilityType::where('code', 'SPEECH_COMMUNICATION')->update(['is_active' => false]);

        $records = $this->byRef($this->fetch($head['user']))[$this->ref($head['membership'])];

        $this->assertSame(['code' => 'SPEECH_COMMUNICATION', 'name' => 'نطق / تواصل'], $records[0]['disability_type']);
    }

    // ---------------------------------------------------------- population

    public function test_a_deceased_active_member_is_included(): void
    {
        $head = $this->activatedHead();
        $late = $this->member($head['family'], 'فرد متوفى', ['life_status' => 'DECEASED']);
        $this->disability($late->person, 'MOTOR');

        $this->assertArrayHasKey($this->ref($late), $this->byRef($this->fetch($head['user'])));
    }

    public function test_a_soft_deleted_person_and_an_ended_membership_are_excluded(): void
    {
        $head = $this->activatedHead();
        $deleted = $this->member($head['family'], 'فرد محذوف');
        $this->chronic($deleted->person, 'مرض محذوف');
        $deleted->person->delete();
        $ended = $this->member($head['family'], 'فرد سابق', membership: ['is_active' => false, 'ended_at' => now()]);
        $this->chronic($ended->person, 'مرض عضو سابق');

        $response = $this->fetch($head['user'])->assertOk()->assertExactJson(['data' => ['members' => []]]);

        $this->assertStringNotContainsString('مرض محذوف', $response->getContent());
        $this->assertStringNotContainsString('مرض عضو سابق', $response->getContent());
    }

    public function test_another_household_is_never_included(): void
    {
        $head = $this->activatedHead('123456789');
        $other = $this->activatedHead('222222222');
        $this->chronic($other['person'], 'مرض أسرة أخرى');
        $otherSon = $this->member($other['family'], 'ابن أسرة أخرى');
        $this->disability($otherSon->person, 'MOTOR');

        $response = $this->fetch($head['user'], self::URI.'?family='.$other['family']->family_code
            .'&member_ref='.$this->ref($otherSon).'&person_id='.$otherSon->person_id)->assertOk();

        $response->assertExactJson(['data' => ['members' => []]]);
    }

    public function test_coordinator_scope_never_widens_it(): void
    {
        $head = $this->activatedHead('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $clan = $this->clan();
        $assigned = $this->familyIn($clan, $this->branch($clan), members: 2);
        $this->assign($head['user'], $clan);
        foreach (FamilyMembership::where('family_id', $assigned->id)->get() as $membership) {
            $this->chronic($membership->person, 'مرض ضمن النطاق '.$membership->id);
        }

        $response = $this->fetch($head['user'])->assertOk()->assertExactJson(['data' => ['members' => []]]);
        $this->assertStringNotContainsString('ضمن النطاق', $response->getContent());
    }

    // ------------------------------------------------------------- privacy

    public function test_no_details_identifier_or_staff_metadata_appears(): void
    {
        $head = $this->activatedHead();
        $staff = User::factory()->create(['name' => 'موظف الصحة']);
        $record = $this->disability($head['person'], 'MOTOR', [
            'details' => 'ملاحظة داخلية سرية', 'created_by' => $staff->id, 'updated_by' => $staff->id,
        ]);

        $raw = $this->fetch($head['user'])->assertOk()->getContent();

        foreach (['ملاحظة داخلية سرية', 'موظف الصحة', $record->uuid, $head['person']->person_code, $head['person']->national_id] as $value) {
            $this->assertStringNotContainsString((string) $value, $raw);
        }
        foreach (['details', '"id"', 'uuid', 'person_id', 'person_code', 'disability_type_id', 'description', 'sort_order',
            'created_by', 'updated_by', 'created_at', 'updated_at', 'summary', 'abilities', 'assessment', 'full_name'] as $key) {
            $this->assertStringNotContainsString($key, $raw, $key);
        }
    }

    public function test_reading_writes_nothing(): void
    {
        $head = $this->activatedHead();
        $this->chronic($head['person'], 'السكري');
        $counts = fn () => [AuthSecurityEvent::count(), FamilyActivity::count(), PersonHealthRecord::count()];
        $before = $counts();

        $this->fetch($head['user'])->assertOk();

        $this->assertSame($before, $counts());
    }

    public function test_the_route_takes_no_parameter(): void
    {
        $route = Route::getRoutes()->getByAction(FamilyHouseholdController::class.'@health');

        $this->assertSame('api/v1/family/household/health', $route->uri());
        $this->assertSame([], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(['api', 'auth:sanctum', 'family.side', 'can:family-portal.access', 'family.context'], $route->gatherMiddleware());
    }

    // --------------------------------------------------------- the boundary

    public function test_a_guest_gets_the_json_401(): void
    {
        $this->getJson(self::URI)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_staff_coordinator_only_and_mixed_accounts_are_refused_by_family_side(): void
    {
        $accounts = ['coordinator only' => $this->familyUser(['COORDINATOR']), 'role-less' => User::factory()->create()];
        foreach (StaffRoles::ALL as $role) {
            $accounts[$role] = tap(User::factory()->create(), fn (User $u) => $u->assignRole($role));
        }
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        $staff->givePermissionTo('family-portal.access');
        $accounts['staff with the permission'] = $staff;
        $mixed = $this->activatedHead('222222222');
        $this->chronic($mixed['person'], 'مرض حساب مختلط');
        $mixed['user']->assignRole('ADMINISTRATOR');
        $accounts['mixed staff and family'] = $mixed['user'];

        foreach ($accounts as $label => $user) {
            $response = $this->fetch($user)->assertForbidden()->assertExactJson(['message' => EnsureFamilySideAccount::MESSAGE]);
            $this->assertStringNotContainsString('مرض', $response->getContent(), $label);
        }
    }

    public function test_a_lost_family_context_gets_the_generic_403(): void
    {
        $head = $this->activatedHead();
        $this->chronic($head['person'], 'السكري');
        $this->fetch($head['user'])->assertOk();

        $head['link']->forceFill(['status' => UserPersonLinkStatus::SUSPENDED->value, 'suspended_at' => now()])->save();

        $response = $this->fetch($head['user'])->assertForbidden()
            ->assertExactJson(['message' => EnsureFamilyContext::MESSAGE, 'code' => EnsureFamilyContext::CODE]);
        $this->assertStringNotContainsString('السكري', $response->getContent());
    }

    public function test_family_portal_access_is_enforced(): void
    {
        $head = $this->activatedHead();
        $this->fetch($head['user'])->assertOk();

        Role::findByName('FAMILY_USER', 'web')->revokePermissionTo('family-portal.access');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->fetch($head['user'])->assertForbidden();
    }
}
