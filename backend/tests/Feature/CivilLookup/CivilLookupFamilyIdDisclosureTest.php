<?php

namespace Tests\Feature\CivilLookup;

use App\Models\CivilLookupAuditEvent;
use App\Models\CivilLookupRolePolicy;
use App\Models\CivilLookupUserAccess;
use App\Models\User;
use App\Support\CivilLookup\CivilLookupGate;
use App\Support\CivilLookup\CivilLookupIdentifierDisclosure;
use App\Support\CivilLookup\CivilRegistrySource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\CivilLookup\FakeCivilRegistry;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FB-CIVIL-02G — the eye-icon reveal of ONE complete National ID
 * (docs/adr/FB-CIVIL-02B-FAMILY-COORDINATOR-LOOKUP.md §B-5b): lists and
 * records stay masked; a reveal names one result card, reverse-list entry,
 * opened record or linked person by its reference, is authorized like an
 * opened record plus the side's reveal switch, costs one quota unit the
 * first time a record is disclosed that Gaza day, and is audited without
 * the value. SYNTHETIC data only.
 */
class CivilLookupFamilyIdDisclosureTest extends TestCase
{
    use CoordinatorFixtures;
    use FamilyIdentityFixtures;
    use RefreshDatabase;

    private const FAMILY = '/api/v1/family/civil-registry/lookup';

    private const COORDINATOR = '/api/v1/family/coordinator/civil-registry/lookup';

    private const STAFF = '/api/v1/civil-registry/lookup';

    private const IDS = ['900000001', '900000002', '900000003', '900000099', '910000000', '910000001'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        config(['civil_lookup.enabled' => true, 'civil_lookup.reverse_links_enabled' => true]);
        $registry = (new FakeCivilRegistry)
            ->add('900000001', ['fullname' => 'سامي خالد سالم', 'name1' => 'سامي', 'father' => 'خالد', 'family' => 'سالم',
                'fatherid' => '900000002', 'motherid' => '900000099', 'partnerid' => '900000003', 'householdid' => 'H-1'])
            ->add('900000002', ['fullname' => 'خالد سالم', 'name1' => 'خالد', 'family' => 'سالم', 'householdid' => 'H-1'])
            ->add('900000003', ['fullname' => 'ليلى سالم', 'name1' => 'ليلى', 'family' => 'سالم', 'householdid' => 'H-1'])
            ->add('910000000', ['fullname' => 'رامي خالد تجريبية', 'name1' => 'رامي', 'father' => 'خالد', 'family' => 'تجريبية'])
            ->add('910000001', ['fullname' => 'رامي خالد تجريبية', 'name1' => 'رامي', 'father' => 'خالد', 'family' => 'تجريبية']);
        $this->app->instance(CivilRegistrySource::class, $registry);
    }

    protected function tearDown(): void
    {
        foreach ([CivilLookupGate::VARIABLE, ...array_values(CivilLookupGate::SIDE_VARIABLES), CivilLookupGate::PROFILE_VARIABLE,
            ...array_values(CivilLookupIdentifierDisclosure::APPROVAL_VARIABLES)] as $variable) {
            putenv($variable);
        }
        parent::tearDown();
    }

    private function openSides(int $limit = 50): void
    {
        config(['civil_lookup.family_side_enabled' => true, 'civil_lookup.coordinator_side_enabled' => true]);
        $this->seed(RolePermissionSeeder::class);
        CivilLookupRolePolicy::whereIn('role_name', ['FAMILY_USER', 'COORDINATOR'])->update(['enabled' => true, 'daily_limit' => $limit]);
    }

    private function grantedHead(string $nationalId = '123456780'): User
    {
        $user = $this->activatedHead($nationalId)['user'];
        CivilLookupUserAccess::create(['user_id' => $user->id, 'access' => 'GRANTED', 'sides' => 'FAMILY', 'changed_by' => $user->id, 'changed_at' => now()]);

        return $user;
    }

