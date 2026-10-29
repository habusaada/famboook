<?php

namespace Tests\Feature\CivilLookup;

use App\Models\CivilLookupPolicyChange;
use App\Models\CivilLookupRolePolicy;
use App\Models\CivilLookupUserAccess;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FB-CIVIL-02D — the Civil Registry Lookup access page API: one account's
 * current access (DEFAULT when it has no override), the Staff account search,
 * the change-history filters and the stale-state check on the per-user
 * change. The 02A / 02B rules themselves are unchanged (covered by
 * CivilLookupApiTest and CivilLookupFamilySideTest). SYNTHETIC data only.
 */
class CivilLookupAccessAdminTest extends TestCase
{
    use FamilyIdentityFixtures;
    use RefreshDatabase;

    private const ADMIN = '/api/v1/civil-registry/lookup/admin';

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);
        $this->super = $this->staff('SUPER_ADMIN', 'مدير تجريبي', 'super@example.test');
    }

    private function staff(string $role, string $name, string $email): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email]);
        $user->assignRole($role);

        return $user;
    }

    /** Opens the family-side switches (before seeding, as on a server) and the family-side role policies. */
    private function openSides(bool $family = true, bool $coordinator = true): void
    {
        config(['civil_lookup.family_side_enabled' => $family, 'civil_lookup.coordinator_side_enabled' => $coordinator]);
        $this->seed(RolePermissionSeeder::class);
        CivilLookupRolePolicy::whereIn('role_name', ['FAMILY_USER', 'COORDINATOR'])->update(['enabled' => true]);
    }

    private function resolve(array $target, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->super)->postJson(self::ADMIN.'/user-access/resolve', $target);
    }

    private function setAccess(array $body): TestResponse
    {
        return $this->actingAs($this->super)->putJson(self::ADMIN.'/user-access', $body);
    }

    private function userChanges(): int
    {
        return CivilLookupPolicyChange::query()->where('subject_type', 'USER')->count();
    }

    public function test_only_civil_lookup_managers_reach_the_new_endpoints(): void
    {
        $administrator = $this->staff('ADMINISTRATOR', 'مسؤول تجريبي', 'admin@example.test');
        $entry = $this->staff('DATA_ENTRY', 'مدخل تجريبي', 'entry@example.test');
        $head = $this->activatedHead('123456781');
        $this->postJson(self::ADMIN.'/user-access/resolve', ['email' => $entry->email])->assertUnauthorized();
        $this->getJson(self::ADMIN.'/accounts/staff?q=تجريبي')->assertUnauthorized();

        foreach ([$administrator, $entry, $head['user']] as $user) {
            $this->resolve(['email' => $entry->email], $user)->assertStatus(403);
            $this->actingAs($user)->getJson(self::ADMIN.'/accounts/staff?q=تجريبي')->assertStatus(403);
            $this->actingAs($user)->getJson(self::ADMIN.'/changes?subject=USER')->assertStatus(403);
        }
    }

    public function test_a_staff_account_without_an_override_resolves_to_default_with_its_role_policy(): void
    {
        $entry = $this->staff('DATA_ENTRY', 'مدخل تجريبي', 'entry@example.test');

        $response = $this->resolve(['email' => 'entry@example.test'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.user.id', $entry->id)->assertJsonPath('data.user.account_side', 'STAFF')
            ->assertJsonPath('data.user.roles', ['DATA_ENTRY'])->assertJsonPath('data.person', null)
            ->assertJsonPath('data.access', 'DEFAULT')->assertJsonPath('data.sides', [])
            ->assertJsonPath('data.changed_by', null)->assertJsonPath('data.last_change', null)
            ->assertJsonPath('data.grantable_sides', [])
            ->assertJsonPath('data.role_policy', ['role' => 'DATA_ENTRY', 'daily_limit' => 50])
            // The gate is closed by default: the override is not the effective eligibility.
            ->assertJsonPath('data.effective.STAFF', ['allowed' => false, 'reason' => 'LOOKUP_DISABLED']);
        $this->assertArrayNotHasKey('password', $response->json('data.user'));

        $this->resolve(['email' => 'nobody@example.test'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->resolve([])->assertStatus(422);
        $this->resolve(['email' => 'entry@example.test', 'person_code' => 'PER-1'])->assertStatus(422);
    }

    public function test_a_family_account_resolves_by_person_code_with_its_override_sides_and_grantable_sides(): void
    {
        $this->openSides(family: true, coordinator: true);
        $head = $this->activatedHead('123456782');
        $coordinator = $this->activatedHead('123456783', ['FAMILY_USER', 'COORDINATOR']);
        $this->setAccess(['person_code' => $head['person']->person_code, 'access' => 'GRANTED', 'sides' => ['FAMILY']])->assertOk();

        $this->resolve(['person_code' => $head['person']->person_code])->assertOk()
            ->assertJsonPath('data.user.id', $head['user']->id)->assertJsonPath('data.user.account_side', 'FAMILY')
            ->assertJsonPath('data.person.person_code', $head['person']->person_code)
            ->assertJsonPath('data.person.link_status', 'ACTIVE')
            ->assertJsonPath('data.access', 'GRANTED')->assertJsonPath('data.sides', ['FAMILY'])
            ->assertJsonPath('data.changed_by', 'مدير تجريبي')->assertJsonPath('data.last_change.changed_by', 'مدير تجريبي')
            // COORDINATOR is offered only to a holder of that role.
            ->assertJsonPath('data.grantable_sides', ['FAMILY'])
            ->assertJsonPath('data.effective.FAMILY.allowed', false);
        $this->resolve(['person_code' => $coordinator['person']->person_code])->assertOk()
            ->assertJsonPath('data.access', 'DEFAULT')->assertJsonPath('data.grantable_sides', ['FAMILY', 'COORDINATOR']);

        // A Person without a linked account, an unknown code, a closed side.
        $unlinked = Person::factory()->create();
        $this->resolve(['person_code' => $unlinked->person_code])->assertStatus(422)->assertJsonValidationErrors('person_code');
        $this->resolve(['person_code' => 'NO-SUCH-PERSON'])->assertStatus(422)->assertJsonValidationErrors('person_code');
        config(['civil_lookup.coordinator_side_enabled' => false]);
        $this->resolve(['person_code' => $coordinator['person']->person_code])->assertJsonPath('data.grantable_sides', ['FAMILY']);
    }

    public function test_the_staff_search_finds_staff_accounts_by_name_or_email_only(): void
    {
        $entry = $this->staff('DATA_ENTRY', 'سامر تجريبي', 'samer@example.test');
        $this->staff('REVIEWER', 'مراجع آخر', 'reviewer@example.test');
        $head = $this->activatedHead('123456784');
        $head['user']->forceFill(['name' => 'سامر رب أسرة'])->save();

        $byName = $this->actingAs($this->super)->getJson(self::ADMIN.'/accounts/staff?q='.urlencode('سامر'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('meta.total', 1);
        $this->assertSame([['id' => $entry->id, 'name' => 'سامر تجريبي', 'email' => 'samer@example.test', 'is_active' => true,
            'account_side' => 'STAFF', 'roles' => ['DATA_ENTRY']]], $byName->json('data'));
        $this->actingAs($this->super)->getJson(self::ADMIN.'/accounts/staff?q=REVIEWER@EXAMPLE')->assertOk()
            ->assertJsonPath('data.0.email', 'reviewer@example.test')->assertJsonCount(1, 'data');
        // LIKE wildcards are literal; the term needs two characters.
        $this->actingAs($this->super)->getJson(self::ADMIN.'/accounts/staff?q=%25%25')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->super)->getJson(self::ADMIN.'/accounts/staff?q=a')->assertStatus(422);
    }

    public function test_adding_and_removing_a_side_keeps_the_other_and_a_stale_state_is_refused(): void
    {
        $this->openSides(family: true, coordinator: true);
        $coordinator = $this->activatedHead('123456785', ['FAMILY_USER', 'COORDINATOR']);
        $code = $coordinator['person']->person_code;

        $this->setAccess(['person_code' => $code, 'access' => 'GRANTED', 'sides' => ['FAMILY'], 'expected_access' => 'DEFAULT', 'expected_sides' => []])
            ->assertOk()->assertJsonPath('data.sides', ['FAMILY']);
        // Adding COORDINATOR: the editor sends the whole set it shows.
        $this->setAccess(['person_code' => $code, 'access' => 'GRANTED', 'sides' => ['FAMILY', 'COORDINATOR'],
            'expected_access' => 'GRANTED', 'expected_sides' => ['FAMILY']])->assertOk()->assertJsonPath('data.sides', ['FAMILY', 'COORDINATOR']);
        // Removing COORDINATOR keeps FAMILY.
        $this->setAccess(['person_code' => $code, 'access' => 'GRANTED', 'sides' => ['FAMILY'],
            'expected_access' => 'GRANTED', 'expected_sides' => ['COORDINATOR', 'FAMILY']])->assertOk()->assertJsonPath('data.sides', ['FAMILY']);
        $this->assertSame('FAMILY', CivilLookupUserAccess::find($coordinator['user']->id)->sides);

        // Another administrator changed it meanwhile: refused, nothing written.
        $before = $this->userChanges();
        $this->setAccess(['person_code' => $code, 'access' => 'GRANTED', 'sides' => ['FAMILY', 'COORDINATOR'],
            'expected_access' => 'GRANTED', 'expected_sides' => ['FAMILY', 'COORDINATOR']])
            ->assertStatus(409)->assertJsonPath('code', 'ACCESS_CHANGED')->assertHeader('Cache-Control', 'no-store, private');
        $this->setAccess(['person_code' => $code, 'access' => 'REVOKED', 'expected_access' => 'DEFAULT', 'expected_sides' => []])->assertStatus(409);
        $this->assertSame('FAMILY', CivilLookupUserAccess::find($coordinator['user']->id)->sides);
        $this->assertSame($before, $this->userChanges());
        // expected_sides is required with expected_access.
        $this->setAccess(['person_code' => $code, 'access' => 'REVOKED', 'expected_access' => 'GRANTED'])->assertStatus(422)->assertJsonValidationErrors('expected_sides');

        // REVOKED, then DEFAULT, each from the loaded state.
        $this->setAccess(['person_code' => $code, 'access' => 'REVOKED', 'expected_access' => 'GRANTED', 'expected_sides' => ['FAMILY']])
            ->assertOk()->assertJsonPath('data.access', 'REVOKED');
        $this->setAccess(['person_code' => $code, 'access' => 'DEFAULT', 'expected_access' => 'REVOKED', 'expected_sides' => []])
            ->assertOk()->assertJsonPath('data.access', 'DEFAULT');
        $this->assertNull(CivilLookupUserAccess::find($coordinator['user']->id));
        $this->resolve(['person_code' => $code])->assertJsonPath('data.access', 'DEFAULT')->assertJsonPath('data.changed_by', null)
            ->assertJsonPath('data.last_change.changed_by', 'مدير تجريبي');
    }

    public function test_the_02b_grant_rules_still_apply_through_the_editor_contract(): void
    {
        $this->openSides(family: true, coordinator: true);
        $entry = $this->staff('DATA_ENTRY', 'مدخل تجريبي', 'entry@example.test');
        $head = $this->activatedHead('123456786');

        // Never GRANTED for a Staff account; COORDINATOR only for a holder of the role.
        $this->setAccess(['email' => $entry->email, 'access' => 'GRANTED', 'sides' => ['FAMILY'], 'expected_access' => 'DEFAULT', 'expected_sides' => []])
            ->assertStatus(422)->assertJsonValidationErrors('access');
        $this->setAccess(['person_code' => $head['person']->person_code, 'access' => 'GRANTED', 'sides' => ['COORDINATOR'],
            'expected_access' => 'DEFAULT', 'expected_sides' => []])->assertStatus(422)->assertJsonValidationErrors('sides');
        // A Staff account may be revoked and restored.
        $this->setAccess(['email' => $entry->email, 'access' => 'REVOKED', 'expected_access' => 'DEFAULT', 'expected_sides' => []])->assertOk();
        $this->resolve(['email' => $entry->email])->assertJsonPath('data.access', 'REVOKED')->assertJsonPath('data.effective.STAFF.reason', 'LOOKUP_DISABLED');
        $this->setAccess(['email' => $entry->email, 'access' => 'DEFAULT', 'expected_access' => 'REVOKED', 'expected_sides' => []])->assertOk();
        $this->assertSame(2, $this->userChanges());
    }

    public function test_the_override_list_names_the_account_side_and_the_history_filters_by_subject_and_user(): void
    {
        $this->openSides(family: true, coordinator: false);
        $entry = $this->staff('DATA_ENTRY', 'مدخل تجريبي', 'entry@example.test');
        $head = $this->activatedHead('123456787');
        $this->setAccess(['email' => $entry->email, 'access' => 'REVOKED'])->assertOk();
        $this->setAccess(['person_code' => $head['person']->person_code, 'access' => 'GRANTED', 'sides' => ['FAMILY']])->assertOk();
        $this->actingAs($this->super)->putJson(self::ADMIN.'/policies/DATA_ENTRY', ['enabled' => true, 'daily_limit' => 40])->assertOk();

        $list = $this->actingAs($this->super)->getJson(self::ADMIN.'/user-access')->assertOk()
            ->assertJsonPath('meta', ['limit' => 200, 'total' => 2])->json('data');
        $bySide = collect($list)->keyBy('account_side');
        $this->assertSame([null, 'REVOKED'], [$bySide['STAFF']['person_code'], $bySide['STAFF']['access']]);
        $this->assertSame([$head['person']->person_code, ['FAMILY']], [$bySide['FAMILY']['person_code'], $bySide['FAMILY']['sides']]);

        $this->actingAs($this->super)->getJson(self::ADMIN.'/changes')->assertOk()->assertJsonPath('meta.total', 3);
        $this->actingAs($this->super)->getJson(self::ADMIN.'/changes?subject=ROLE')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.role', 'DATA_ENTRY');
        $this->actingAs($this->super)->getJson(self::ADMIN.'/changes?subject=USER')->assertOk()->assertJsonPath('meta.total', 2);
        $this->actingAs($this->super)->getJson(self::ADMIN.'/changes?subject=USER&user_id='.$head['user']->id)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.new_access', 'GRANTED')->assertJsonPath('data.0.new_sides', ['FAMILY']);
        $this->actingAs($this->super)->getJson(self::ADMIN.'/changes?subject=OTHER')->assertStatus(422);
        $this->actingAs($this->super)->getJson(self::ADMIN.'/changes?user_id=x')->assertStatus(422);
    }
}
