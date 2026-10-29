<?php

namespace Tests\Feature\CivilLookup;

use App\Models\CivilLookupAuditEvent;
use App\Models\CivilLookupPolicyChange;
use App\Models\CivilLookupRolePolicy;
use App\Models\User;
use App\Support\CivilLookup\CivilRegistrySource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Support\CivilLookup\FakeCivilRegistry;
use Tests\TestCase;

/**
 * FB-CIVIL-02A — Civil Registry Lookup API (docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md):
 * gate, authorization, field visibility, quota and charging rules, references,
 * audit, rate limits and administration, against an in-memory registry
 * (FakeCivilRegistry). The PostgreSQL functions themselves: CivilLookupPostgresTest.
 * SYNTHETIC data only (900000xxx identifiers, placeholder names).
 */
class CivilLookupApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/civil-registry/lookup';

    private FakeCivilRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['civil_lookup.enabled' => true, 'family_auth.fingerprint' => [
            'key' => 'test-only-civil-lookup-fingerprint-key-0123456789', 'key_version' => 1, 'previous_key' => null, 'previous_key_version' => null,
        ]]);
        $this->registry = (new FakeCivilRegistry)
            ->add('900000001', ['fullname' => 'مُحَمَّد أحمد عبد الله سالم', 'name1' => 'محمد', 'father' => 'أحمد', 'grandfath' => 'عبد الله',
                'family' => 'سالم', 'fatherid' => '900000002', 'motherid' => '900000099', 'householdid' => 'H-1', 'phonenum' => '0599000000',
                'relgion' => 'X', 'worker' => '1'])
            ->add('900000002', ['fullname' => 'احمد عبدالله سالم', 'name1' => 'احمد', 'father' => 'عبدالله', 'family' => 'سالم', 'householdid' => 'H-1']);
        $this->app->instance(CivilRegistrySource::class, $this->registry);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function lookup(User $user, string $path, array $body = []): TestResponse
    {
        return $this->actingAs($user)->postJson(self::BASE.$path, $body);
    }

    private function used(User $user): int
    {
        return (int) DB::table('civil_lookup_daily_usage')->where('user_id', $user->id)->sum('used');
    }

    /** Many same-named synthetic records. */
    private function crowd(int $n, string $family = 'تجريبية'): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->registry->add((string) (910000000 + $i), ['fullname' => "سامي خالد {$family}", 'name1' => 'سامي', 'father' => 'خالد', 'family' => $family]);
        }
    }

    public function test_the_lookup_is_closed_by_default_and_without_a_production_approval(): void
    {
        $admin = $this->staff('ADMINISTRATOR');
        config(['civil_lookup.enabled' => false]);
        $this->lookup($admin, '/search/national-id', ['national_id' => '900000001'])->assertStatus(503)
            ->assertJsonPath('code', 'LOOKUP_DISABLED')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($admin)->getJson(self::BASE.'/status')->assertOk()
            ->assertJsonPath('data.quota.enabled', false)->assertJsonPath('data.quota.reason', 'LOOKUP_DISABLED');

        config(['civil_lookup.enabled' => true]);
        $this->app['env'] = 'production';
        putenv('CIVIL_LOOKUP_PRODUCTION_APPROVAL');
        $this->lookup($admin, '/search/national-id', ['national_id' => '900000001'])->assertStatus(503)->assertJsonPath('code', 'LOOKUP_DISABLED');
        $this->assertSame(0, $this->registry->reads, 'a closed gate reads nothing');
        $this->assertSame(0, CivilLookupAuditEvent::count());
        $this->assertSame(0, $this->used($admin));
    }

    public function test_a_national_id_lookup_returns_one_masked_result_counts_and_is_audited_without_the_value(): void
    {
        $admin = $this->staff('ADMINISTRATOR');
        $response = $this->lookup($admin, '/search/national-id', ['national_id' => '٩٠٠٠٠٠٠٠١'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.outcome', 'FOUND')->assertJsonPath('data.release_id', 1)
            ->assertJsonCount(1, 'data.results')->assertJsonPath('data.results.0.national_id', '*****0001')
            ->assertJsonPath('data.results.0.fullname', 'مُحَمَّد أحمد عبد الله سالم')
            // FB-CIVIL-02C (corrected): ADMINISTRATOR is unlimited; the lookup still counts (statistics).
            ->assertJsonPath('data.quota.used', 1)->assertJsonPath('data.quota.unlimited', true)->assertJsonPath('data.quota.remaining', null);
        $this->assertStringNotContainsString('900000001', $response->getContent(), 'a result list never carries the clear National ID');

        $event = CivilLookupAuditEvent::sole();
        $this->assertSame(['SEARCH_BY_ID', 'FOUND', 1, 1, 1, 1], [$event->event_type, $event->outcome_code, $event->release_id,
            $event->record_id, $event->result_count, $event->quota_used_after]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $event->query_fingerprint);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $event->ip_hash);
        $this->assertStringNotContainsString('900000001', json_encode(DB::table('civil_lookup_audit_events')->get()));

        // NOT_FOUND counts too; a malformed ID is refused before execution and counts nothing.
        $this->lookup($admin, '/search/national-id', ['national_id' => '900000077'])->assertOk()
            ->assertJsonPath('data.outcome', 'NOT_FOUND')->assertJsonCount(0, 'data.results')->assertJsonPath('data.quota.used', 2);
        $reads = $this->registry->reads;
        foreach (['12345', '111111111', 'abcdefghi', ''] as $bad) {
            $this->lookup($admin, '/search/national-id', ['national_id' => $bad])->assertStatus(422)->assertJsonMissing(['national_id' => $bad]);
        }
        $this->assertSame([2, $reads], [$this->used($admin), $this->registry->reads]);
    }

    public function test_name_search_normalizes_paginates_for_free_and_refuses_foreign_or_tampered_references(): void
    {
        $user = $this->staff('DATA_ENTRY');
        // Diacritics, tatweel, alef forms and extra spaces are ignored; tokens in any order; compound names with or without a space.
        $this->lookup($user, '/search/name', ['fullname' => '  سالم   مُحَمَّـد  أحمد '])->assertOk()
            ->assertJsonPath('data.outcome', 'RESULTS')->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.results.0.national_id', '*****0001');
        $this->lookup($user, '/search/name', ['fullname' => 'عبد الله سالم'])->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->assertSame(2, $this->used($user));

        $this->crowd(30);
        $first = $this->lookup($user, '/search/name', ['fullname' => 'سامي تجريبية'])->assertOk()
            ->assertJsonPath('data.meta.total', 30)->assertJsonPath('data.meta.last_page', 2)->assertJsonCount(25, 'data.results');
        $ref = $first->json('data.search_ref');
        $this->assertIsString($ref);
        $this->lookup($user, '/search/page', ['ref' => $ref, 'page' => 2])->assertOk()->assertJsonCount(5, 'data.results')
            ->assertJsonPath('data.meta.page', 2);
        $this->assertSame(3, $this->used($user), 'further pages of the same search are free');
        $this->assertSame(1, CivilLookupAuditEvent::where('event_type', 'SEARCH_PAGE')->count());

        $this->lookup($user, '/search/page', ['ref' => $ref.'x', 'page' => 2])->assertStatus(422)->assertJsonPath('code', 'REFERENCE_INVALID');
        $this->lookup($this->staff('DATA_ENTRY'), '/search/page', ['ref' => $ref, 'page' => 2])->assertStatus(422)->assertJsonPath('code', 'REFERENCE_INVALID');
        $this->lookup($user, '/search/page', ['ref' => $ref, 'page' => 21])->assertStatus(422);
        $this->travel(31)->minutes();
        $this->lookup($user, '/search/page', ['ref' => $ref, 'page' => 2])->assertStatus(422)->assertJsonPath('code', 'REFERENCE_INVALID');

        // Too short or too long: refused before execution, never charged.
        foreach (['محمد', 'م ح', 'مح اح', str_repeat('اسم ', 7)] as $bad) {
            $this->lookup($user, '/search/name', ['fullname' => $bad])->assertStatus(422);
        }
        $this->assertSame(3, $this->used($user));
    }

    public function test_a_too_broad_search_returns_no_rows_and_still_counts(): void
    {
        $this->crowd(501);
        $user = $this->staff('ADMINISTRATOR');
        $this->lookup($user, '/search/name', ['fullname' => 'سامي خالد'])->assertOk()
            ->assertJsonPath('data.outcome', 'TOO_BROAD')->assertJsonCount(0, 'data.results')
            ->assertJsonPath('data.meta.total', null)->assertJsonPath('data.search_ref', null);
        $this->assertSame(1, $this->used($user));
        $this->assertSame('TOO_BROAD', CivilLookupAuditEvent::sole()->outcome_code);
    }

    public function test_detailed_search_needs_two_to_four_fields(): void
    {
        $user = $this->staff('DATA_ENTRY');
        $this->lookup($user, '/search/parts', ['family' => 'سالم'])->assertStatus(422);
        $this->lookup($user, '/search/parts', ['name1' => 'م', 'family' => 'س'])->assertStatus(422);
        $this->lookup($user, '/search/parts', ['name1' => 'محمد<script>', 'family' => 'سالم'])->assertStatus(422);
        $this->assertSame(0, $this->used($user));

        $this->lookup($user, '/search/parts', ['father' => 'أحمد', 'family' => 'سالم'])->assertOk()
            ->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.results.0.national_id', '*****0001');
        $this->lookup($user, '/search/parts', ['name1' => 'احمد', 'father' => 'عبد', 'grandfath' => null, 'family' => 'سالم'])->assertOk()
            ->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.results.0.national_id', '*****0002');
        $this->assertSame(2, $this->used($user));
    }

    public function test_opening_a_listed_record_is_free_and_opening_a_linked_citizen_is_a_new_counted_lookup(): void
    {
        $user = $this->staff('ADMINISTRATOR');
        $ref = $this->lookup($user, '/search/national-id', ['national_id' => '900000001'])->json('data.results.0.ref');
        $record = $this->lookup($user, '/records/open', ['ref' => $ref])->assertOk()
            ->assertJsonPath('data.record.national_id', '900000001')->assertJsonPath('data.record.level', 'FULL')->json('data.record');
        $this->assertSame(1, $this->used($user), 'opening a listed record is free');

        $links = collect($record['links'])->keyBy('relation');
        $this->assertSame(['AVAILABLE', 'NOT_IN_RELEASE', 'EMPTY', 'DISABLED', 'DISABLED'],
            [$links['FATHER']['status'], $links['MOTHER']['status'], $links['SPOUSE']['status'], $links['HOUSEHOLD']['status'], $links['CHILDREN']['status']]);
        $this->assertArrayNotHasKey('ref', $links['MOTHER'], 'a missing reference is never navigable');
        $this->assertSame('900000099', $links['MOTHER']['national_id']);

        $father = $this->lookup($user, '/links/open', ['ref' => $links['FATHER']['ref']])->assertOk()
            ->assertJsonPath('data.outcome', 'FOUND')->assertJsonPath('data.relation', 'FATHER')
            ->assertJsonPath('data.record.national_id', '900000002');
        $this->assertSame(2, $this->used($user), 'a linked citizen is a new lookup');
        $this->assertSame(['SEARCH_BY_ID', 'RECORD_OPENED', 'LINK_OPENED'], CivilLookupAuditEvent::orderBy('id')->pluck('event_type')->all());

        // A link reference is not a record reference (and the reverse), and cannot be used across releases.
        $this->lookup($user, '/records/open', ['ref' => $links['FATHER']['ref']])->assertStatus(422);
        $this->lookup($user, '/links/open', ['ref' => $ref])->assertStatus(422);
        $this->registry->releaseId = 2;
        $this->lookup($user, '/links/open', ['ref' => $links['FATHER']['ref']])->assertStatus(409)->assertJsonPath('code', 'RELEASE_CHANGED');
        $this->lookup($user, '/records/open', ['ref' => $father->json('data.record.ref')])->assertStatus(409);
        $this->assertSame(2, $this->used($user), 'a refused navigation is not charged');

        // Reverse links stay disabled.
        $this->lookup($user, '/links/list', ['ref' => 'x', 'relation' => 'HOUSEHOLD'])->assertStatus(403)->assertJsonPath('code', 'LINKS_DISABLED');
    }

    public function test_reverse_links_when_enabled_list_household_members_and_children_without_implying_kinship(): void
    {
        config(['civil_lookup.reverse_links_enabled' => true]);
        $this->registry->add('900000003', ['fullname' => 'فاطمة احمد سالم', 'fatherid' => '900000002', 'householdid' => 'H-1']);
        $user = $this->staff('ADMINISTRATOR');
        $ref = $this->lookup($user, '/search/national-id', ['national_id' => '900000002'])->json('data.results.0.ref');
        $links = collect($this->lookup($user, '/records/open', ['ref' => $ref])->json('data.record.links'))->keyBy('relation');
        $this->assertSame(['AVAILABLE', 'AVAILABLE'], [$links['HOUSEHOLD']['status'], $links['CHILDREN']['status']]);

        $household = $this->lookup($user, '/links/list', ['ref' => $links['HOUSEHOLD']['ref'], 'relation' => 'HOUSEHOLD'])->assertOk()
            ->assertJsonPath('data.relation', 'HOUSEHOLD')->assertJsonPath('data.meta.total', 2)->json('data.results');
        $this->assertNotContains('*****0002', array_column($household, 'national_id'), 'never the record itself');
        $this->lookup($user, '/links/list', ['ref' => $links['CHILDREN']['ref'], 'relation' => 'CHILDREN'])->assertOk()
            ->assertJsonPath('data.meta.total', 2);
        $this->assertSame(3, $this->used($user), 'each reverse-link list is a counted lookup');
    }

    public function test_field_visibility_is_enforced_by_the_api_and_relgion_is_never_returned(): void
    {
        $standard = $this->staff('DATA_ENTRY');
        $ref = $this->lookup($standard, '/search/national-id', ['national_id' => '900000001'])->json('data.results.0.ref');
        $response = $this->lookup($standard, '/records/open', ['ref' => $ref])->assertOk()
            // FB-CIVIL-02F: STANDARD shows the opened record's own National ID (a charged disclosure); links stay masked.
            ->assertJsonPath('data.record.level', 'STANDARD')->assertJsonPath('data.record.national_id', '900000001');
        $sections = collect($response->json('data.record.sections'))->mapWithKeys(fn ($s) => [$s['key'] => array_column($s['fields'], 'key')]);
        $this->assertSame(['personal', 'family', 'geographic'], $sections->keys()->all());
        $this->assertNotContains('phonenum', $sections->flatten()->all());
        $this->assertNotContains('fatherid', $sections->flatten()->all());
        $links = collect($response->json('data.record.links'))->keyBy('relation');
        $this->assertSame('*****0002', $links['FATHER']['national_id'], 'linked IDs are masked without view-full');
        foreach (['900000002', '0599000000', 'relgion'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $response->getContent());
        }

        $full = $this->staff('ADMINISTRATOR');
        $ref = $this->lookup($full, '/search/national-id', ['national_id' => '900000001'])->json('data.results.0.ref');
        $response = $this->lookup($full, '/records/open', ['ref' => $ref])->assertOk()->assertJsonPath('data.record.level', 'FULL');
        $keys = collect($response->json('data.record.sections'))->flatMap(fn ($s) => array_column($s['fields'], 'key'))->all();
        $this->assertCount(35, $keys, 'every source field except relgion');
        $this->assertNotContains('relgion', $keys);
        $this->assertStringNotContainsString('relgion', $response->getContent());
        $values = collect($response->json('data.record.sections'))->flatMap(fn ($s) => $s['fields'])->pluck('value', 'key');
        $this->assertSame(['0599000000', '1', null], [$values['phonenum'], $values['worker'], $values['deathdate']], 'raw values; empty is null');
    }

    /**
     * FB-CIVIL-02E: the full National ID reaches only a FULL requester, only for
     * an opened record, never in a result list, never in the audit, and never
     * once the requester is revoked — even with a reference issued before.
     */
    public function test_a_full_national_id_reaches_only_an_authorized_full_requester_for_an_opened_record(): void
    {
        $super = $this->staff('SUPER_ADMIN');
        $full = $this->staff('ADMINISTRATOR');
        $search = $this->lookup($full, '/search/national-id', ['national_id' => '900000001'])->assertOk()
            ->assertJsonPath('data.results.0.national_id', '*****0001');
        $this->assertStringNotContainsString('900000001', $search->getContent(), 'a result list is masked even for FULL');
        $ref = $search->json('data.results.0.ref');

        $this->lookup($full, '/records/open', ['ref' => $ref])->assertOk()->assertJsonPath('data.record.national_id', '900000001');
        $this->assertSame(1, $this->used($full), 'opening a listed record is not counted');
        foreach (CivilLookupAuditEvent::all() as $event) {
            $this->assertStringNotContainsString('900000001', json_encode($event->getAttributes()), 'the audit never keeps the National ID');
        }

        $this->actingAs($super)->putJson(self::BASE.'/admin/user-access', ['email' => $full->email, 'access' => 'REVOKED'])->assertOk();
        $refused = $this->lookup($full, '/records/open', ['ref' => $ref])->assertStatus(403)->assertJsonPath('code', 'LOOKUP_NOT_ALLOWED');
        $this->assertStringNotContainsString('900000001', $refused->getContent());
        $this->assertSame(1, $this->used($full));
    }

    public function test_the_daily_quota_is_atomic_per_user_withholds_results_when_exhausted_and_the_super_admin_is_unlimited(): void
    {
        CivilLookupRolePolicy::where('role_name', 'DATA_ENTRY')->update(['daily_limit' => 2]);
        $user = $this->staff('DATA_ENTRY');
        $this->lookup($user, '/search/national-id', ['national_id' => '900000001'])->assertOk();
        $this->lookup($user, '/search/national-id', ['national_id' => '900000002'])->assertOk()->assertJsonPath('data.quota.remaining', 0);
        $this->lookup($user, '/search/national-id', ['national_id' => '900000001'])->assertStatus(429)
            ->assertJsonPath('code', 'QUOTA_EXHAUSTED')->assertJsonPath('quota.remaining', 0)->assertJsonMissingPath('data');
        $this->assertSame(2, $this->used($user));
        $this->assertSame('QUOTA_EXHAUSTED', CivilLookupAuditEvent::latest('id')->first()->outcome_code);
        $this->actingAs($user)->getJson(self::BASE.'/status')->assertJsonPath('data.quota.remaining', 0)->assertJsonPath('data.quota.daily_limit', 2);

        // Another user's counter is separate.
        $this->lookup($this->staff('DATA_ENTRY'), '/search/national-id', ['national_id' => '900000001'])->assertOk();

        $super = $this->staff('SUPER_ADMIN');
        for ($i = 0; $i < 5; $i++) {
            $this->lookup($super, '/search/national-id', ['national_id' => '900000001'])->assertOk();
        }
        $this->actingAs($super)->getJson(self::BASE.'/status')->assertJsonPath('data.quota.unlimited', true)
            ->assertJsonPath('data.quota.remaining', null)->assertJsonPath('data.quota.used', 5);
    }

    public function test_the_quota_day_is_the_asia_gaza_calendar_day(): void
    {
        $user = $this->staff('DATA_ENTRY');
        $dates = fn () => DB::table('civil_lookup_daily_usage')->orderBy('usage_date')->pluck('usage_date')->map(fn ($d) => (string) $d)->all();
        // Summer (UTC+3): 20:30 UTC on 15 July 2026 is 23:30 in Gaza; the reset is local midnight, 21:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-07-15 20:30:00', 'UTC'));
        $this->lookup($user, '/search/national-id', ['national_id' => '900000001'])->assertOk()
            ->assertJsonPath('data.quota.resets_at', '2026-07-15T21:00:00+00:00');
        // 21:10 UTC is 00:10 on 16 July in Gaza (still 15 July in UTC): a fresh counter.
        Carbon::setTestNow(Carbon::parse('2026-07-15 21:10:00', 'UTC'));
        $this->lookup($user, '/search/national-id', ['national_id' => '900000001'])->assertOk()->assertJsonPath('data.quota.used', 1);
        $this->assertSame(['2026-07-15', '2026-07-16'], $dates());

        // Winter (UTC+2, after the October change): local midnight is 22:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-27 21:30:00', 'UTC'));
        $this->lookup($user, '/search/national-id', ['national_id' => '900000001'])->assertOk()
            ->assertJsonPath('data.quota.used', 1)->assertJsonPath('data.quota.resets_at', '2026-10-27T22:00:00+00:00');
        Carbon::setTestNow(Carbon::parse('2026-10-27 22:10:00', 'UTC'));
        $this->lookup($user, '/search/national-id', ['national_id' => '900000001'])->assertOk()->assertJsonPath('data.quota.used', 1);
        $this->assertSame(['2026-07-15', '2026-07-16', '2026-10-27', '2026-10-28'], $dates());
        Carbon::setTestNow();
    }

    public function test_disabled_roles_individual_revocation_and_the_family_side_are_refused(): void
    {
        $viewer = $this->staff('REPORTS_VIEWER');
        $this->lookup($viewer, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403)->assertJsonPath('code', 'LOOKUP_NOT_ALLOWED');
        $this->assertSame(['LOOKUP_REFUSED', 'NOT_ALLOWED'], [CivilLookupAuditEvent::sole()->event_type, CivilLookupAuditEvent::sole()->outcome_code]);

        $super = $this->staff('SUPER_ADMIN');
        $user = $this->staff('DATA_ENTRY');
        $this->actingAs($super)->putJson(self::BASE.'/admin/user-access', ['email' => $user->email, 'access' => 'REVOKED'])->assertOk()
            ->assertJsonPath('data.access', 'REVOKED');
        $this->lookup($user, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403)->assertJsonPath('code', 'LOOKUP_NOT_ALLOWED');
        $this->assertTrue($user->fresh()->hasRole('DATA_ENTRY') && $user->fresh()->can('civil-lookup.search'), 'roles and permissions untouched');
        $this->actingAs($super)->putJson(self::BASE.'/admin/user-access', ['email' => $user->email, 'access' => 'DEFAULT'])->assertOk();
        $this->lookup($user, '/search/national-id', ['national_id' => '900000001'])->assertOk();
        // GRANTED is the family side's (02B): refused while that side is closed.
        $this->actingAs($super)->putJson(self::BASE.'/admin/user-access', ['email' => $user->email, 'access' => 'GRANTED'])->assertStatus(422);

        // FAMILY_USER, COORDINATOR, or both: never through the Staff API, whatever the policy says.
        CivilLookupRolePolicy::whereIn('role_name', ['FAMILY_USER', 'COORDINATOR'])->update(['enabled' => true]);
        $family = User::factory()->create();
        $family->assignRole(['FAMILY_USER', 'COORDINATOR']);
        $this->lookup($family, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403);
        $this->actingAs($family)->getJson(self::BASE.'/status')->assertStatus(403);
        $this->assertFalse($family->can('civil-lookup.search'), 'a Coordinator assignment never implies lookup');

        $this->assertSame(2, CivilLookupPolicyChange::where('subject_type', 'USER')->count());
    }

    public function test_an_internal_failure_or_a_missing_release_is_not_charged(): void
    {
        $user = $this->staff('ADMINISTRATOR');
        $this->registry->fail = true;
        $this->lookup($user, '/search/national-id', ['national_id' => '900000001'])->assertStatus(503)->assertJsonPath('code', 'LOOKUP_UNAVAILABLE');
        $this->registry->fail = false;
        $this->registry->releaseId = null;
        $this->lookup($user, '/search/national-id', ['national_id' => '900000001'])->assertStatus(503)->assertJsonPath('code', 'NO_PUBLISHED_RELEASE');
        $this->lookup($user, '/search/name', ['fullname' => 'محمد سالم'])->assertStatus(503)->assertJsonPath('code', 'NO_PUBLISHED_RELEASE');
        $this->assertSame(0, $this->used($user));
    }

    public function test_rate_limits_are_independent_of_the_quota_and_invalid_requests_are_limited_separately(): void
    {
        config(['civil_lookup.rate_limits.per_minute' => 3, 'civil_lookup.rate_limits.invalid_per_hour' => 2]);
        $user = $this->staff('SUPER_ADMIN');
        for ($i = 0; $i < 3; $i++) {
            $this->lookup($user, '/search/national-id', ['national_id' => '900000001'])->assertOk();
        }
        $this->lookup($user, '/search/national-id', ['national_id' => '900000001'])->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');

        $other = $this->staff('ADMINISTRATOR');
        $this->lookup($other, '/search/national-id', ['national_id' => '1'])->assertStatus(422);
        $this->lookup($other, '/search/national-id', ['national_id' => '2'])->assertStatus(422);
        $this->lookup($other, '/search/name', ['fullname' => 'محمد سالم'])->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
        $this->assertSame(0, $this->used($other));
    }

    public function test_quota_administration_records_every_change_and_reports_usage(): void
    {
        $super = $this->staff('SUPER_ADMIN');
        $admin = $this->staff('ADMINISTRATOR');
        $this->actingAs($admin)->putJson(self::BASE.'/admin/policies/DATA_ENTRY', ['enabled' => true, 'daily_limit' => 10])->assertStatus(403);
        $this->actingAs($super)->putJson(self::BASE.'/admin/policies/DATA_ENTRY', ['enabled' => true, 'daily_limit' => 0])->assertStatus(422);
        $this->actingAs($super)->putJson(self::BASE.'/admin/policies/DATA_ENTRY', ['enabled' => true, 'daily_limit' => null])->assertStatus(422);
        $this->actingAs($super)->putJson(self::BASE.'/admin/policies/SUPER_ADMIN', ['enabled' => true, 'daily_limit' => 10])->assertStatus(422);
        $this->actingAs($super)->putJson(self::BASE.'/admin/policies/HOUSEHOLD_HEAD', ['enabled' => true, 'daily_limit' => 5])->assertStatus(404);

        $this->actingAs($super)->putJson(self::BASE.'/admin/policies/DATA_ENTRY', ['enabled' => true, 'daily_limit' => 1])->assertOk()
            ->assertJsonPath('data.daily_limit', 1);
        $this->actingAs($super)->putJson(self::BASE.'/admin/policies/REVIEWER', ['enabled' => true, 'daily_limit' => 30])->assertOk();
        $this->actingAs($super)->putJson(self::BASE.'/admin/policies/REVIEWER', ['enabled' => true, 'daily_limit' => 30])->assertOk();
        $change = CivilLookupPolicyChange::where('role_name', 'DATA_ENTRY')->sole();
        $this->assertSame([true, true, 50, 1, $super->id], [$change->old_enabled, $change->new_enabled, $change->old_daily_limit, $change->new_daily_limit, $change->changed_by]);
        $this->assertSame(2, CivilLookupPolicyChange::count(), 'an unchanged policy writes no change');
        $this->lookup($this->staff('REVIEWER'), '/search/national-id', ['national_id' => '900000001'])->assertOk();

        $user = $this->staff('DATA_ENTRY');
        $this->lookup($user, '/search/national-id', ['national_id' => '900000001'])->assertOk();
        $usage = $this->actingAs($admin)->getJson(self::BASE.'/admin/usage')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.total_lookups', 2)->assertJsonPath('data.active_users', 2)
            ->assertJsonPath('data.exhausted_users.0.user_id', $user->id)->json('data.policies');
        $this->assertSame(['lookups' => 1, 'users' => 1, 'exhausted' => 1], collect($usage)->firstWhere('role', 'DATA_ENTRY')['today']);
        $this->assertSame(['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER', 'COORDINATOR', 'FAMILY_USER'],
            array_column($usage, 'role'));
        $this->actingAs($admin)->getJson(self::BASE.'/admin/changes')->assertStatus(403);
        $this->actingAs($super)->getJson(self::BASE.'/admin/changes')->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.role', 'REVIEWER')->assertJsonPath('data.1.old_daily_limit', 50);
        $this->actingAs($this->staff('DATA_ENTRY'))->getJson(self::BASE.'/admin/usage')->assertStatus(403);
    }

    public function test_super_admin_and_administrator_are_automatic_and_unlimited_but_still_individually_revocable(): void
    {
        // FB-CIVIL-02C (corrected, ADR §L-6a).
        $super = $this->staff('SUPER_ADMIN');
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR'] as $role) {
            $policy = CivilLookupRolePolicy::where('role_name', $role)->sole();
            $this->assertSame([true, null, true], [$policy->enabled, $policy->daily_limit, $policy->unlimited_allowed], $role);
            $this->actingAs($super)->putJson(self::BASE."/admin/policies/{$role}", ['enabled' => true, 'daily_limit' => 10])->assertStatus(422);
            $this->actingAs($super)->putJson(self::BASE."/admin/policies/{$role}", ['enabled' => false, 'daily_limit' => null])->assertStatus(422)
                ->assertJsonValidationErrors('enabled');
        }
        $this->assertSame(0, CivilLookupPolicyChange::count());
        // Other roles keep their 02A policies.
        $this->assertSame([true, 50], [CivilLookupRolePolicy::where('role_name', 'DATA_ENTRY')->value('enabled'),
            CivilLookupRolePolicy::where('role_name', 'DATA_ENTRY')->value('daily_limit')]);
        $this->assertFalse((bool) CivilLookupRolePolicy::where('role_name', 'REVIEWER')->value('enabled'));

        $admin = $this->staff('ADMINISTRATOR');
        for ($i = 0; $i < 3; $i++) {
            $this->lookup($admin, '/search/national-id', ['national_id' => '900000001'])->assertOk()->assertJsonPath('data.quota.unlimited', true);
        }
        $this->assertSame(3, $this->used($admin), 'unlimited lookups still count');
        // Automatic is not unconditional: the individual block and the gate still apply.
        $this->actingAs($super)->putJson(self::BASE.'/admin/user-access', ['email' => $admin->email, 'access' => 'REVOKED'])->assertOk();
        $this->lookup($admin, '/search/national-id', ['national_id' => '900000001'])->assertStatus(403);
        config(['civil_lookup.enabled' => false]);
        $this->lookup($super, '/search/national-id', ['national_id' => '900000001'])->assertStatus(503);
    }

    public function test_history_is_append_only_and_no_route_carries_an_identifier(): void
    {
        $this->lookup($this->staff('ADMINISTRATOR'), '/search/national-id', ['national_id' => '900000001'])->assertOk();
        $event = CivilLookupAuditEvent::sole();
        $this->assertThrows(fn () => $event->update(['outcome_code' => 'X']), LogicException::class);
        $this->assertThrows(fn () => $event->delete(), LogicException::class);

        $uris = collect(Route::getRoutes()->getRoutes())->map->uri()->filter(fn ($u) => str_starts_with($u, 'api/v1/civil-registry/lookup'))->values();
        // 13 + FB-CIVIL-02D: POST admin/user-access/resolve (identifier in the body) and GET admin/accounts/staff;
        // + FB-CIVIL-02G: POST identifiers/reveal (the reference in the body).
        $this->assertCount(16, $uris);
        $this->assertSame(['api/v1/civil-registry/lookup/admin/policies/{role}'], $uris->filter(fn ($u) => str_contains($u, '{'))->values()->all());
    }
}