    private function lookup(User $user, string $base, string $path, array $body = []): TestResponse
    {
        return $this->actingAs($user)->postJson($base.$path, $body);
    }

    private function reveal(User $user, string $base, ?string $ref): TestResponse
    {
        return $this->lookup($user, $base, '/identifiers/reveal', ['ref' => $ref]);
    }

    private function used(User $user): int
    {
        return (int) DB::table('civil_lookup_daily_usage')->where('user_id', $user->id)->sum('used');
    }

    private function assertNoCompleteId(TestResponse $response): void
    {
        foreach (self::IDS as $id) {
            $this->assertStringNotContainsString($id, $response->getContent());
        }
    }

    public function test_the_family_reveal_is_closed_by_default_and_lists_and_records_stay_masked(): void
    {
        $this->openSides();
        $head = $this->grantedHead();
        $this->actingAs($head)->getJson(self::FAMILY.'/status')->assertOk()->assertJsonPath('data.capabilities.reveal_ids', false);
        $list = $this->lookup($head, self::FAMILY, '/search/name', ['fullname' => 'سامي سالم'])->assertOk();
        $this->assertNoCompleteId($list);
        $refused = $this->reveal($head, self::FAMILY, $list->json('data.results.0.ref'))->assertStatus(403)->assertJsonPath('code', 'LOOKUP_NOT_ALLOWED');
        $this->assertNoCompleteId($refused);
        $this->assertSame('NOT_ALLOWED', CivilLookupAuditEvent::where('event_type', 'ID_REVEALED')->value('outcome_code'));
    }

    public function test_a_family_user_reveals_one_id_at_a_time_on_cards_the_record_its_related_persons_and_reverse_lists(): void
    {
        $this->openSides();
        config(['civil_lookup.family_id_reveal_enabled' => true]);
        $head = $this->grantedHead();
        $this->actingAs($head)->getJson(self::FAMILY.'/status')->assertOk()->assertJsonPath('data.capabilities.reveal_ids', true);

        // Search results stay masked in every mode: nothing is disclosed in bulk.
        $byName = $this->lookup($head, self::FAMILY, '/search/name', ['fullname' => 'سامي سالم'])->assertOk();
        $byParts = $this->lookup($head, self::FAMILY, '/search/parts', ['name1' => 'رامي', 'family' => 'تجريبية'])->assertOk();
        $this->assertNoCompleteId($byName);
        $this->assertNoCompleteId($byParts);
        $this->assertSame(2, $this->used($head));

        // 1. A result card: its one value, charged once; again free.
        $card = $byName->json('data.results.0.ref');
        $this->reveal($head, self::FAMILY, $card)->assertOk()->assertJsonPath('data.national_id', '900000001')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->reveal($head, self::FAMILY, $card)->assertOk();
        $this->assertSame(3, $this->used($head));

        // 2. The opened record stays masked; its own reveal is the same record — free today.
        $record = $this->lookup($head, self::FAMILY, '/records/open', ['ref' => $card])->assertOk()
            ->assertJsonPath('data.record.national_id', '*****0001');
        $this->assertNoCompleteId($record);
        $this->reveal($head, self::FAMILY, $record->json('data.record.ref'))->assertOk()->assertJsonPath('data.national_id', '900000001');
        $this->assertSame(3, $this->used($head));

        // 3. Related persons, each on its own: the spouse's reveal discloses the spouse only (a new record: charged).
        $links = collect($record->json('data.record.links'))->keyBy('relation');
        $this->assertSame(['*****0002', '*****0099', '*****0003'],
            [$links['FATHER']['national_id'], $links['MOTHER']['national_id'], $links['SPOUSE']['national_id']]);
        $spouse = $this->reveal($head, self::FAMILY, $links['SPOUSE']['ref'])->assertOk()->assertJsonPath('data.national_id', '900000003');
        $this->assertStringNotContainsString('900000002', $spouse->getContent());
        $this->assertSame(4, $this->used($head));
        // A person not in the release has no reference to reveal.
        $this->assertArrayNotHasKey('ref', $links['MOTHER']);

        // 4. A reverse (household) list: masked entries, each revealable.
        $household = $this->lookup($head, self::FAMILY, '/links/list', ['ref' => $links['HOUSEHOLD']['ref'], 'relation' => 'HOUSEHOLD'])->assertOk();
        $this->assertNoCompleteId($household);
        $entry = collect($household->json('data.results'))->firstWhere('national_id', '*****0002');
        $this->reveal($head, self::FAMILY, $entry['ref'])->assertOk()->assertJsonPath('data.national_id', '900000002');

        // 5. The detailed search's cards follow the same rule.
        $this->reveal($head, self::FAMILY, $byParts->json('data.results.1.ref'))->assertOk();
        $this->assertSame(4, DB::table('civil_lookup_record_disclosures')->where('user_id', $head->id)->count(), 'four distinct records revealed');
    }

