<?php

namespace Tests\Feature\FamilyPortal;

use App\Actions\RecordPersonDeathAction;
use App\Enums\AuthIdentityStatus;
use App\Enums\AuthSecurityEventType;
use App\Enums\FamilyStatus;
use App\Enums\LifeStatus;
use App\Enums\LifeStatusVerificationMethod;
use App\Enums\UserPersonLinkStatus;
use App\Http\Controllers\Api\V1\Family\FamilySelfController;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Models\AuthSecurityEvent;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\User;
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
 * PWA-3B.2: POST /api/v1/family/self/reveal — ONE full value of the signed-in
 * household head's OWN Person, for one field code. The Person comes only
 * from the family.context boundary; any target or value in the request is
 * refused; every authorized reveal records one security event with the
 * field code and never the value; no Family Activity; no-store; throttled.
 * Synthetic data only.
 */
class FamilySelfRevealTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/self/reveal';

    private const NATIONAL_ID = '123456789';

    private const MOBILE = '0591234567';

    private const ALTERNATE = '0567654321';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
    }

    private function reveal(User $as, array $body, string $uri = self::URI): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->postJson($uri, $body);
    }

    private function ownHead(string $nationalId = self::NATIONAL_ID, array $roles = ['FAMILY_USER']): array
    {
        $head = $this->activatedHead($nationalId, $roles);
        $head['person']->forceFill(['mobile' => self::MOBILE, 'alternate_mobile' => self::ALTERNATE, 'alternate_mobile_owner_relation' => 'أخ'])->save();

        return $head;
    }

    private function events(): Collection
    {
        return AuthSecurityEvent::where('event_type', AuthSecurityEventType::SELF_SENSITIVE_REVEALED->value)->get();
    }

    // ------------------------------------------------------------- reveal

    /** @return array<string, array{0: string, 1: string, 2: list<string>}> */
    public static function fields(): array
    {
        return [
            'national id' => ['NATIONAL_ID', self::NATIONAL_ID, [self::MOBILE, self::ALTERNATE]],
            'mobile' => ['MOBILE', self::MOBILE, [self::NATIONAL_ID, self::ALTERNATE]],
            'alternate mobile' => ['ALTERNATE_MOBILE', self::ALTERNATE, [self::NATIONAL_ID, self::MOBILE]],
        ];
    }

    /** @param list<string> $others */
    #[DataProvider('fields')]
    public function test_the_head_reveals_exactly_the_requested_own_value(string $field, string $value, array $others): void
    {
        $head = $this->ownHead();

        $response = $this->reveal($head['user'], ['field' => $field])->assertOk();

        $response->assertExactJson(['data' => ['field' => $field, 'value' => $value]]);
        foreach ($others as $other) {
            $this->assertStringNotContainsString($other, $response->getContent());
        }
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    #[DataProvider('fields')]
    public function test_each_reveal_records_one_security_event_with_the_field_code_and_never_the_value(string $field, string $value): void
    {
        $head = $this->ownHead();

        $this->reveal($head['user'], ['field' => $field])->assertOk();

        $event = $this->events()->sole();
        $this->assertSame('SUCCESS', $event->outcome->value);
        $this->assertSame(['field' => $field], $event->metadata);
        $this->assertSame($head['person']->id, $event->person_id);
        $this->assertSame($head['user']->id, $event->user_id);
        $this->assertSame($head['user']->id, $event->actor_user_id);
        $this->assertSame($head['link']->id, $event->user_person_link_id);
        $this->assertNull($event->login_key);
        $row = json_encode(DB::table('auth_security_events')->where('id', $event->id)->first());
        foreach ([self::NATIONAL_ID, self::MOBILE, self::ALTERNATE] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $row, $secret);
        }
        // A reveal changes no registry data: no Family Activity.
        $this->assertSame(0, FamilyActivity::count());
    }

    public function test_a_null_value_is_returned_as_null_and_still_recorded(): void
    {
        $head = $this->ownHead();
        $head['person']->forceFill(['mobile' => null, 'alternate_mobile' => null, 'alternate_mobile_owner_relation' => null])->save();

        $this->reveal($head['user'], ['field' => 'MOBILE'])->assertOk()->assertExactJson(['data' => ['field' => 'MOBILE', 'value' => null]]);
        $this->reveal($head['user'], ['field' => 'ALTERNATE_MOBILE'])->assertOk()->assertExactJson(['data' => ['field' => 'ALTERNATE_MOBILE', 'value' => null]]);

        // An authorized reveal request is recorded whether or not a value is stored.
        $this->assertSame([['field' => 'MOBILE'], ['field' => 'ALTERNATE_MOBILE']], $this->events()->pluck('metadata')->all());
    }

    public function test_the_masked_views_stay_masked_after_a_reveal(): void
    {
        $head = $this->ownHead();
        $this->reveal($head['user'], ['field' => 'NATIONAL_ID'])->assertOk();

        $this->app['auth']->forgetGuards();
        $self = $this->actingAs($head['user']->fresh())->getJson('/api/v1/family/self')->assertOk();
        $self->assertJsonPath('data.national_id_masked', '*****6789')->assertJsonPath('data.mobile_masked', '05*****567');
        $me = $this->actingAs($head['user']->fresh())->getJson('/api/v1/family/me')->assertOk();
        foreach ([$self, $me] as $response) {
            foreach ([self::NATIONAL_ID, self::MOBILE, self::ALTERNATE] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent(), $secret);
            }
        }
    }

    // ---------------------------------------------------- input and SELF

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidBodies(): array
    {
        return [
            'missing field' => [[], 'field'],
            'unknown field' => [['field' => 'PASSWORD'], 'field'],
            'a column name' => [['field' => 'national_id'], 'field'],
            'another column' => [['field' => 'notes'], 'field'],
            'person id' => [['field' => 'NATIONAL_ID', 'person_id' => 1], 'person_id'],
            'person code' => [['field' => 'NATIONAL_ID', 'person_code' => 'PER-000002'], 'person_code'],
            'family id' => [['field' => 'NATIONAL_ID', 'family_id' => 1], 'family_id'],
            'family code' => [['field' => 'NATIONAL_ID', 'family_code' => 'FAM-000002'], 'family_code'],
            'user id' => [['field' => 'NATIONAL_ID', 'user_id' => 1], 'user_id'],
            'membership id' => [['field' => 'NATIONAL_ID', 'membership_id' => 1], 'membership_id'],
            'a national id' => [['field' => 'NATIONAL_ID', 'national_id' => '222222222'], 'national_id'],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function test_invalid_or_targeted_requests_are_refused_and_reveal_nothing(array $body, string $error): void
    {
        $head = $this->ownHead();

        $response = $this->reveal($head['user'], $body)->assertUnprocessable()->assertJsonValidationErrors($error);

        foreach ([self::NATIONAL_ID, self::MOBILE, self::ALTERNATE] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent(), $secret);
        }
        $this->assertCount(0, $this->events());
    }

    public function test_spoofed_query_parameters_cannot_retarget_the_reveal(): void
    {
        $mine = $this->ownHead('111111111');
        $other = $this->ownHead('222222222');
        $query = http_build_query(['person_id' => $other['person']->id, 'person_code' => $other['person']->person_code, 'family_code' => $other['family']->family_code]);

        $this->reveal($mine['user'], ['field' => 'NATIONAL_ID'], self::URI.'?'.$query)->assertUnprocessable();
        $this->reveal($mine['user'], ['field' => 'NATIONAL_ID'], self::URI.'?other='.$other['person']->id)
            ->assertOk()->assertExactJson(['data' => ['field' => 'NATIONAL_ID', 'value' => '111111111']]);
    }

    public function test_the_route_has_no_target_parameter_and_the_whole_boundary(): void
    {
        $route = Route::getRoutes()->getByAction(FamilySelfController::class.'@reveal');

        $this->assertSame('api/v1/family/self/reveal', $route->uri());
        $this->assertSame([], $route->parameterNames());
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(['api', 'auth:sanctum', 'family.side', 'can:family-portal.access', 'family.context', 'throttle:family-self-reveal'], $route->gatherMiddleware());
    }

    public function test_coordinator_scope_never_reveals_another_person(): void
    {
        $head = $this->ownHead('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $clan = $this->clan();
        $assigned = $this->familyIn($clan, $this->branch($clan), members: 2);
        $this->assign($head['user'], $clan);
        $assignedHead = FamilyMembership::where('family_id', $assigned->id)->where('is_household_head', true)->sole()->person;
        $assignedHead->forceFill(['national_id' => '555555555', 'mobile' => '0599999999'])->save();

        foreach (['NATIONAL_ID' => self::NATIONAL_ID, 'MOBILE' => self::MOBILE] as $field => $own) {
            $response = $this->reveal($head['user'], ['field' => $field])->assertOk()->assertJsonPath('data.value', $own);
            $this->assertStringNotContainsString('555555555', $response->getContent());
            $this->assertStringNotContainsString('0599999999', $response->getContent());
        }
    }

    // --------------------------------------------------------- the boundary

    public function test_a_guest_gets_the_json_401(): void
    {
        $this->postJson(self::URI, ['field' => 'NATIONAL_ID'])->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertCount(0, $this->events());
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
        $mixed = $this->ownHead('222222222')['user'];
        $mixed->assignRole('ADMINISTRATOR');
        $accounts['mixed staff and family'] = $mixed;

        foreach ($accounts as $label => $user) {
            $response = $this->reveal($user, ['field' => 'NATIONAL_ID'])->assertForbidden()->assertExactJson(['message' => EnsureFamilySideAccount::MESSAGE]);
            $this->assertStringNotContainsString('222222222', $response->getContent(), $label);
        }
        $this->assertCount(0, $this->events());
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
    public function test_every_lost_family_context_is_refused_and_reveals_nothing(string $denial): void
    {
        $head = $this->ownHead();
        $this->reveal($head['user'], ['field' => 'MOBILE'])->assertOk();
        AuthSecurityEvent::query()->delete();

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

        foreach (['NATIONAL_ID', 'MOBILE', 'ALTERNATE_MOBILE'] as $field) {
            $response = $this->reveal($head['user'], ['field' => $field])->assertForbidden()
                ->assertExactJson(['message' => EnsureFamilyContext::MESSAGE, 'code' => EnsureFamilyContext::CODE]);
            foreach ([self::NATIONAL_ID, '987654321', self::MOBILE, self::ALTERNATE] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent(), $secret);
            }
        }
        $this->assertCount(0, $this->events());
    }

    public function test_a_recorded_death_of_the_head_closes_the_reveal(): void
    {
        $head = $this->ownHead();
        app(RecordPersonDeathAction::class)->handle($head['person'], null, LifeStatusVerificationMethod::IN_PERSON, null);

        $this->reveal($head['user'], ['field' => 'NATIONAL_ID'])->assertForbidden();
        $this->assertCount(0, $this->events());
    }

    // ------------------------------------------------------------- throttle

    public function test_the_dedicated_throttle_answers_429_and_reveals_nothing_more(): void
    {
        config(['family_auth.self_reveal.limits.user_minute' => 3]);
        $head = $this->ownHead();
        $other = $this->ownHead('222222222');

        foreach (['NATIONAL_ID', 'MOBILE', 'ALTERNATE_MOBILE'] as $field) {
            $this->reveal($head['user'], ['field' => $field])->assertOk();
        }
        $response = $this->reveal($head['user'], ['field' => 'NATIONAL_ID'])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        $this->assertStringNotContainsString(self::NATIONAL_ID, $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertCount(3, $this->events());
        // The ceiling is per user: another household head is not affected.
        $this->reveal($other['user'], ['field' => 'NATIONAL_ID'])->assertOk()->assertJsonPath('data.value', '222222222');
    }
}
