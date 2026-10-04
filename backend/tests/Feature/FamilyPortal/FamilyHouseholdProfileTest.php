<?php

namespace Tests\Feature\FamilyPortal;

use App\Enums\AuthIdentityStatus;
use App\Enums\FamilyStatus;
use App\Enums\LifeStatus;
use App\Enums\UserPersonLinkStatus;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Models\Family;
use App\Models\FamilyHouseholdDeclaration;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-3A Step 4: GET /api/v1/family/household/profile — the «أسرتي» family
 * facts and the CURRENT residence of the signed-in head's own household. The
 * Family comes only from the family.context boundary; the projection is an
 * explicit allow-list; NULL stays NULL. Synthetic data only.
 */
class FamilyHouseholdProfileTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/household/profile';

    private const DENIED = ['message' => EnsureFamilyContext::MESSAGE, 'code' => EnsureFamilyContext::CODE];

    private const NO_ADDRESS = ['governorate' => null, 'city' => null, 'area' => null, 'neighborhood' => null];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
    }

    private function fetch(User $as, string $uri = self::URI): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->getJson($uri);
    }

    /** A current residence with nothing but what is given (the factory's defaults cleared). */
    private function residence(Family $family, array $values = []): FamilyResidence
    {
        return FamilyResidence::factory()->create([
            'family_id' => $family->id, 'governorate' => null, 'city' => null, 'address_text' => null, 'started_at' => null, ...$values,
        ]);
    }

    private function addMembers(Family $family, int $count, array $person = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            FamilyMembership::factory()->create(['family_id' => $family->id, 'person_id' => Person::factory()->create($person)->id]);
        }
    }

    // -------------------------------------------------------------- contract

    public function test_complete_family_and_residence_data_is_exactly_the_approved_allow_list(): void
    {
        $head = $this->activatedHead();
        $clan = $this->clan();
        $branch = $this->branch($clan);
        $head['family']->forceFill(['clan_id' => $clan->id, 'branch_id' => $branch->id])->save();
        $this->addMembers($head['family'], 4);
        FamilyHouseholdDeclaration::factory()->create(['family_id' => $head['family']->id, 'declared_household_size' => 7, 'declared_at' => '2026-09-01']);
        $this->residence($head['family'], [
            'original_residence_text' => 'بني سهيلا – خانيونس', 'displacement_status' => 'DISPLACED', 'displacement_location_text' => 'مواصي خانيونس',
            'governorate' => 'خانيونس', 'city' => 'خانيونس', 'area' => 'المواصي', 'neighborhood' => 'حي تجريبي',
        ]);

        $response = $this->fetch($head['user'])->assertOk();

        $response->assertExactJson(['data' => [
            'family' => [
                'family_code' => $head['family']->family_code,
                'clan_name' => $clan->name,
                'branch_name' => $branch->name,
                'head' => ['full_name' => $head['person']->full_name],
                'declared_household_size' => 7,
                'declared_at' => '2026-09-01',
                'registered_member_count' => 5,
            ],
            'residence' => [
                'original_residence_text' => 'بني سهيلا – خانيونس',
                'displacement_status' => 'DISPLACED',
                'displacement_location_text' => 'مواصي خانيونس',
                'current_address' => ['governorate' => 'خانيونس', 'city' => 'خانيونس', 'area' => 'المواصي', 'neighborhood' => 'حي تجريبي'],
            ],
        ]]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_family_without_a_branch_declaration_or_residence_gets_nulls(): void
    {
        $head = $this->activatedHead();

        $this->fetch($head['user'])->assertOk()->assertExactJson(['data' => [
            'family' => [
                'family_code' => $head['family']->family_code,
                'clan_name' => $head['family']->clan->name,
                'branch_name' => null,
                'head' => ['full_name' => $head['person']->full_name],
                'declared_household_size' => null,
                'declared_at' => null,
                'registered_member_count' => 1,
            ],
            'residence' => null,
        ]]);
    }

    public function test_a_declaration_with_a_null_size_or_date_keeps_them_null_and_ignores_older_declarations(): void
    {
        $head = $this->activatedHead();
        FamilyHouseholdDeclaration::factory()->create(['family_id' => $head['family']->id, 'declared_household_size' => 11, 'declared_at' => '2020-01-01', 'is_current' => false]);
        FamilyHouseholdDeclaration::factory()->create([
            'family_id' => $head['family']->id, 'declared_household_size' => null, 'declared_living_sons' => 2, 'declared_at' => null,
        ]);

        $this->fetch($head['user'])->assertOk()
            ->assertJsonPath('data.family.declared_household_size', null)
            ->assertJsonPath('data.family.declared_at', null);
    }

    public function test_the_registered_count_matches_the_dashboard_and_the_members_list(): void
    {
        $head = $this->activatedHead();
        $family = $head['family'];
        $this->addMembers($family, 2);
        $this->addMembers($family, 1, ['life_status' => LifeStatus::DECEASED->value]);
        $this->addMembers($family, 1, ['life_status' => LifeStatus::UNKNOWN->value]);
        $this->addMembers($family, 1, ['is_active' => false]);
        FamilyMembership::factory()->create(['family_id' => $family->id, 'person_id' => Person::factory()->create()->id])->person->delete();
        FamilyMembership::factory()->create([
            'family_id' => $family->id, 'person_id' => Person::factory()->create()->id,
            'is_active' => false, 'ended_at' => now()->toDateString(), 'end_reason' => 'synthetic',
        ]);

        $profile = $this->fetch($head['user'])->assertOk()->json('data.family.registered_member_count');

        $this->assertSame(7, $profile);
        $this->assertSame($profile, $this->fetch($head['user'], '/api/v1/family/household')->json('data.registered_member_count'));
        $this->assertCount($profile, $this->fetch($head['user'], '/api/v1/family/household/members')->json('data.members'));
    }

    // ------------------------------------------------------------- residence

    public function test_an_import_shaped_residence_returns_the_original_residence_and_nothing_else(): void
    {
        $head = $this->activatedHead();
        $this->residence($head['family'], ['original_residence_text' => 'بيت لاهيا', 'source' => 'IMPORT', 'started_at' => '2026-10-01']);

        $this->fetch($head['user'])->assertOk()->assertJsonPath('data.residence', [
            'original_residence_text' => 'بيت لاهيا',
            'displacement_status' => null,
            'displacement_location_text' => null,
            'current_address' => self::NO_ADDRESS,
        ]);
    }

    public function test_a_partial_current_address_stays_partial(): void
    {
        $head = $this->activatedHead();
        $this->residence($head['family'], ['city' => 'غزة', 'neighborhood' => 'الرمال']);

        $this->fetch($head['user'])->assertOk()->assertJsonPath('data.residence', [
            'original_residence_text' => null,
            'displacement_status' => null,
            'displacement_location_text' => null,
            'current_address' => ['governorate' => null, 'city' => 'غزة', 'area' => null, 'neighborhood' => 'الرمال'],
        ]);
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function displacements(): array
    {
        return [
            'displaced with a location' => ['DISPLACED', 'مواصي خانيونس'],
            'displaced without a location' => ['DISPLACED', null],
            'not displaced' => ['NOT_DISPLACED', null],
            'not collected stays null' => [null, null],
        ];
    }

    #[DataProvider('displacements')]
    public function test_the_displacement_status_is_returned_exactly_as_stored(?string $status, ?string $location): void
    {
        $head = $this->activatedHead();
        $this->residence($head['family'], ['displacement_status' => $status, 'displacement_location_text' => $location]);

        $response = $this->fetch($head['user'])->assertOk();

        $this->assertSame($status, $response->json('data.residence.displacement_status'));
        $this->assertSame($location, $response->json('data.residence.displacement_location_text'));
        $this->assertTrue(array_key_exists('displacement_status', $response->json('data.residence')));
    }

    public function test_only_the_current_residence_is_used_and_a_historical_row_is_ignored(): void
    {
        $head = $this->activatedHead();
        // A historical row written by hand: the application itself never creates one.
        DB::table('family_residences')->insert([
            'family_id' => $head['family']->id, 'city' => 'مدينة قديمة', 'original_residence_text' => 'أصل قديم',
            'started_at' => '2020-01-01', 'ended_at' => '2021-01-01', 'is_current' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNull($this->fetch($head['user'])->assertOk()->json('data.residence'));

        $this->residence($head['family'], ['city' => 'مدينة حالية']);
        $response = $this->fetch($head['user'])->assertOk();

        $this->assertSame('مدينة حالية', $response->json('data.residence.current_address.city'));
        $this->assertNull($response->json('data.residence.original_residence_text'));
        $this->assertStringNotContainsString('قديم', $response->getContent());
    }

    // ---------------------------------------------------- context and IDOR

    public function test_spoofed_identifiers_never_switch_the_household(): void
    {
        $mine = $this->activatedHead('111111111');
        $other = $this->activatedHead('222222222');
        $otherResidence = $this->residence($other['family'], ['original_residence_text' => 'سكن أسرة أخرى', 'city' => 'مدينة أسرة أخرى']);
        $otherDeclaration = FamilyHouseholdDeclaration::factory()->create(['family_id' => $other['family']->id, 'declared_household_size' => 12]);
        $expected = $this->fetch($mine['user'])->assertOk()->json();

        $query = http_build_query([
            'family_id' => $other['family']->id, 'residence_id' => $otherResidence->id, 'declaration_id' => $otherDeclaration->id,
            'person_id' => $other['person']->id, 'membership_id' => $other['membership']->id, 'family_code' => $other['family']->family_code,
        ]);
        $spoofed = $this->fetch($mine['user'], self::URI.'?'.$query)->assertOk();

        $this->assertSame($expected, $spoofed->json());
        foreach ([$other['family']->family_code, $other['person']->full_name, 'سكن أسرة أخرى', 'مدينة أسرة أخرى'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $spoofed->getContent(), $foreign);
        }
        $this->assertNull($spoofed->json('data.residence'));
        $this->assertNull($spoofed->json('data.family.declared_household_size'));
    }

    public function test_a_dual_role_head_sees_only_their_own_household(): void
    {
        $head = $this->activatedHead('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $clan = $this->clan();
        $assigned = $this->familyIn($clan, $this->branch($clan), members: 3);
        $this->residence($assigned, ['city' => 'مدينة أسرة ضمن النطاق']);
        $this->assign($head['user'], $clan);

        $response = $this->fetch($head['user'])->assertOk();

        $this->assertSame($head['family']->family_code, $response->json('data.family.family_code'));
        $this->assertNull($response->json('data.residence'));
        $this->assertStringNotContainsString($assigned->family_code, $response->getContent());
        $this->assertStringNotContainsString('مدينة أسرة ضمن النطاق', $response->getContent());
    }

    public function test_no_sensitive_value_or_internal_id_appears(): void
    {
        $head = $this->activatedHead('123456789');
        $family = $head['family'];
        $family->forceFill(['notes' => 'ملاحظة سرية للأسرة', 'paper_form_no' => 'PAPER-7788', 'registration_date' => '2011-11-11'])->save();
        $head['person']->forceFill(['mobile' => '0597766554', 'alternate_mobile' => '0568877665'])->save();
        FamilyHouseholdDeclaration::factory()->create([
            'family_id' => $family->id, 'declared_household_size' => 6, 'declared_living_sons' => 3, 'declared_living_daughters' => 2, 'notes' => 'ملاحظة إقرار سرية',
        ]);
        $this->residence($family, [
            'address_text' => 'عنوان تفصيلي سري', 'residence_type' => 'نوع سكن سري', 'latitude' => '31.5012345', 'longitude' => '34.4612345',
            'started_at' => '2019-09-09', 'source' => 'MANUAL_ENTRY', 'notes' => 'ملاحظة سكن سرية', 'city' => 'غزة',
        ]);

        $body = $this->fetch($head['user'])->assertOk()->getContent();

        foreach (['123456789', '*****', '0597766554', '0568877665', 'ملاحظة', 'PAPER-7788', '2011-11-11', 'عنوان تفصيلي سري', 'نوع سكن سري',
            '31.50', '34.46', '2019-09-09', 'MANUAL_ENTRY', 'IMPORT', $head['person']->person_code,
            '"id"', 'family_id', 'person_id', 'clan_id', 'branch_id', 'address_text', 'residence_type', 'latitude', 'longitude',
            'started_at', 'ended_at', 'is_current', 'source', 'notes', 'paper_form_no', 'registration', '"status"', 'sons', 'daughters',
            'national_id', 'mobile', 'created', 'updated', 'deleted'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret);
        }
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
        $mixed = $this->activatedHead('222222222')['user'];
        $mixed->assignRole('ADMINISTRATOR');
        $accounts['mixed staff and family'] = $mixed;

        foreach ($accounts as $label => $user) {
            $this->fetch($user)->assertForbidden()->assertExactJson(['message' => EnsureFamilySideAccount::MESSAGE]);
        }
    }

    public function test_family_portal_access_is_enforced(): void
    {
        $head = $this->activatedHead();
        $this->residence($head['family'], ['city' => 'مدينة تجريبية']);
        $this->fetch($head['user'])->assertOk();

        Role::findByName('FAMILY_USER', 'web')->revokePermissionTo('family-portal.access');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this->fetch($head['user'])->assertForbidden();
        $this->assertStringNotContainsString('مدينة تجريبية', $response->getContent());
        $this->assertStringNotContainsString($head['family']->family_code, $response->getContent());
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
            'family archived' => ['family-archived'],
            'family soft-deleted' => ['family-deleted'],
        ];
    }

    #[DataProvider('denials')]
    public function test_every_lost_family_context_gets_the_same_generic_403(string $denial): void
    {
        $head = $this->activatedHead('123456789');
        $this->residence($head['family'], ['city' => 'مدينة تجريبية']);
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
            'family-archived' => $head['family']->forceFill(['status' => FamilyStatus::ARCHIVED->value])->save(),
            'family-deleted' => $head['family']->delete(),
        };

        $response = $this->fetch($head['user'])->assertForbidden()->assertExactJson(self::DENIED);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('مدينة تجريبية', $response->getContent());
    }

    // ---------------------------------------------------------------- reads

    public function test_the_query_count_does_not_grow_with_the_household(): void
    {
        $small = $this->activatedHead('111111111');
        $this->addMembers($small['family'], 1);
        $large = $this->activatedHead('222222222');
        $this->addMembers($large['family'], 20);
        foreach ([$small, $large] as $h) {
            FamilyHouseholdDeclaration::factory()->create(['family_id' => $h['family']->id]);
            $this->residence($h['family'], ['city' => 'مدينة']);
        }

        $count = function (User $user): int {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user->fresh());
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::URI)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $count($small['user']); // warm-up: one-off queries (permission cache) are not part of the comparison

        $this->assertSame($count($small['user']), $count($large['user']));
    }
}