    public function test_an_exact_id_search_reveals_its_record_free_and_an_exhausted_quota_reveals_nothing_new(): void
    {
        $this->openSides(limit: 2);
        config(['civil_lookup.family_id_reveal_enabled' => true]);
        $head = $this->grantedHead();
        $byId = $this->lookup($head, self::FAMILY, '/search/national-id', ['national_id' => '900000001'])->assertOk();
        $this->assertNoCompleteId($byId);
        $this->reveal($head, self::FAMILY, $byId->json('data.results.0.ref'))->assertOk()->assertJsonPath('data.national_id', '900000001');
        $this->assertSame(1, $this->used($head), 'the requester supplied that ID');

        $byName = $this->lookup($head, self::FAMILY, '/search/name', ['fullname' => 'رامي تجريبية'])->assertOk();
        $this->assertSame(2, $this->used($head));
        $refused = $this->reveal($head, self::FAMILY, $byName->json('data.results.0.ref'))->assertStatus(429)->assertJsonPath('code', 'QUOTA_EXHAUSTED');
        $this->assertNoCompleteId($refused);
        // Already revealed today: still free at the limit.
        $this->reveal($head, self::FAMILY, $byId->json('data.results.0.ref'))->assertOk();
    }

    public function test_references_cannot_move_between_users_or_contexts_and_a_revoked_user_reveals_nothing(): void
    {
        $this->openSides();
        config(['civil_lookup.family_id_reveal_enabled' => true]);
        $head = $this->grantedHead();
        $other = $this->grantedHead('123456781');
        $ref = $this->lookup($head, self::FAMILY, '/search/name', ['fullname' => 'سامي سالم'])->json('data.results.0.ref');

        $this->reveal($other, self::FAMILY, $ref)->assertStatus(422)->assertJsonPath('code', 'REFERENCE_INVALID');
        $this->reveal($head, self::FAMILY, 'not-a-reference')->assertStatus(422);
        $this->reveal($head, self::FAMILY, null)->assertStatus(422);

        CivilLookupUserAccess::query()->whereKey($head->id)->update(['access' => 'REVOKED', 'sides' => null]);
        $this->assertNoCompleteId($this->reveal($head, self::FAMILY, $ref)->assertStatus(403));
    }

