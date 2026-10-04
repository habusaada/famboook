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
 * PWA-3A: GET /api/v1/family/household — the signed-in household head's own
 * household summary. The Family comes only from the family.context boundary
 * (FamilyAccessResolver); the projection is an explicit allow-list.
 * Synthetic data only.
 */
class FamilyHouseholdTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/household';

    private const DENIED = ['message' => EnsureFamilyContext::MESSAGE, 'code' => EnsureFamilyContext::CODE];

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

    /** Active members in addition to the head. */
    private function addMembers(Family $family, int $count, array $person = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            FamilyMembership::factory()->create([
                'family_id' => $family->id,
                'person_id' => Person::factory()->create($person)->id,
            ]);
        }
    }

    private function endedMembership(Family $family): void
    {
        FamilyMembership::factory()->create([
            'family_id' => $family->id, 'person_id' => Person::factory()->create()->id,
            'is_active' => false, 'ended_at' => now()->toDateString(), 'end_reason' => 'synthetic',
        ]);
    }

    // -------------------------------------------------------------- contract

    public function test_an_eligible_head_gets_exactly_the_approved_summary(): void
    {
        $head = $this->activatedHead();
        $clan = $this->clan();
        $branch = $this->branch($clan);
        $head['family']->forceFill(['clan_id' => $clan->id, 'branch_id' => $branch->id])->save();
        $this->addMembers($head['family'], 4);
        FamilyHouseholdDeclaration::factory()->create([
            'family_id' => $head['family']->id, 'declared_household_size' => 7, 'declared_at' => '2026-09-01',
        ]);

        $response = $this->fetch($head['user'])->assertOk();

        $response->assertExactJson(['data' => [
            'family_code' => $head['family']->family_code,
            'clan_name' => $clan->name,
            'branch_name' => $branch->name,
            'head' => ['full_name' => $head['person']->full_name],
            'declared_household_size' => 7,
            'declared_at' => '2026-09-01',
            'registered_member_count' => 5,
        ]]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_family_without_a_branch_or_a_declaration_gets_nulls_not_zeros(): void
    {
        $head = $this->activatedHead();

        $this->fetch($head['user'])->assertOk()
            ->assertJsonPath('data.branch_name', null)
            ->assertJsonPath('data.declared_household_size', null)
            ->assertJsonPath('data.declared_at', null)
            ->assertJsonPath('data.registered_member_count', 1);
    }

    public function test_the_declared_size_comes_only_from_the_current_declaration(): void
    {
        $head = $this->activatedHead();
        $family = $head['family'];
        FamilyHouseholdDeclaration::factory()->create(['family_id' => $family->id, 'declared_household_size' => 11, 'is_current' => false]);
        FamilyHouseholdDeclaration::factory()->create([
            'family_id' => $family->id, 'declared_household_size' => 9, 'declared_living_sons' => 1, 'declared_living_daughters' => 1,
        ]);
        $this->addMembers($family, 1);

        // Never derived from the members (2) or from sons + daughters + head + spouse.
        $this->fetch($head['user'])->assertOk()
            ->assertJsonPath('data.declared_household_size', 9)
            ->assertJsonPath('data.registered_member_count', 2);
    }

    public function test_a_declaration_without_a_size_stays_null_and_a_stored_zero_stays_zero(): void
    {
        $head = $this->activatedHead();
        $declaration = FamilyHouseholdDeclaration::factory()->create([
            'family_id' => $head['family']->id, 'declared_household_size' => null, 'declared_living_sons' => 3,
        ]);
        $this->fetch($head['user'])->assertOk()->assertJsonPath('data.declared_household_size', null);

        $declaration->forceFill(['declared_household_size' => 0])->save();
        $this->assertSame(0, $this->fetch($head['user'])->assertOk()->json('data.declared_household_size'));
    }

    public function test_every_active_membership_counts_whatever_the_life_status(): void
    {
        $head = $this->activatedHead();
        $family = $head['family'];
        $this->addMembers($family, 2);
        $this->addMembers($family, 1, ['life_status' => LifeStatus::DECEASED->value]);
        $this->addMembers($family, 1, ['life_status' => LifeStatus::UNKNOWN->value]);
        $this->endedMembership($family);
        $this->endedMembership($family);

        // head + 2 alive + 1 deceased + 1 unknown; the 2 ended memberships do not count.
        $this->fetch($head['user'])->assertOk()->assertJsonPath('data.registered_member_count', 5);
    }

    // ---------------------------------------------------- context and IDOR

    public function test_spoofed_identifiers_never_switch_the_family(): void
    {
        $mine = $this->activatedHead('111111111');
        $other = $this->activatedHead('222222222');
        $this->addMembers($other['family'], 6);
        FamilyHouseholdDeclaration::factory()->create(['family_id' => $other['family']->id, 'declared_household_size' => 12]);
        $expected = $this->fetch($mine['user'])->assertOk()->json();

        $query = http_build_query([
            'family_id' => $other['family']->id,
            'person_id' => $other['person']->id,
            'membership_id' => $other['membership']->id,
            'family_code' => $other['family']->family_code,
            'family' => $other['family']->family_code,
        ]);
        $spoofed = $this->fetch($mine['user'], self::URI.'?'.$query)->assertOk();

        $this->assertSame($expected, $spoofed->json());
        $this->assertSame($mine['family']->family_code, $spoofed->json('data.family_code'));
        $this->assertSame($mine['person']->full_name, $spoofed->json('data.head.full_name'));
        $this->assertStringNotContainsString($other['family']->family_code, $spoofed->getContent());
        $this->assertStringNotContainsString($other['person']->full_name, $spoofed->getContent());
    }

    public function test_each_head_only_ever_sees_their_own_household(): void
    {
        $a = $this->activatedHead('111111111');
        $b = $this->activatedHead('222222222');
        $this->addMembers($b['family'], 3);

        $this->fetch($a['user'])->assertOk()
            ->assertJsonPath('data.family_code', $a['family']->family_code)
            ->assertJsonPath('data.registered_member_count', 1);
        $this->fetch($b['user'])->assertOk()
            ->assertJsonPath('data.family_code', $b['family']->family_code)
            ->assertJsonPath('data.registered_member_count', 4);
    }

    public function test_no_internal_id_or_sensitive_value_appears_in_the_response(): void
    {
        $head = $this->activatedHead('123456789');
        $family = $head['family'];
        $family->forceFill(['notes' => 'ملاحظة سرية للأسرة', 'paper_form_no' => 'PAPER-7788'])->save();
        $head['person']->forceFill([
            'mobile' => '0597766554', 'alternate_mobile' => '0568877665', 'notes' => 'ملاحظة سرية للشخص', 'birth_date' => '1980-05-17',
        ])->save();
        FamilyHouseholdDeclaration::factory()->create(['family_id' => $family->id, 'notes' => 'ملاحظة إقرار سرية']);
        FamilyResidence::factory()->create([
            'family_id' => $family->id, 'address_text' => 'عنوان سكن سري', 'original_residence_text' => 'سكن أصلي سري',
            'latitude' => '31.5012345', 'longitude' => '34.4612345',
        ]);

        $body = $this->fetch($head['user'])->assertOk()->getContent();

        foreach (['123456789', '*****', '0597766554', '7766554', '0568877665', 'ملاحظة', 'PAPER-7788', 'عنوان سكن سري', 'سكن أصلي سري',
            '31.50', '34.46', '1980-05-17', $head['person']->person_code, 'registration_source', 'notes', '"id"', 'person_id', 'family_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret);
        }
    }

    public function test_a_dual_role_head_gets_the_same_family_summary_and_no_coordinator_data(): void
    {
        $head = $this->activatedHead('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $clan = $this->clan();
        $branch = $this->branch($clan);
        $assigned = $this->familyIn($clan, $branch, members: 4);
        $this->assign($head['user'], $clan);

        $response = $this->fetch($head['user'])->assertOk();

        $response->assertExactJson(['data' => [
            'family_code' => $head['family']->family_code,
            'clan_name' => $head['family']->clan->name,
            'branch_name' => null,
            'head' => ['full_name' => $head['person']->full_name],
            'declared_household_size' => null,
            'declared_at' => null,
            'registered_member_count' => 1,
        ]]);
        $this->assertStringNotContainsString($assigned->family_code, $response->getContent());
        $this->assertStringNotContainsString($branch->name, $response->getContent());
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
        // A Staff account holding the permission directly.
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        $staff->givePermissionTo('family-portal.access');
        $accounts['staff with the permission'] = $staff;
        // An eligible head that also holds a Staff role.
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
        $this->fetch($head['user'])->assertOk();

        Role::findByName('FAMILY_USER', 'web')->revokePermissionTo('family-portal.access');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this->fetch($head['user'])->assertForbidden();
        $this->assertNotSame(EnsureFamilyContext::CODE, $response->json('code'));
        $this->assertStringNotContainsString($head['family']->family_code, $response->getContent());
    }

    /** @return array<string, array{0: string}> */
    public static function denials(): array
    {
        return [
            'link suspended' => ['link-suspended'],
            'link ended' => ['link-ended'],
            'person deceased' => ['deceased'],
            'person life status unknown' => ['unknown'],
            'person inactive' => ['person-inactive'],
            'person soft-deleted' => ['person-deleted'],
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
        $this->assertStringNotContainsString($head['family']->family_code, $response->getContent());
    }

    // ---------------------------------------------------------------- reads

    public function test_the_query_count_does_not_grow_with_the_household(): void
    {
        $small = $this->activatedHead('111111111');
        $this->addMembers($small['family'], 1);
        $large = $this->activatedHead('222222222');
        $this->addMembers($large['family'], 15);
        FamilyHouseholdDeclaration::factory()->create(['family_id' => $large['family']->id]);

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
