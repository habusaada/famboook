<?php

namespace Tests\Feature\CivilLookup;

use App\Models\CivilLookupAuditEvent;
use App\Models\CivilLookupPolicyChange;
use App\Models\CivilLookupRolePolicy;
use App\Models\CivilLookupUserAccess;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Support\CivilLookup\CivilLookupGate;
use App\Support\CivilLookup\CivilRegistrySource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\CivilLookup\FakeCivilRegistry;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FB-CIVIL-02B — the Civil Registry Lookup on the Family Portal and in
 * Coordinator Space (docs/adr/FB-CIVIL-02B-FAMILY-COORDINATOR-LOOKUP.md):
 * side gates, the household-head and assignment boundaries, the explicit
 * per-side grant, the EXTENDED_CONTROLLED profile, the shared quota, the
 * context-bound references and audit, and the Staff side unchanged. Against
 * the in-memory registry (FakeCivilRegistry). SYNTHETIC data only
 * (900000xxx identifiers, placeholder names).
 */
class CivilLookupFamilySideTest extends TestCase
{
    use CoordinatorFixtures;
    use FamilyIdentityFixtures;
    use RefreshDatabase;

    private const FAMILY = '/api/v1/family/civil-registry/lookup';

    private const COORDINATOR = '/api/v1/family/coordinator/civil-registry/lookup';

    private const STAFF = '/api/v1/civil-registry/lookup';

