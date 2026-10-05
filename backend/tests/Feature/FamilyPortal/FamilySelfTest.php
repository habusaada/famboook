<?php

namespace Tests\Feature\FamilyPortal;

use App\Actions\RecordPersonDeathAction;
use App\Enums\AuthIdentityStatus;
use App\Enums\FamilyStatus;
use App\Enums\LifeStatus;
use App\Enums\LifeStatusVerificationMethod;
use App\Enums\UserPersonLinkStatus;
use App\Http\Controllers\Api\V1\Family\FamilySelfController;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-3B.1: GET /api/v1/family/self — «بياناتي الشخصية», the signed-in
 * household head's OWN Person and membership data. The Person comes only
 * from the family.context boundary; the National ID and mobiles are masked
 * (no full value in the payload); the projection is an explicit allow-list;
 * NULL stays NULL. Synthetic data only.
 */
class FamilySelfTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/self';

    private const DENIED = ['message' => EnsureFamilyContext::MESSAGE, 'code' => EnsureFamilyContext::CODE];

    private const KEYS = [
        'full_name', 'national_id_masked', 'gender', 'birth_date', 'marital_status', 'mobile_masked',
        'alternate_mobile_masked', 'alternate_mobile_owner_relation', 'relationship', 'is_household_head',
        'membership_started_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->useFamilyAuthKey();
    }

    private function fetch(User $as, string $uri = self::URI): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->getJson($uri);
    }

    /** An activated head with a complete, synthetic personal record. */
    private function completeHead(string $nationalId = '123456789'): array
    {
        $head = $this->activatedHead($nationalId);
        $head['person']->forceFill([
            'full_name' => 'سالم أحمد الاختبار', 'gender' => 'MALE', 'birth_date' => '1980-01-15', 'marital_status' => 'MARRIED',
            'mobile' => '0591234567', 'alternate_mobile' => '0567654321', 'alternate_mobile_owner_relation' => 'أخ',
        ])->save();
        $head['membership']->forceFill([
            'relationship_type_id' => RelationshipType::where('code', 'HEAD')->value('id'), 'started_at' => '2001-03-04',
        ])->save();

        return $head;
    }

    // -------------------------------------------------------------- contract

    public function test_the_head_gets_exactly_their_own_masked_record(): void
    {
        $head = $this->completeHead();

        $response = $this->fetch($head['user'])->assertOk();

        $this->assertSame(self::KEYS, array_keys($response->json('data')));
        $response->assertExactJson(['data' => [
            'full_name' => 'سالم أحمد الاختبار',
            'national_id_masked' => '*****6789',
            'gender' => 'MALE',
            'birth_date' => '1980-01-15',
            'marital_status' => 'MARRIED',
            'mobile_masked' => '05*****567',
            'alternate_mobile_masked' => '05*****321',
            'alternate_mobile_owner_relation' => 'أخ',
            'relationship' => ['code' => 'HEAD', 'name' => RelationshipType::where('code', 'HEAD')->value('name')],
            'is_household_head' => true,
            'membership_started_at' => '2001-03-04',
        ]]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_no_full_sensitive_value_or_internal_field_appears(): void
    {
        $head = $this->completeHead();
        $head['person']->forceFill(['notes' => 'ملاحظة سرية عن الشخص'])->save();
        $head['membership']->forceFill(['notes' => 'ملاحظة عضوية سرية', 'paper_sequence_no' => 7319])->save();

        $body = $this->fetch($head['user'])->assertOk()->getContent();

        foreach (['123456789', '0591234567', '0567654321', '91234', '67654', 'ملاحظة', '7319', $head['person']->person_code,
            '"id"', 'person_id', 'family_id', 'user_id', 'person_code', 'national_id"', '"mobile"', '"alternate_mobile"',
            'notes', 'is_active', 'life_status', 'death_date', 'created', 'updated', 'deleted', 'paper_sequence',
            'login_key', 'fingerprint', 'trust', 'family_code', $head['family']->family_code] as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret);
        }
    }

    public function test_absent_values_stay_null_and_unknown_stays_unknown(): void
    {
        $head = $this->activatedHead('123456789');
        $head['person']->forceFill([
            'gender' => null, 'birth_date' => null, 'marital_status' => 'UNKNOWN',
            'mobile' => null, 'alternate_mobile' => null, 'alternate_mobile_owner_relation' => null,
        ])->save();
        $head['membership']->forceFill(['relationship_type_id' => null, 'started_at' => null])->save();

        $data = $this->fetch($head['user'])->assertOk()->json('data');

        // The National ID exists (the login identifier), so it is masked, never null here.
        $this->assertSame('*****6789', $data['national_id_masked']);
        foreach (['gender', 'birth_date', 'mobile_masked', 'alternate_mobile_masked', 'alternate_mobile_owner_relation', 'relationship', 'membership_started_at'] as $key) {
            $this->assertNull($data[$key], $key);
        }
        $this->assertSame('UNKNOWN', $data['marital_status']);
        $this->assertTrue($data['is_household_head']);
    }

    public function test_blank_mobiles_are_null_and_a_non_standard_stored_mobile_gets_no_invented_prefix(): void
    {
        $head = $this->activatedHead('123456789');
        $head['person']->forceFill(['mobile' => '  ', 'alternate_mobile' => '+970 59 123 4567'])->save();

        $data = $this->fetch($head['user'])->assertOk()->json('data');

        $this->assertNull($data['mobile_masked']);
        $this->assertSame('*****567', $data['alternate_mobile_masked']);
        $this->assertStringNotContainsString('1234567', json_encode($data));
    }

    // ---------------------------------------------------- SELF and IDOR

    public function test_the_returned_person_is_the_family_context_person_whatever_the_request_says(): void
    {
        $mine = $this->completeHead('111111111');
        $other = $this->completeHead('222222222');
        $other['person']->forceFill(['full_name' => 'رب أسرة أخرى'])->save();
        $expected = $this->fetch($mine['user'])->assertOk()->json();

        $query = http_build_query([
            'person_id' => $other['person']->id, 'person_code' => $other['person']->person_code, 'family_id' => $other['family']->id,
            'family_code' => $other['family']->family_code, 'membership_id' => $other['membership']->id, 'national_id' => '222222222',
            'user_id' => $other['user']->id,
        ]);
        $spoofed = $this->fetch($mine['user'], self::URI.'?'.$query)->assertOk();

        $this->assertSame($expected, $spoofed->json());
        $this->assertSame('*****1111', $spoofed->json('data.national_id_masked'));
        $this->assertStringNotContainsString('رب أسرة أخرى', $spoofed->getContent());
    }

    public function test_the_route_takes_no_target_parameter(): void
    {
        $route = Route::getRoutes()->getByAction(FamilySelfController::class.'@show');

        $this->assertSame('api/v1/family/self', $route->uri());
        $this->assertSame([], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        foreach (['auth:sanctum', 'family.side', 'can:family-portal.access', 'family.context'] as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware(), $middleware);
        }
    }

    public function test_coordinator_scope_never_changes_the_person_returned(): void
    {
        $head = $this->activatedHead('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $clan = $this->clan();
        $assigned = $this->familyIn($clan, $this->branch($clan), members: 3);
        $this->assign($head['user'], $clan);
        $assignedHead = FamilyMembership::where('family_id', $assigned->id)->where('is_household_head', true)->sole()->person;

        $response = $this->fetch($head['user'])->assertOk();

        $this->assertSame($head['person']->full_name, $response->json('data.full_name'));
        $this->assertStringNotContainsString($assignedHead->full_name, $response->getContent());
        $this->assertStringNotContainsString($assigned->family_code, $response->getContent());
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
        $mixed = $this->completeHead('222222222')['user'];
        $mixed->assignRole('ADMINISTRATOR');
        $accounts['mixed staff and family'] = $mixed;

        foreach ($accounts as $label => $user) {
            $response = $this->fetch($user)->assertForbidden()->assertExactJson(['message' => EnsureFamilySideAccount::MESSAGE]);
            $this->assertStringNotContainsString('*****', $response->getContent(), $label);
        }
    }

    public function test_family_portal_access_is_enforced(): void
    {
        $head = $this->completeHead();
        $this->fetch($head['user'])->assertOk();

        Role::findByName('FAMILY_USER', 'web')->revokePermissionTo('family-portal.access');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this->fetch($head['user'])->assertForbidden();
        $this->assertStringNotContainsString('سالم أحمد الاختبار', $response->getContent());
    }

    /** @return array<string, array{0: string}> */
    public static function denials(): array
    {
        return [
            'link suspended' => ['link-suspended'],
            'link ended' => ['link-ended'],
            'head deceased' => ['deceased'],
            'head life status unknown' => ['unknown'],
            'head inactive' => ['person-inactive'],
            'head soft-deleted' => ['person-deleted'],
            'auth identity suspended' => ['identity-suspended'],
            'national id changed behind the identity' => ['identity-mismatch'],
            'not the household head' => ['not-head'],
            'membership ended' => ['membership-ended'],
            'family inactive' => ['family-inactive'],
            'family soft-deleted' => ['family-deleted'],
        ];
    }

    #[DataProvider('denials')]
    public function test_every_lost_family_context_gets_the_same_generic_403(string $denial): void
    {
        $head = $this->completeHead();
        $this->fetch($head['user'])->assertOk();

        match ($denial) {
            'link-suspended' => $head['link']->forceFill(['status' => UserPersonLinkStatus::SUSPENDED->value, 'suspended_at' => now()])->save(),
            'link-ended' => $head['link']->forceFill(['status' => UserPersonLinkStatus::ENDED->value, 'ended_at' => now(), 'end_reason' => 'ADMINISTRATIVE'])->save(),
            'deceased' => $head['person']->forceFill(['life_status' => LifeStatus::DECEASED->value])->save(),
            'unknown' => $head['person']->forceFill(['life_status' => LifeStatus::UNKNOWN->value])->save(),
            'person-inactive' => $head['person']->forceFill(['is_active' => false])->save(),
            'person-deleted' => $head['person']->delete(),
            'identity-suspended' => $head['identity']->forceFill(['status' => AuthIdentityStatus::SUSPENDED->value])->save(),
            'identity-mismatch' => DB::table('persons')->where('id', $head['person']->id)->update(['national_id' => '987654321']),
            'not-head' => $head['membership']->forceFill(['is_household_head' => false])->save(),
            'membership-ended' => $head['membership']->forceFill(['is_active' => false, 'is_household_head' => false, 'ended_at' => now()])->save(),
            'family-inactive' => $head['family']->forceFill(['status' => FamilyStatus::INACTIVE->value])->save(),
            'family-deleted' => $head['family']->delete(),
        };

        $response = $this->fetch($head['user'])->assertForbidden()->assertExactJson(self::DENIED);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('سالم أحمد الاختبار', $response->getContent());
        $this->assertStringNotContainsString('*****', $response->getContent());
    }

    public function test_a_recorded_death_of_the_head_closes_the_view(): void
    {
        $head = $this->completeHead();
        $this->fetch($head['user'])->assertOk();

        app(RecordPersonDeathAction::class)->handle($head['person'], null, LifeStatusVerificationMethod::IN_PERSON, null);

        // The link is ended: the session no longer has a Family context.
        $this->fetch($head['user'])->assertForbidden();
        $this->assertSame(0, Person::whereKey($head['person']->id)->where('life_status', 'ALIVE')->count());
    }
}
