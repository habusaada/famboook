<?php

namespace Tests\Feature\FamilyPortal;

use App\Enums\AuthSecurityEventType;
use App\Enums\LifeStatus;
use App\Exceptions\HouseholdMemberUnavailableException;
use App\Http\Controllers\Api\V1\Family\FamilyMemberRevealController;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Models\AuthSecurityEvent;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Support\FamilyPortal\HouseholdMemberReference;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-3B.4: POST /api/v1/family/household/members/{memberRef}/reveal — ONE
 * full value of ANOTHER member of the signed-in head's household. The member
 * is resolved only inside the family.context Family (FU-13 member_ref);
 * every unavailable target is one generic 404; the head's own values stay on
 * the self path; one security event per authorized reveal (target Person,
 * field code only); no Family Activity; no-store; its own per-user throttle.
 * Synthetic data only.
 */
class FamilyMemberRevealTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const SPOUSE_NID = '807766554';

    private const SPOUSE_MOBILE = '0591122334';

    private const SPOUSE_ALTERNATE = '0563344556';

    private const UNAVAILABLE = ['message' => HouseholdMemberUnavailableException::MESSAGE, 'code' => HouseholdMemberUnavailableException::CODE];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
    }

    private function uri(string $memberRef): string
    {
        return '/api/v1/family/household/members/'.$memberRef.'/reveal';
    }

    private function reveal(User $as, string $memberRef, array $body, string $query = ''): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->postJson($this->uri($memberRef).($query === '' ? '' : '?'.$query), $body);
    }

    /** A household: an activated head, a spouse and a child. */
    private function household(string $nationalId = '123456789', array $roles = ['FAMILY_USER']): array
    {
        $head = $this->activatedHead($nationalId, $roles);
        $head['person']->forceFill(['mobile' => '0597766554', 'alternate_mobile' => '0568877665'])->save();
        $spouse = $this->member($head, [
            'national_id' => self::SPOUSE_NID, 'mobile' => self::SPOUSE_MOBILE, 'alternate_mobile' => self::SPOUSE_ALTERNATE,
            'alternate_mobile_owner_relation' => 'أخ',
        ]);
        $child = $this->member($head, ['national_id' => '406655443', 'mobile' => null, 'alternate_mobile' => null, 'birth_date' => '2015-05-05']);

        return [...$head, 'spouse' => $spouse, 'child' => $child];
    }

    private function member(array $head, array $person = []): FamilyMembership
    {
        return FamilyMembership::factory()->create([
            'family_id' => $head['family']->id, 'person_id' => Person::factory()->create($person)->id,
        ]);
    }

    private function ref(FamilyMembership $membership): string
    {
        return HouseholdMemberReference::of((int) $membership->family_id, (int) $membership->id);
    }

    private function events(): Collection
    {
        return AuthSecurityEvent::where('event_type', AuthSecurityEventType::HOUSEHOLD_MEMBER_SENSITIVE_REVEALED->value)->get();
    }

    private function assertNoStorePrivate(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    // ---------------------------------------------------------------- reveal

    /** @return array<string, array{0: string, 1: string, 2: list<string>}> */
    public static function spouseFields(): array
    {
        return [
            'national id' => ['NATIONAL_ID', self::SPOUSE_NID, [self::SPOUSE_MOBILE, self::SPOUSE_ALTERNATE]],
            'mobile' => ['MOBILE', self::SPOUSE_MOBILE, [self::SPOUSE_NID, self::SPOUSE_ALTERNATE]],
            'alternate mobile' => ['ALTERNATE_MOBILE', self::SPOUSE_ALTERNATE, [self::SPOUSE_NID, self::SPOUSE_MOBILE]],
        ];
    }

    /** @param list<string> $others */
    #[DataProvider('spouseFields')]
    public function test_the_head_reveals_exactly_the_requested_value_of_the_spouse(string $field, string $value, array $others): void
    {
        $h = $this->household();

        $response = $this->reveal($h['user'], $this->ref($h['spouse']), ['field' => $field])->assertOk();

        $response->assertExactJson(['data' => ['field' => $field, 'value' => $value]]);
        foreach ([...$others, '123456789', '0597766554'] as $other) {
            $this->assertStringNotContainsString($other, $response->getContent());
        }
        $this->assertNoStorePrivate($response);
    }

    #[DataProvider('spouseFields')]
    public function test_each_reveal_records_exactly_one_event_for_the_target_with_the_field_only(string $field, string $value): void
    {
        $h = $this->household();
        $ref = $this->ref($h['spouse']);

        $this->reveal($h['user'], $ref, ['field' => $field])->assertOk();

        $event = $this->events()->sole();
        $this->assertSame('SUCCESS', $event->outcome->value);
        $this->assertSame($h['spouse']->person_id, $event->person_id, 'The target Person.');
        $this->assertSame($h['user']->id, $event->user_id);
        $this->assertSame($h['user']->id, $event->actor_user_id);
        $this->assertSame($h['link']->id, $event->user_person_link_id);
        $this->assertSame(['field' => $field], $event->metadata);
        $row = (string) json_encode(DB::table('auth_security_events')->where('id', $event->id)->first());
        // The metadata is exactly the field code (asserted above): no membership id there either.
        foreach ([$value, $ref, $h['spouse']->person->person_code, '*****'] as $secret) {
            $this->assertStringNotContainsString($secret, $row, $secret);
        }
        $this->assertSame(0, FamilyActivity::count());
        $this->assertSame(0, AuthSecurityEvent::where('event_type', AuthSecurityEventType::SELF_SENSITIVE_REVEALED->value)->count());
    }

    public function test_a_childs_national_id_is_revealed_with_no_adult_or_minor_rule(): void
    {
        $h = $this->household();

        $this->reveal($h['user'], $this->ref($h['child']), ['field' => 'NATIONAL_ID'])
            ->assertOk()->assertExactJson(['data' => ['field' => 'NATIONAL_ID', 'value' => '406655443']]);
    }

    public function test_a_null_value_is_200_with_null_and_still_exactly_one_event(): void
    {
        $h = $this->household();

        $this->reveal($h['user'], $this->ref($h['child']), ['field' => 'MOBILE'])
            ->assertOk()->assertExactJson(['data' => ['field' => 'MOBILE', 'value' => null]]);

        $this->assertSame([['field' => 'MOBILE']], $this->events()->pluck('metadata')->all());
        $this->assertSame($h['child']->person_id, $this->events()->sole()->person_id);
    }

    public function test_an_inactive_person_record_is_still_revealable(): void
    {
        $h = $this->household();
        $h['spouse']->person->forceFill(['is_active' => false, 'life_status' => LifeStatus::DECEASED->value])->save();

        $this->reveal($h['user'], $this->ref($h['spouse']), ['field' => 'NATIONAL_ID'])->assertOk()->assertJsonPath('data.value', self::SPOUSE_NID);
    }

    public function test_the_masked_views_stay_masked_after_a_reveal(): void
    {
        $h = $this->household();
        foreach (['NATIONAL_ID', 'MOBILE', 'ALTERNATE_MOBILE'] as $field) {
            $this->reveal($h['user'], $this->ref($h['spouse']), ['field' => $field])->assertOk();
        }

        $this->app['auth']->forgetGuards();
        $members = $this->actingAs($h['user']->fresh())->getJson('/api/v1/family/household/members')->assertOk();
        $self = $this->actingAs($h['user']->fresh())->getJson('/api/v1/family/self')->assertOk();
        foreach ([$members, $self] as $response) {
            foreach ([self::SPOUSE_NID, self::SPOUSE_MOBILE, self::SPOUSE_ALTERNATE, '123456789', '0597766554'] as $full) {
                $this->assertStringNotContainsString($full, $response->getContent(), $full);
            }
        }
        $row = collect($members->json('data.members'))->firstWhere('member_ref', $this->ref($h['spouse']));
        $this->assertSame('*****6554', $row['national_id_masked']);
    }

    // ------------------------------------------------------- generic failure

    public function test_every_unavailable_target_gets_the_identical_404_and_no_event(): void
    {
        $h = $this->household('111111111');
        $other = $this->household('222222222');
        $ended = $this->member($h, ['national_id' => '305544332']);
        $endedRef = $this->ref($ended);
        $ended->forceFill(['is_active' => false, 'ended_at' => now()->toDateString(), 'end_reason' => 'synthetic'])->save();
        $deleted = $this->member($h, ['national_id' => '304433221']);
        $deleted->person->delete();

        $targets = [
            'malformed' => 'abc',
            'uppercase' => strtoupper($this->ref($h['spouse'])),
            'random' => str_repeat('0123456789abcdef', 4),
            'foreign member' => $this->ref($other['spouse']),
            'foreign head' => $this->ref($other['membership']),
            'foreign member under my family id' => HouseholdMemberReference::of($h['family']->id, $other['spouse']->id),
            'ended membership' => $endedRef,
            'soft-deleted person' => $this->ref($deleted),
            'the head themself' => $this->ref($h['membership']),
        ];

        $bodies = [];
        foreach ($targets as $label => $ref) {
            $response = $this->reveal($h['user'], $ref, ['field' => 'NATIONAL_ID'])->assertNotFound()->assertExactJson(self::UNAVAILABLE);
            $this->assertNoStorePrivate($response);
            $bodies[$label] = $response->getContent();
            foreach (['111111111', '222222222', self::SPOUSE_NID, '305544332', '304433221'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent(), $label);
            }
        }
        $this->assertCount(1, array_unique($bodies), 'One identical answer, whatever the reason.');
        $this->assertCount(0, $this->events());
        $this->assertSame(0, AuthSecurityEvent::count());
    }

    public function test_coordinator_scope_never_widens_the_reveal(): void
    {
        $h = $this->household('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $clan = $this->clan();
        $assigned = $this->familyIn($clan, $this->branch($clan), members: 3);
        $this->assign($h['user'], $clan);

        foreach (FamilyMembership::where('family_id', $assigned->id)->get() as $membership) {
            $this->reveal($h['user'], $this->ref($membership), ['field' => 'NATIONAL_ID'])->assertNotFound()->assertExactJson(self::UNAVAILABLE);
        }
        $this->reveal($h['user'], $this->ref($h['spouse']), ['field' => 'NATIONAL_ID'])->assertOk();
        $this->assertCount(1, $this->events());
    }

    // ------------------------------------------------------------------ input

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidBodies(): array
    {
        return [
            'missing field' => [[], 'field'],
            'unknown field' => [['field' => 'PASSWORD'], 'field'],
            'a column name' => [['field' => 'national_id'], 'field'],
            'member_ref in the body' => [['field' => 'NATIONAL_ID', 'member_ref' => 'x'], 'member_ref'],
            'person id' => [['field' => 'NATIONAL_ID', 'person_id' => 1], 'person_id'],
            'person code' => [['field' => 'NATIONAL_ID', 'person_code' => 'PER-000002'], 'person_code'],
            'family id' => [['field' => 'NATIONAL_ID', 'family_id' => 1], 'family_id'],
            'family code' => [['field' => 'NATIONAL_ID', 'family_code' => 'FAM-000002'], 'family_code'],
            'user id' => [['field' => 'NATIONAL_ID', 'user_id' => 1], 'user_id'],
            'membership id' => [['field' => 'NATIONAL_ID', 'membership_id' => 1], 'membership_id'],
            'a national id' => [['field' => 'NATIONAL_ID', 'national_id' => '222222222'], 'national_id'],
            'a mobile' => [['field' => 'NATIONAL_ID', 'mobile' => '0599999999'], 'mobile'],
            'a value' => [['field' => 'NATIONAL_ID', 'value' => 'x'], 'value'],
            'a target' => [['field' => 'NATIONAL_ID', 'target' => 'x'], 'target'],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function test_invalid_or_targeted_bodies_are_refused_and_reveal_nothing(array $body, string $error): void
    {
        $h = $this->household();

        $response = $this->reveal($h['user'], $this->ref($h['spouse']), $body)->assertUnprocessable()->assertJsonValidationErrors($error);

        foreach ([self::SPOUSE_NID, self::SPOUSE_MOBILE, self::SPOUSE_ALTERNATE] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertCount(0, $this->events());
    }

    public function test_target_identifiers_in_the_query_string_are_refused_too(): void
    {
        $h = $this->household('111111111');
        $other = $this->household('222222222');

        foreach (['family_id' => $other['family']->id, 'family_code' => $other['family']->family_code, 'person_id' => $other['spouse']->person_id, 'membership_id' => $other['spouse']->id] as $key => $value) {
            $this->reveal($h['user'], $this->ref($h['spouse']), ['field' => 'NATIONAL_ID'], http_build_query([$key => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertCount(0, $this->events());
    }

    public function test_the_route_has_only_the_member_reference_and_the_whole_boundary(): void
    {
        $route = Route::getRoutes()->getByAction(FamilyMemberRevealController::class.'@reveal');

        $this->assertSame('api/v1/family/household/members/{memberRef}/reveal', $route->uri());
        $this->assertSame(['memberRef'], $route->parameterNames());
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(['api', 'auth:sanctum', 'family.side', 'can:family-portal.access', 'family.context', 'throttle:family-member-reveal'], $route->gatherMiddleware());
    }

    // -------------------------------------------------------------- boundary

    public function test_a_guest_gets_the_json_401(): void
    {
        $this->postJson($this->uri(str_repeat('a', 64)), ['field' => 'NATIONAL_ID'])->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertSame(0, AuthSecurityEvent::count());
    }

    public function test_staff_coordinator_only_and_mixed_accounts_are_refused_by_family_side(): void
    {
        $h = $this->household('111111111');
        $ref = $this->ref($h['spouse']);
        $accounts = ['coordinator only' => $this->familyUser(['COORDINATOR']), 'role-less' => User::factory()->create()];
        foreach (StaffRoles::ALL as $role) {
            $accounts[$role] = tap(User::factory()->create(), fn (User $u) => $u->assignRole($role));
        }
        $mixed = $this->household('222222222')['user'];
        $mixed->assignRole('ADMINISTRATOR');
        $accounts['mixed staff and family'] = $mixed;

        foreach ($accounts as $label => $user) {
            $response = $this->reveal($user, $ref, ['field' => 'NATIONAL_ID'])->assertForbidden()->assertExactJson(['message' => EnsureFamilySideAccount::MESSAGE]);
            $this->assertStringNotContainsString(self::SPOUSE_NID, $response->getContent(), $label);
        }
        $this->assertCount(0, $this->events());
    }

    public function test_a_lost_family_context_is_refused_before_any_resolution(): void
    {
        $h = $this->household();
        $h['person']->forceFill(['life_status' => LifeStatus::DECEASED->value])->save();

        $this->reveal($h['user'], $this->ref($h['spouse']), ['field' => 'NATIONAL_ID'])
            ->assertForbidden()->assertExactJson(['message' => EnsureFamilyContext::MESSAGE, 'code' => EnsureFamilyContext::CODE]);
        $this->assertCount(0, $this->events());
    }

    // ------------------------------------------------------------- throttle

    public function test_the_minute_ceiling_is_per_user_and_changing_the_member_does_not_reset_it(): void
    {
        config(['family_auth.member_reveal.limits.user_minute' => 3]);
        $h = $this->household('111111111');
        $other = $this->household('222222222');

        $this->reveal($h['user'], $this->ref($h['spouse']), ['field' => 'NATIONAL_ID'])->assertOk();
        $this->reveal($h['user'], $this->ref($h['child']), ['field' => 'NATIONAL_ID'])->assertOk();
        // A probe with another (random) reference also counts.
        $this->reveal($h['user'], str_repeat('ab', 32), ['field' => 'NATIONAL_ID'])->assertNotFound();
        $response = $this->reveal($h['user'], $this->ref($h['spouse']), ['field' => 'MOBILE'])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString(self::SPOUSE_MOBILE, $response->getContent());
        $this->assertCount(2, $this->events());
        // Another head is not affected.
        $this->reveal($other['user'], $this->ref($other['spouse']), ['field' => 'NATIONAL_ID'])->assertOk();
    }

    public function test_the_hour_ceiling_applies(): void
    {
        config(['family_auth.member_reveal.limits.user_minute' => 100, 'family_auth.member_reveal.limits.user_hour' => 2]);
        $h = $this->household();

        $this->reveal($h['user'], $this->ref($h['spouse']), ['field' => 'NATIONAL_ID'])->assertOk();
        $this->reveal($h['user'], $this->ref($h['spouse']), ['field' => 'MOBILE'])->assertOk();
        $this->reveal($h['user'], $this->ref($h['child']), ['field' => 'NATIONAL_ID'])->assertStatus(429);
    }

    public function test_the_self_and_member_ceilings_are_independent(): void
    {
        config(['family_auth.member_reveal.limits.user_minute' => 1, 'family_auth.self_reveal.limits.user_minute' => 1]);
        $h = $this->household();

        $this->reveal($h['user'], $this->ref($h['spouse']), ['field' => 'NATIONAL_ID'])->assertOk();
        $this->reveal($h['user'], $this->ref($h['spouse']), ['field' => 'MOBILE'])->assertStatus(429);

        // The member ceiling being reached does not block the self reveal …
        $this->app['auth']->forgetGuards();
        $this->actingAs($h['user']->fresh())->postJson('/api/v1/family/self/reveal', ['field' => 'NATIONAL_ID'])->assertOk();
        // … and the self ceiling being reached does not touch the member bucket.
        $this->actingAs($h['user']->fresh())->postJson('/api/v1/family/self/reveal', ['field' => 'MOBILE'])->assertStatus(429);
        $this->assertCount(1, $this->events());
    }
}