    public function test_the_coordinator_reveal_has_its_own_switch_and_staff_keeps_the_02f_rules(): void
    {
        $this->openSides();
        config(['civil_lookup.family_id_reveal_enabled' => true]);
        $coordinator = $this->coordinator('131111110');
        $this->assign($coordinator, $this->clan());
        CivilLookupUserAccess::create(['user_id' => $coordinator->id, 'access' => 'GRANTED', 'sides' => 'FAMILY,COORDINATOR',
            'changed_by' => $coordinator->id, 'changed_at' => now()]);
        $coordRef = $this->lookup($coordinator, self::COORDINATOR, '/search/name', ['fullname' => 'سامي سالم'])->json('data.results.0.ref');
        $this->reveal($coordinator, self::COORDINATOR, $coordRef)->assertStatus(403);
        // A Family Portal reference never works in Coordinator Space, and the reverse.
        $familyRef = $this->lookup($coordinator, self::FAMILY, '/search/name', ['fullname' => 'سامي سالم'])->json('data.results.0.ref');
        $this->reveal($coordinator, self::COORDINATOR, $familyRef)->assertStatus(403);
        config(['civil_lookup.coordinator_id_reveal_enabled' => true]);
        $this->reveal($coordinator, self::COORDINATOR, $familyRef)->assertStatus(422)->assertJsonPath('code', 'REFERENCE_INVALID');
        $this->reveal($coordinator, self::COORDINATOR, $coordRef)->assertOk()->assertJsonPath('data.national_id', '900000001');

        // Staff: STANDARD reveals a card (one disclosure, shared with opening it); FULL is free.
        $standard = User::factory()->create();
        $standard->assignRole('DATA_ENTRY');
        $card = $this->lookup($standard, self::STAFF, '/search/name', ['fullname' => 'سامي سالم'])->json('data.results.0.ref');
        $this->reveal($standard, self::STAFF, $card)->assertOk()->assertJsonPath('data.national_id', '900000001');
        $links = collect($this->lookup($standard, self::STAFF, '/records/open', ['ref' => $card])->assertOk()
            ->assertJsonPath('data.record.national_id', '900000001')->json('data.record.links'))->keyBy('relation');
        $this->assertSame('*****0002', $links['FATHER']['national_id'], 'STANDARD links stay masked until revealed');
        $this->assertSame(2, $this->used($standard));
        $this->reveal($standard, self::STAFF, $links['FATHER']['ref'])->assertOk()->assertJsonPath('data.national_id', '900000002');
        $this->assertSame(3, $this->used($standard));

        $full = User::factory()->create();
        $full->assignRole('ADMINISTRATOR');
        $ref = $this->lookup($full, self::STAFF, '/search/name', ['fullname' => 'سامي سالم'])->json('data.results.0.ref');
        $this->reveal($full, self::STAFF, $ref)->assertOk()->assertJsonPath('data.national_id', '900000001');
        $this->assertSame([1, 0], [$this->used($full), DB::table('civil_lookup_record_disclosures')->where('user_id', $full->id)->count()]);
    }

    public function test_in_production_the_family_reveal_needs_its_own_approval_and_the_audit_keeps_no_identifier(): void
    {
        $this->openSides();
        config(['civil_lookup.family_id_reveal_enabled' => true]);
        $head = $this->grantedHead();
        $this->app['env'] = 'production';
        foreach ([CivilLookupGate::VARIABLE => 'APPROVAL-1', 'CIVIL_LOOKUP_FAMILY_APPROVAL' => 'FAMILY-1', CivilLookupGate::PROFILE_VARIABLE => 'PROFILE-1'] as $k => $v) {
            putenv("{$k}={$v}");
        }
        $ref = $this->lookup($head, self::FAMILY, '/search/name', ['fullname' => 'سامي سالم'])->assertOk()->json('data.results.0.ref');
        $this->reveal($head, self::FAMILY, $ref)->assertStatus(403);
        putenv(CivilLookupIdentifierDisclosure::APPROVAL_VARIABLES['FAMILY'].'=FAMILY-REVEAL-1');
        $this->reveal($head, self::FAMILY, $ref)->assertOk()->assertJsonPath('data.national_id', '900000001');

        $this->assertSame(['FOUND', 'NOT_ALLOWED'], CivilLookupAuditEvent::where('event_type', 'ID_REVEALED')->orderByDesc('id')->pluck('outcome_code')->all());
        foreach (CivilLookupAuditEvent::all() as $event) {
            $row = (string) json_encode($event->getAttributes());
            foreach (self::IDS as $id) {
                $this->assertStringNotContainsString($id, $row);
            }
        }
    }
}
