<?php

namespace Tests\Feature\CivilLookup;

use App\Models\CivilLookupAuditEvent;
use App\Models\CivilLookupRolePolicy;
use App\Models\User;
use App\Support\CivilLookup\CivilLookupQuota;
use App\Support\CivilLookup\CivilRegistrySource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\CivilLookup\FakeCivilRegistry;
use Tests\TestCase;

/**
 * FB-CIVIL-02F — full National ID disclosure in an opened record
 * (docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md §L-4b, §L-6c): STANDARD shows
 * the opened record's own ID and its first disclosure each Gaza day costs one
 * quota unit; FULL is unchanged; lists and linked IDs stay masked; an exact
 * National ID search opens its record without a second charge. The
 * PostgreSQL concurrency guarantees: CivilLookupPostgresTest. SYNTHETIC data
 * only (900000xxx / 910000xxx identifiers, placeholder names).
 */
class CivilLookupDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/civil-registry/lookup';

    private const CROWD = ['910000000', '910000001', '910000002'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['civil_lookup.enabled' => true, 'family_auth.fingerprint' => [
            'key' => 'test-only-civil-lookup-fingerprint-key-0123456789', 'key_version' => 1, 'previous_key' => null, 'previous_key_version' => null,
        ]]);
        $registry = (new FakeCivilRegistry)
            ->add('900000001', ['fullname' => 'سامي خالد سالم', 'name1' => 'سامي', 'father' => 'خالد', 'family' => 'سالم', 'fatherid' => '900000002'])
            ->add('900000002', ['fullname' => 'خالد سالم', 'name1' => 'خالد', 'family' => 'سالم']);
        foreach (self::CROWD as $id) {
            $registry->add($id, ['fullname' => 'سامي خالد تجريبية', 'name1' => 'سامي', 'father' => 'خالد', 'family' => 'تجريبية']);
        }
        $this->app->instance(CivilRegistrySource::class, $registry);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
        return (int) DB::table('civil_lookup_daily_usage')->where('user_id', $user->id)->where('usage_date', CivilLookupQuota::today())->value('used');
    }

    private function disclosures(User $user): int
    {
        return DB::table('civil_lookup_record_disclosures')->where('user_id', $user->id)->count();
    }

    /** A counted name search for the three crowd records; their references by masked ID. @return array<string, string> */
    private function crowdRefs(User $user): array
    {
        $response = $this->lookup($user, '/search/name', ['fullname' => 'سامي تجريبية'])->assertOk()->assertJsonPath('data.meta.total', 3);
        foreach (self::CROWD as $id) {
            $this->assertStringNotContainsString($id, $response->getContent(), 'a result list is always masked');
        }

        return collect($response->json('data.results'))->pluck('ref', 'national_id')->all();
    }

    public function test_standard_shows_the_opened_record_id_and_charges_its_first_disclosure_once_a_day(): void
    {
        $user = $this->staff('DATA_ENTRY');
        $refs = $this->crowdRefs($user);
        $this->assertSame(1, $this->used($user));

        $this->lookup($user, '/records/open', ['ref' => $refs['*****0000']])->assertOk()
            ->assertJsonPath('data.record.level', 'STANDARD')->assertJsonPath('data.record.national_id', '910000000')
            ->assertJsonPath('data.quota.used', 2);
        // Reopening the same record — by the same reference or by another reference to it — is free.
        $this->lookup($user, '/records/open', ['ref' => $refs['*****0000']])->assertOk()->assertJsonPath('data.record.national_id', '910000000');
        $other = $this->crowdRefs($user);
        $this->assertSame(3, $this->used($user), 'a new search is counted as before');
        $this->assertNotSame($refs['*****0000'], $other['*****0000'], 'a different reference to the same record');
        $this->lookup($user, '/records/open', ['ref' => $other['*****0000']])->assertOk();
        $this->assertSame(3, $this->used($user));
        // A different record is a separate disclosure.
        $this->lookup($user, '/records/open', ['ref' => $refs['*****0001']])->assertOk()->assertJsonPath('data.record.national_id', '910000001');
        $this->assertSame([4, 2], [$this->used($user), $this->disclosures($user)]);

        // The next Gaza-local day starts a new deduplication (and a new counter).
        Carbon::setTestNow(CivilLookupQuota::now()->addDay()->startOfDay()->addMinutes(5));
        $refs = $this->crowdRefs($user);
        $this->lookup($user, '/records/open', ['ref' => $refs['*****0000']])->assertOk();
        $this->assertSame(2, $this->used($user), 'one search and one new disclosure on the new day');
    }

    public function test_full_is_unchanged_and_lists_and_linked_ids_stay_masked_at_standard(): void
    {
        $full = $this->staff('ADMINISTRATOR');
        $refs = $this->crowdRefs($full);
        $this->lookup($full, '/records/open', ['ref' => $refs['*****0000']])->assertOk()
            ->assertJsonPath('data.record.level', 'FULL')->assertJsonPath('data.record.national_id', '910000000');
        $this->assertSame([1, 0], [$this->used($full), $this->disclosures($full)], 'FULL: no disclosure charge, no row');

        $standard = $this->staff('DATA_ENTRY');
        $ref = $this->lookup($standard, '/search/name', ['fullname' => 'سامي سالم'])->json('data.results.0.ref');
        $record = $this->lookup($standard, '/records/open', ['ref' => $ref])->assertOk()->assertJsonPath('data.record.national_id', '900000001');
        $fields = collect($record->json('data.record.sections'))->flatMap(fn ($s) => $s['fields'])->pluck('value', 'key');
        $this->assertSame('900000001', $fields['mainid']);
        $links = collect($record->json('data.record.links'))->keyBy('relation');
        $this->assertSame('*****0002', $links['FATHER']['national_id'], 'a linked ID stays masked at STANDARD');
        $this->assertStringNotContainsString('900000002', $record->getContent());

        // Opening the linked person is the charged link lookup; its own record then shows its own ID, and that is the
        // disclosure — reopening it today is free.
        $linked = $this->lookup($standard, '/links/open', ['ref' => $links['FATHER']['ref']])->assertOk()
            ->assertJsonPath('data.record.national_id', '900000002');
        $this->assertSame([3, 2], [$this->used($standard), $this->disclosures($standard)]);
        $this->lookup($standard, '/records/open', ['ref' => $linked->json('data.record.ref')])->assertOk();
        $this->assertSame(3, $this->used($standard));
    }

    public function test_an_exact_id_search_opens_its_record_without_a_second_charge_and_the_exemption_is_bound_to_it(): void
    {
        $user = $this->staff('DATA_ENTRY');
        $ref = $this->lookup($user, '/search/national-id', ['national_id' => '910000000'])->assertOk()
            ->assertJsonPath('data.results.0.national_id', '*****0000')->json('data.results.0.ref');
        $this->lookup($user, '/records/open', ['ref' => $ref])->assertOk()->assertJsonPath('data.record.national_id', '910000000');
        $this->lookup($user, '/records/open', ['ref' => $ref])->assertOk();
        $this->assertSame([1, 1], [$this->used($user), $this->disclosures($user)], 'the exact-ID search only; the disclosure is marked, not charged');

        // The exemption belongs to that record only: the others from a name search are charged; the marked one is free.
        $refs = $this->crowdRefs($user);
        $this->lookup($user, '/records/open', ['ref' => $refs['*****0000']])->assertOk();
        $this->assertSame(2, $this->used($user));
        $this->lookup($user, '/records/open', ['ref' => $refs['*****0002']])->assertOk();
        $this->assertSame(3, $this->used($user));
        // A reference is bound to its user: another user cannot reuse it.
        $this->lookup($this->staff('DATA_ENTRY'), '/records/open', ['ref' => $ref])->assertStatus(422)->assertJsonPath('code', 'REFERENCE_INVALID');

        // The exemption flag and its record id travel together in the authenticated encryption: only the exact-ID
        // reference carries it, and any alteration of a reference is refused (nothing disclosed, nothing charged).
        $payload = fn (string $r) => json_decode(Crypt::decryptString($r), true)['d'];
        $this->assertSame(['i' => $payload($ref)['i'], 'e' => 1], $payload($ref));
        $this->assertArrayNotHasKey('e', $payload($refs['*****0001']));
        $tampered = substr($ref, 0, -6).strrev(substr($ref, -6));
        $this->lookup($user, '/records/open', ['ref' => $tampered])->assertStatus(422)->assertJsonPath('code', 'REFERENCE_INVALID');
        $this->assertSame([3, 2], [$this->used($user), $this->disclosures($user)], 'two searches + one charged disclosure; two records disclosed');
    }

    public function test_an_exhausted_quota_withholds_a_new_disclosure_and_marks_nothing(): void
    {
        CivilLookupRolePolicy::where('role_name', 'DATA_ENTRY')->update(['daily_limit' => 2]);
        $user = $this->staff('DATA_ENTRY');
        $refs = $this->crowdRefs($user);
        $this->lookup($user, '/records/open', ['ref' => $refs['*****0000']])->assertOk();

        $refused = $this->lookup($user, '/records/open', ['ref' => $refs['*****0001']])->assertStatus(429)->assertJsonPath('code', 'QUOTA_EXHAUSTED');
        $this->assertStringNotContainsString('910000001', $refused->getContent());
        $this->assertSame([2, 1], [$this->used($user), $this->disclosures($user)], 'the refused disclosure left no row');
        $this->assertSame('QUOTA_EXHAUSTED', CivilLookupAuditEvent::where('event_type', 'RECORD_OPENED')->latest('id')->value('outcome_code'));
        // What was already disclosed today can still be reopened at the limit, free — also by another reference.
        $this->lookup($user, '/records/open', ['ref' => $refs['*****0000']])->assertOk()->assertJsonPath('data.record.national_id', '910000000');
        $this->assertSame([2, 1], [$this->used($user), $this->disclosures($user)]);
        $this->lookup($user, '/records/open', ['ref' => $refs['*****0001']])->assertStatus(429);
        $this->assertSame(2, $this->used($user));
    }

    public function test_at_the_limit_a_disclosed_record_reopens_free_by_a_different_reference_and_a_new_one_stays_blocked(): void
    {
        CivilLookupRolePolicy::where('role_name', 'DATA_ENTRY')->update(['daily_limit' => 3]);
        $user = $this->staff('DATA_ENTRY');
        $first = $this->crowdRefs($user);
        $detailRef = $this->lookup($user, '/records/open', ['ref' => $first['*****0000']])->assertOk()->json('data.record.ref');
        $second = $this->crowdRefs($user);
        $this->assertSame([3, 1], [$this->used($user), $this->disclosures($user)], 'two searches and one disclosure: the quota is exhausted');

        // Two other valid RECORD references to the disclosed record: a later search's and the record's own.
        foreach ([$second['*****0000'], $detailRef] as $other) {
            $this->assertNotSame($first['*****0000'], $other);
            $this->lookup($user, '/records/open', ['ref' => $other])->assertOk()->assertJsonPath('data.record.national_id', '910000000');
        }
        $this->assertSame([3, 1], [$this->used($user), $this->disclosures($user)], 'reopening consumed nothing');

        // A record never disclosed today stays blocked, by any of its references.
        foreach ([$first['*****0001'], $second['*****0001']] as $blocked) {
            $refused = $this->lookup($user, '/records/open', ['ref' => $blocked])->assertStatus(429)->assertJsonPath('code', 'QUOTA_EXHAUSTED');
            $this->assertStringNotContainsString('910000001', $refused->getContent());
        }
        $this->assertSame([3, 1], [$this->used($user), $this->disclosures($user)]);
    }

    public function test_a_revoked_or_unauthorized_requester_discloses_nothing_and_the_audit_keeps_no_national_id(): void
    {
        $super = $this->staff('SUPER_ADMIN');
        $user = $this->staff('DATA_ENTRY');
        $refs = $this->crowdRefs($user);
        $this->lookup($user, '/records/open', ['ref' => $refs['*****0000']])->assertOk();

        $this->actingAs($super)->putJson(self::BASE.'/admin/user-access', ['email' => $user->email, 'access' => 'REVOKED'])->assertOk();
        $refused = $this->lookup($user, '/records/open', ['ref' => $refs['*****0001']])->assertStatus(403);
        $this->assertStringNotContainsString('910000001', $refused->getContent());
        $this->assertSame([2, 1], [$this->used($user), $this->disclosures($user)]);
        // Without civil-lookup.view (REPORTS_VIEWER holds no lookup permission) nothing is reachable at all.
        $this->lookup($this->staff('REPORTS_VIEWER'), '/records/open', ['ref' => $refs['*****0001']])->assertStatus(403);

        foreach (CivilLookupAuditEvent::all() as $event) {
            $row = (string) json_encode($event->getAttributes());
            foreach ([...self::CROWD, '900000001', '900000002'] as $id) {
                $this->assertStringNotContainsString($id, $row, 'the audit never keeps a National ID');
            }
        }
    }
}