    private FakeCivilRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        config(['civil_lookup.enabled' => true]);
        $this->registry = (new FakeCivilRegistry)
            ->add('900000001', ['fullname' => 'سامي خالد عبد الله تجريبي', 'name1' => 'سامي', 'father' => 'خالد', 'grandfath' => 'عبد الله',
                'family' => 'تجريبي', 'maritalst' => '2', 'govern' => '10', 'city' => '101', 'birthplace' => '101', 'age' => '36',
                'fatherid' => '900000002', 'motherid' => '900000099', 'partnerid' => '', 'householdid' => 'H-1', 'householdrel' => '1',
                'phonenum' => '0599000000', 'relgion' => 'X', 'worker' => '1', 'deathdate' => '', 'street' => 'شارع تجريبي'])
            ->add('900000002', ['fullname' => 'خالد عبد الله تجريبي', 'name1' => 'خالد', 'father' => 'عبد الله', 'family' => 'تجريبي', 'householdid' => 'H-1']);
        $this->app->instance(CivilRegistrySource::class, $this->registry);
    }

    protected function tearDown(): void
    {
        foreach ([CivilLookupGate::VARIABLE, ...array_values(CivilLookupGate::SIDE_VARIABLES), CivilLookupGate::PROFILE_VARIABLE] as $variable) {
            putenv($variable);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Opens the family-side switches (before seeding, as on a server) and the family-side role policies. */
    private function openSides(bool $family = true, bool $coordinator = true): void
    {
        config(['civil_lookup.family_side_enabled' => $family, 'civil_lookup.coordinator_side_enabled' => $coordinator]);
        $this->seed(RolePermissionSeeder::class);
        CivilLookupRolePolicy::whereIn('role_name', ['FAMILY_USER', 'COORDINATOR'])->update(['enabled' => true]);
    }

    private function householdHead(string $nationalId = '123456780'): User
    {
        return $this->activatedHead($nationalId)['user'];
    }

    /** An activated household head holding COORDINATOR with an effective assignment. */
    private function activeCoordinator(string $nationalId = '111111110'): User
    {
        $user = $this->coordinator($nationalId);
        $this->assign($user, $this->clan());

        return $user;
    }

    private function grant(User $user, array $sides): void
    {
        CivilLookupUserAccess::create(['user_id' => $user->id, 'access' => 'GRANTED', 'sides' => CivilLookupUserAccess::sideValue($sides),
            'changed_by' => $user->id, 'changed_at' => now()]);
    }

    private function lookup(User $user, string $base, string $path, array $body = []): TestResponse
    {
        return $this->actingAs($user)->postJson($base.$path, $body);
    }

    private function used(User $user): int
    {
        return (int) DB::table('civil_lookup_daily_usage')->where('user_id', $user->id)->sum('used');
    }

    public function test_both_family_side_contexts_are_closed_by_default_even_for_a_granted_household_head_and_coordinator(): void
    {
        $this->seed(RolePermissionSeeder::class);
        // Even an enabled policy and a GRANTED row written directly change nothing while the sides are closed.
        CivilLookupRolePolicy::whereIn('role_name', ['FAMILY_USER', 'COORDINATOR'])->update(['enabled' => true]);
        $user = $this->activeCoordinator();
        $this->grant($user, ['FAMILY', 'COORDINATOR']);

        $this->assertFalse($user->can('civil-lookup.search'), 'no family-side role holds the permission by default');
        $this->lookup($user, self::FAMILY, '/search/national-id', ['national_id' => '900000001'])->assertStatus(503)->assertJsonPath('code', 'LOOKUP_DISABLED');
        $this->lookup($user, self::COORDINATOR, '/search/national-id', ['national_id' => '900000001'])->assertStatus(503)->assertJsonPath('code', 'LOOKUP_DISABLED');
        $this->actingAs($user)->getJson('/api/v1/family/me')->assertOk()
            ->assertJsonPath('user.civil_lookup.family', false)->assertJsonPath('user.civil_lookup.coordinator', false);
        $this->assertSame(0, $this->registry->reads);
        $this->assertSame(0, $this->used($user));

        // The global switch alone is not enough either: each side has its own.
        config(['civil_lookup.family_side_enabled' => true]);
        $this->lookup($user, self::COORDINATOR, '/search/national-id', ['national_id' => '900000001'])->assertStatus(503);
    }

    public function test_an_authorized_household_head_looks_up_with_the_extended_controlled_profile(): void
    {
        $this->openSides();
        $head = $this->householdHead();
        $this->grant($head, ['FAMILY']);

        $this->actingAs($head)->getJson('/api/v1/family/me')->assertOk()
            ->assertJsonPath('user.civil_lookup.family', true)->assertJsonPath('user.civil_lookup.coordinator', false);
        $this->actingAs($head)->getJson(self::FAMILY.'/status')->assertOk()
            ->assertJsonPath('data.context', 'FAMILY')->assertJsonPath('data.quota.enabled', true)->assertJsonPath('data.quota.daily_limit', 5)
            ->assertJsonPath('data.capabilities.view_full', false);

        $list = $this->lookup($head, self::FAMILY, '/search/national-id', ['national_id' => '900000001'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.results.0.national_id', '*****0001');
        $record = $this->lookup($head, self::FAMILY, '/records/open', ['ref' => $list->json('data.results.0.ref')])->assertOk()
            ->assertJsonPath('data.record.level', 'EXTENDED_CONTROLLED')->assertJsonPath('data.record.national_id', '*****0001')->json('data.record');

        $fields = collect($record['sections'])->flatMap(fn ($s) => collect($s['fields'])->pluck('value', 'key'))->all();
        $this->assertSame(['mainid', 'fullname', 'name1', 'father', 'grandfath', 'family', 'gender', 'datebirth', 'age', 'birthplace', 'maritalst',
            'govern', 'city'], array_keys($fields), 'exactly the EXTENDED_CONTROLLED allowlist, in section order');
        $this->assertSame('*****0001', $fields['mainid']);
        $body = json_encode($record, JSON_UNESCAPED_UNICODE);
        foreach (['relgion', 'phonenum', 'worker', 'householdrel', 'householdid', 'street', 'deathdate', '0599000000', 'شارع تجريبي', '900000001', '900000002',
            'source_payload', 'record_id'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $body);
        }
        $links = collect($record['links'])->keyBy('relation');
        $this->assertSame(['AVAILABLE', '*****0002'], [$links['FATHER']['status'], $links['FATHER']['national_id']]);
        $this->assertSame('NOT_IN_RELEASE', $links['MOTHER']['status']);
        $this->assertSame('EMPTY', $links['SPOUSE']['status']);
        $this->assertSame(['DISABLED', 'DISABLED'], [$links['HOUSEHOLD']['status'], $links['CHILDREN']['status']]);
        $this->assertSame(1, $this->used($head), 'the search counted; opening the listed record did not');
        // FB-CIVIL-02F: EXTENDED_CONTROLLED stays masked pending B-OD-1, so nothing is disclosed or charged for it.
        $this->assertSame(0, DB::table('civil_lookup_record_disclosures')->count());

        $event = CivilLookupAuditEvent::query()->where('event_type', 'SEARCH_BY_ID')->sole();
        $this->assertSame(['FAMILY', 'FAMILY'], [$event->actor_side, $event->lookup_context]);
        $this->assertSame('FAMILY', CivilLookupAuditEvent::query()->where('event_type', 'RECORD_OPENED')->sole()->lookup_context);
    }

    public function test_household_head_eligibility_explicit_grant_and_role_policy_are_each_required(): void
    {
        $this->openSides();
        // Not a household head: the family.context boundary refuses before any lookup.
        $notHead = $this->activatedHead('123456781');
        FamilyMembership::query()->whereKey($notHead['membership']->id)->update(['is_household_head' => false]);
        $member = $notHead['user'];
        $this->grant($member, ['FAMILY']);
        $this->lookup($member, self::FAMILY, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403)
            ->assertJsonPath('code', 'FAMILY_CONTEXT_UNAVAILABLE');

        // A household head holding the (gated) permission but without an explicit grant.
        $head = $this->householdHead('123456782');
        $this->assertTrue($head->can('civil-lookup.search'));
        $this->lookup($head, self::FAMILY, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403)->assertJsonPath('code', 'LOOKUP_NOT_ALLOWED');
        $refused = CivilLookupAuditEvent::query()->where('event_type', 'LOOKUP_REFUSED')->sole();
        $this->assertSame(['FAMILY', 'NOT_ALLOWED'], [$refused->lookup_context, $refused->outcome_code]);

        // Granted for Coordinator Space only: the Family Portal stays closed.
        $this->grant($head, ['COORDINATOR']);
        $this->lookup($head, self::FAMILY, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403);

        // Granted for the Family Portal, but the FAMILY_USER policy is disabled.
        CivilLookupUserAccess::query()->whereKey($head->id)->update(['sides' => 'FAMILY']);
        CivilLookupRolePolicy::where('role_name', 'FAMILY_USER')->update(['enabled' => false]);
        $this->lookup($head, self::FAMILY, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403);

        CivilLookupRolePolicy::where('role_name', 'FAMILY_USER')->update(['enabled' => true]);
        $this->lookup($head, self::FAMILY, '/search/national-id', ['national_id' => '900000001'])->assertOk();
        $this->assertSame(1, $this->used($head), 'only the authorized lookup counted');
    }

    public function test_a_coordinator_needs_an_effective_assignment_and_its_own_explicit_grant(): void
    {
        $this->openSides();
        // COORDINATOR role, granted, but no assignment: the coordinator.space boundary refuses.
        $unassigned = $this->coordinator('121111110');
        $this->grant($unassigned, ['COORDINATOR']);
        $this->lookup($unassigned, self::COORDINATOR, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403)
            ->assertJsonPath('code', 'COORDINATOR_SPACE_UNAVAILABLE');

        // An effective assignment alone grants nothing.
        $coordinator = $this->activeCoordinator('131111110');
        $this->lookup($coordinator, self::COORDINATOR, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403)
            ->assertJsonPath('code', 'LOOKUP_NOT_ALLOWED');
        $this->actingAs($coordinator)->getJson('/api/v1/family/me')->assertOk()->assertJsonPath('user.coordinator_space', true)
            ->assertJsonPath('user.civil_lookup.coordinator', false);

        // A Family Portal grant is not a Coordinator Space grant.
        $this->grant($coordinator, ['FAMILY']);
        $this->lookup($coordinator, self::COORDINATOR, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403);
        $this->lookup($coordinator, self::FAMILY, '/search/national-id', ['national_id' => '900000001'])->assertOk();

        CivilLookupUserAccess::query()->whereKey($coordinator->id)->update(['sides' => 'FAMILY,COORDINATOR']);
        $this->lookup($coordinator, self::COORDINATOR, '/search/national-id', ['national_id' => '900000001'])->assertOk();
        $this->assertSame('COORDINATOR', CivilLookupAuditEvent::query()->where('event_type', 'SEARCH_BY_ID')->latest('id')->first()->lookup_context);

        // The COORDINATOR policy is its own switch.
        CivilLookupRolePolicy::where('role_name', 'COORDINATOR')->update(['enabled' => false]);
        $this->lookup($coordinator, self::COORDINATOR, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403);
        $this->lookup($coordinator, self::FAMILY, '/search/national-id', ['national_id' => '900000001'])->assertOk();

        // Revocation closes every side at once; nothing else about the account changes.
        CivilLookupUserAccess::query()->whereKey($coordinator->id)->update(['access' => 'REVOKED', 'sides' => null]);
        $this->lookup($coordinator, self::FAMILY, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403);
        $this->assertTrue($coordinator->fresh()->hasRole('COORDINATOR'));
        $this->actingAs($coordinator)->getJson('/api/v1/family/coordinator/context')->assertOk();
    }

    public function test_one_daily_quota_is_shared_across_the_family_portal_and_coordinator_space_and_resets_at_gaza_midnight(): void
    {
        $this->openSides();
        $user = $this->activeCoordinator();
        $this->grant($user, ['FAMILY', 'COORDINATOR']);
        Carbon::setTestNow(Carbon::parse('2026-07-15 08:00:00', 'UTC'));

        foreach ([self::FAMILY, self::COORDINATOR, self::FAMILY, self::COORDINATOR, self::FAMILY] as $base) {
            $this->lookup($user, $base, '/search/national-id', ['national_id' => '900000001'])->assertOk();
        }
        $this->assertSame(5, $this->used($user));
        $this->assertSame(1, DB::table('civil_lookup_daily_usage')->where('user_id', $user->id)->count(), 'one counter per user per day');
        $this->lookup($user, self::COORDINATOR, '/search/national-id', ['national_id' => '900000001'])->assertStatus(429)->assertJsonPath('code', 'QUOTA_EXHAUSTED');
        $this->lookup($user, self::FAMILY, '/search/name', ['fullname' => 'سامي خالد'])->assertStatus(429);
        $this->actingAs($user)->getJson(self::COORDINATOR.'/status')->assertOk()->assertJsonPath('data.quota.remaining', 0);
        $this->assertSame(5, $this->used($user));

        // Invalid requests are not charged.
        $this->lookup($user, self::FAMILY, '/search/national-id', ['national_id' => '12'])->assertStatus(422);

        // The next Asia/Gaza day (UTC+3 in July): a fresh allowance, still one counter.
        Carbon::setTestNow(Carbon::parse('2026-07-15 21:05:00', 'UTC'));
        $this->lookup($user, self::COORDINATOR, '/search/national-id', ['national_id' => '900000001'])->assertOk()->assertJsonPath('data.quota.used_after', 1);
    }

    public function test_pagination_and_listed_records_are_free_linked_citizens_are_charged_and_reverse_links_stay_disabled(): void
    {
        $this->openSides();
        $head = $this->householdHead();
        $this->grant($head, ['FAMILY']);
        for ($i = 0; $i < 30; $i++) {
            $this->registry->add((string) (910000000 + $i), ['fullname' => 'نادر وليد تجريبية', 'name1' => 'نادر', 'father' => 'وليد', 'family' => 'تجريبية']);
        }

        $first = $this->lookup($head, self::FAMILY, '/search/name', ['fullname' => 'نادر تجريبية'])->assertOk()->assertJsonPath('data.meta.total', 30);
        $this->assertCount(25, $first->json('data.results'));
        $this->lookup($head, self::FAMILY, '/search/page', ['ref' => $first->json('data.search_ref'), 'page' => 2])->assertOk();
        $this->assertSame(1, $this->used($head), 'the second page is free');

        $list = $this->lookup($head, self::FAMILY, '/search/national-id', ['national_id' => '900000001'])->assertOk();
        $record = $this->lookup($head, self::FAMILY, '/records/open', ['ref' => $list->json('data.results.0.ref')])->assertOk()->json('data.record');
        $this->assertSame(2, $this->used($head));
        $father = collect($record['links'])->firstWhere('relation', 'FATHER');
        $linked = $this->lookup($head, self::FAMILY, '/links/open', ['ref' => $father['ref']])->assertOk()
            ->assertJsonPath('data.record.level', 'EXTENDED_CONTROLLED')->assertJsonPath('data.record.national_id', '*****0002');
        $this->assertSame(3, $this->used($head), 'a linked citizen is a new, counted lookup');
        $this->assertStringNotContainsString('900000002', $linked->getContent());

        $this->lookup($head, self::FAMILY, '/links/list', ['ref' => 'x', 'relation' => 'CHILDREN'])->assertStatus(403)->assertJsonPath('code', 'LINKS_DISABLED');
        $this->assertSame(3, $this->used($head));
    }

    public function test_references_are_bound_to_their_context_and_the_sides_never_cross(): void
    {
        $this->openSides();
        $user = $this->activeCoordinator();
        $this->grant($user, ['FAMILY', 'COORDINATOR']);
        $list = $this->lookup($user, self::FAMILY, '/search/national-id', ['national_id' => '900000001'])->assertOk();
        $ref = $list->json('data.results.0.ref');

        // The same user, the same record — but issued on the Family Portal: refused in Coordinator Space.
        $this->lookup($user, self::COORDINATOR, '/records/open', ['ref' => $ref])->assertStatus(422)->assertJsonPath('code', 'REFERENCE_INVALID');
        $this->lookup($user, self::FAMILY, '/records/open', ['ref' => $ref])->assertOk();

        // A family-side account never reaches the Staff mount, and a Staff account never the family mounts.
        $this->lookup($user, self::STAFF, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403);
        $this->lookup($user, self::STAFF, '/records/open', ['ref' => $ref])->assertStatus(403);
        $admin = User::factory()->create();
        $admin->assignRole('ADMINISTRATOR');
        $this->lookup($admin, self::FAMILY, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403);
        $this->lookup($admin, self::COORDINATOR, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403);
        $staffList = $this->lookup($admin, self::STAFF, '/search/national-id', ['national_id' => '900000001'])->assertOk();
        $this->lookup($admin, self::STAFF, '/records/open', ['ref' => $staffList->json('data.results.0.ref')])->assertOk()
            ->assertJsonPath('data.record.level', 'FULL');
        $this->lookup($user, self::FAMILY, '/records/open', ['ref' => $staffList->json('data.results.0.ref')])->assertStatus(422);

        // No family-side lookup route carries an identifier or accepts GET.
        $this->actingAs($user)->getJson(self::FAMILY.'/search/national-id?national_id=900000001')->assertStatus(405);
    }

    public function test_in_production_each_side_needs_its_own_approval_and_the_field_profile_approval(): void
    {
        $this->openSides();
        $head = $this->householdHead();
        $this->grant($head, ['FAMILY']);
        $this->app['env'] = 'production';
        putenv(CivilLookupGate::VARIABLE.'=APPROVAL-STAFF-TEST-1');
        $search = fn () => $this->lookup($head, self::FAMILY, '/search/national-id', ['national_id' => '900000001']);

        $search()->assertStatus(503)->assertJsonPath('code', 'LOOKUP_DISABLED');
        putenv(CivilLookupGate::SIDE_VARIABLES['FAMILY'].'=APPROVAL-FAMILY-TEST-1');
        $search()->assertStatus(503);
        putenv(CivilLookupGate::PROFILE_VARIABLE.'=APPROVAL-PROFILE-TEST-1');
        $search()->assertOk();
        // The Coordinator side's approval is separate.
        $this->assertFalse(CivilLookupGate::allowsContext('COORDINATOR'));
        // And none of it without the global approval.
        putenv(CivilLookupGate::VARIABLE);
        $search()->assertStatus(503);
    }

    public function test_individual_grants_are_per_side_explicit_audited_and_refused_while_a_side_is_closed(): void
    {
        $this->openSides(family: true, coordinator: false);
        $super = User::factory()->create();
        $super->assignRole('SUPER_ADMIN');
        $staff = User::factory()->create();
        $staff->assignRole('DATA_ENTRY');
        $head = $this->activatedHead('123456783');
        $put = fn (array $body) => $this->actingAs($super)->putJson(self::STAFF.'/admin/user-access', $body);

        $this->actingAs($super)->getJson(self::STAFF.'/admin/usage')->assertOk()->assertJsonPath('data.grantable_sides', ['FAMILY'])
            ->assertJsonPath('data.side_lookup_enabled.FAMILY', true)->assertJsonPath('data.side_lookup_enabled.COORDINATOR', false);

        // A family-side account is addressed by its person code (it has no email).
        $put(['person_code' => $head['person']->person_code, 'access' => 'GRANTED', 'sides' => ['FAMILY']])->assertOk()
            ->assertJsonPath('data.access', 'GRANTED')->assertJsonPath('data.sides', ['FAMILY']);
        // A closed side cannot be granted; COORDINATOR also needs the role; a sideless grant is refused.
        $put(['person_code' => $head['person']->person_code, 'access' => 'GRANTED', 'sides' => ['FAMILY', 'COORDINATOR']])->assertStatus(422);
        $put(['person_code' => $head['person']->person_code, 'access' => 'GRANTED'])->assertStatus(422);
        config(['civil_lookup.coordinator_side_enabled' => true]);
        $put(['person_code' => $head['person']->person_code, 'access' => 'GRANTED', 'sides' => ['COORDINATOR']])->assertStatus(422)
            ->assertJsonValidationErrors('sides');
        // Never a Staff account; never sides with REVOKED / DEFAULT.
        $put(['email' => $staff->email, 'access' => 'GRANTED', 'sides' => ['FAMILY']])->assertStatus(422);
        $put(['person_code' => $head['person']->person_code, 'access' => 'REVOKED', 'sides' => ['FAMILY']])->assertStatus(422);
        $put(['person_code' => $head['person']->person_code, 'access' => 'REVOKED'])->assertOk()->assertJsonPath('data.sides', []);
        $put(['person_code' => 'NO-SUCH-PERSON', 'access' => 'REVOKED'])->assertStatus(422)->assertJsonValidationErrors('person_code');
        $put(['email' => $staff->email, 'person_code' => $head['person']->person_code, 'access' => 'REVOKED'])->assertStatus(422);

        $changes = CivilLookupPolicyChange::query()->where('subject_type', 'USER')->orderBy('id')->get();
        $this->assertSame([[null, 'GRANTED', null, 'FAMILY'], ['GRANTED', 'REVOKED', 'FAMILY', null]],
            $changes->map(fn ($c) => [$c->old_access, $c->new_access, $c->old_sides, $c->new_sides])->all());
        $this->actingAs($super)->getJson(self::STAFF.'/admin/changes')->assertOk()->assertJsonPath('data.0.old_sides', ['FAMILY']);

        // Family-side accounts never reach the administration.
        $this->actingAs($head['user'])->getJson(self::STAFF.'/admin/usage')->assertStatus(403);
    }

    public function test_the_permission_baseline_follows_the_side_switches_and_direct_grants_are_critical(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->assertSame(0, Artisan::call('famboook:verify-permissions'));

        $this->openSides(family: true, coordinator: false);
        $this->assertSame(0, Artisan::call('famboook:verify-permissions'));
        $head = $this->householdHead();
        $this->assertTrue($head->can('civil-lookup.search'));
        $this->assertFalse($head->can('civil-lookup.view-full') || $head->can('civil-lookup.usage.view') || $head->can('civil-lookup.manage'));

        // Closing the switch without reseeding is reported (and the runtime gate already refuses).
        config(['civil_lookup.family_side_enabled' => false]);
        $this->assertSame(1, Artisan::call('famboook:verify-permissions'));
        $this->assertStringContainsString('FAMILY_USER must NOT have civil-lookup.search', Artisan::output());

        $this->seed(RolePermissionSeeder::class);
        $head->fresh()->givePermissionTo('civil-lookup.search');
        $this->assertSame(1, Artisan::call('famboook:verify-permissions'));
        $this->assertStringContainsString('civil lookup permission grant(s) made directly', Artisan::output());
    }
}
