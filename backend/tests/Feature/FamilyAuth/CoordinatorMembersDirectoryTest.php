<?php

namespace Tests\Feature\FamilyAuth;

use App\Enums\AuthSecurityEventType;
use App\Enums\FingerprintContext;
use App\Models\AuthSecurityEvent;
use App\Models\Family;
use App\Models\FamilyHouseholdDeclaration;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use App\Models\User;
use App\Support\Coordinator\CoordinatorMemberReference;
use App\Support\FamilyAuth\KeyedFingerprint;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * CO-01C (docs/06 AUTH-ADR-092): the Coordinator members directory and the
 * audited one-field reveal — permissions, scope isolation, IDOR, masking,
 * audit and rate limiting. Synthetic data only: every name, National ID and
 * mobile here is invented.
 */
class CoordinatorMembersDirectoryTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const FAMILY_KEYS = ['family_code', 'clan_name', 'branch_group_name', 'branch_name', 'head_name', 'registered_member_count', 'declared_household_size', 'declared_at'];

    private const MEMBER_KEYS = ['member_ref', 'available', 'full_name', 'relationship', 'is_household_head', 'gender', 'age', 'marital_status', 'life_status', 'national_id_masked', 'mobile_masked'];

    private const FAMILY_NOT_FOUND = ['message' => 'الأسرة غير متاحة.', 'code' => 'FAMILY_NOT_FOUND'];

    private const MEMBER_NOT_FOUND = ['message' => 'الفرد غير متاح.', 'code' => 'MEMBER_NOT_FOUND'];

    // Invented values, used to prove they never leak.
    private const NID = '804455667';

    private const MOBILE = '0598877112';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        // Generous ceilings; the rate-limit test lowers them.
        config(['family_auth.coordinator_reveal.limits.user_minute' => 1000, 'family_auth.coordinator_reveal.limits.user_hour' => 1000]);
    }

    private function directoryUri(string $code): string
    {
        return "/api/v1/family/coordinator/families/{$code}/members";
    }

    private function directory(User $as, string $code): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->getJson($this->directoryUri($code));
    }

    private function reveal(User $as, string $code, string $ref, array $body = ['field' => 'NATIONAL_ID'], string $query = ''): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as->fresh())->postJson($this->directoryUri($code)."/{$ref}/reveal{$query}", $body);
    }

    private function relationship(string $code, string $name): int
    {
        return (int) (DB::table('relationship_types')->where('code', $code)->value('id')
            ?? DB::table('relationship_types')->insertGetId(['code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]));
    }

    /** A member added to the Family with known synthetic values. */
    private function member(Family $family, array $person = [], string $relationship = 'SON'): FamilyMembership
    {
        return FamilyMembership::factory()->create([
            'family_id' => $family->id,
            'person_id' => Person::factory()->create($person)->id,
            'relationship_type_id' => $this->relationship($relationship, $relationship === 'SON' ? 'ابن' : 'زوجة'),
            'is_household_head' => false,
        ]);
    }

    private function refOf(FamilyMembership $membership): string
    {
        return CoordinatorMemberReference::of((int) $membership->family_id, (int) $membership->id);
    }

    private function events(): Collection
    {
        return AuthSecurityEvent::query()->where('event_type', AuthSecurityEventType::COORDINATOR_MEMBER_SENSITIVE_REVEALED->value)->orderBy('id')->get();
    }

    /** A Coordinator with one BRANCH scope and one Family in it. */
    private function scenario(): array
    {
        $clan = $this->clan();
        $group = $this->group($clan, 'GRP_A');
        $branch = $this->branch($clan, $group, 'BR_A');
        $family = $this->familyIn($clan, $branch);
        $user = $this->coordinator();
        $this->assign($user, $branch);

        return compact('clan', 'group', 'branch', 'family', 'user');
    }

    // ------------------------------------------------------------ projection

    public function test_the_directory_carries_exactly_the_approved_fields_with_distinct_counts(): void
    {
        ['clan' => $clan, 'group' => $group, 'branch' => $branch, 'family' => $family, 'user' => $user] = $this->scenario();
        $son = $this->member($family, [
            'full_name' => 'ابن تجريبي', 'gender' => 'MALE', 'birth_date' => now()->subYears(12)->subDay()->toDateString(),
            'marital_status' => 'SINGLE', 'life_status' => 'ALIVE', 'national_id' => self::NID, 'mobile' => self::MOBILE,
        ]);
        $this->member($family, ['full_name' => 'زوجة تجريبية'], 'SPOUSE');
        FamilyHouseholdDeclaration::factory()->create(['family_id' => $family->id, 'declared_household_size' => 7, 'declared_at' => '2026-09-01', 'is_current' => true]);

        $response = $this->directory($user, $family->family_code)->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(['family', 'members', 'can_reveal'], array_keys($response->json('data')));
        $this->assertTrue($response->json('data.can_reveal'));
        $this->assertSame([
            'family_code' => $family->family_code,
            'clan_name' => $clan->name,
            'branch_group_name' => $group->name,
            'branch_name' => $branch->name,
            'head_name' => 'رب أسرة '.$family->family_code,
            // Registered (active memberships) and declared stay distinct.
            'registered_member_count' => 3,
            'declared_household_size' => 7,
            'declared_at' => '2026-09-01',
        ], $response->json('data.family'));

        $members = collect($response->json('data.members'));
        $this->assertCount(3, $members);
        $members->each(fn (array $m) => $this->assertSame(self::MEMBER_KEYS, array_keys($m)));
        $this->assertTrue($members->first()['is_household_head']);
        $row = $members->firstWhere('full_name', 'ابن تجريبي');
        $this->assertSame([
            'member_ref' => $this->refOf($son),
            'available' => true,
            'full_name' => 'ابن تجريبي',
            'relationship' => ['code' => 'SON', 'name' => 'ابن'],
            'is_household_head' => false,
            'gender' => 'MALE',
            'age' => 12,
            'marital_status' => 'SINGLE',
            'life_status' => 'ALIVE',
            'national_id_masked' => '*****5667',
            'mobile_masked' => '05*****112',
        ], $row);
    }

    public function test_no_full_identifier_or_protected_data_ever_appears_in_the_directory(): void
    {
        ['family' => $family, 'user' => $user] = $this->scenario();
        $family->forceFill(['notes' => 'ملاحظة سرية للأسرة', 'paper_form_no' => 'PAPER-7788'])->saveQuietly();
        $member = $this->member($family, [
            'national_id' => self::NID, 'mobile' => self::MOBILE, 'alternate_mobile' => '0567766551',
            'notes' => 'ملاحظة سرية للشخص', 'birth_date' => '1990-04-23',
        ]);
        FamilyResidence::factory()->create(['family_id' => $family->id, 'address_text' => 'عنوان سكن سري', 'city' => 'مدينة سرية']);
        PersonHealthRecord::factory()->create(['person_id' => $member->person_id, 'condition_name' => 'حالة صحية سرية', 'details' => 'تفاصيل صحية سرية']);

        $body = $this->directory($user, $family->family_code)->assertOk()->getContent();

        foreach ([self::NID, self::MOBILE, '0567766551', 'ملاحظة سرية', 'PAPER-7788', 'عنوان سكن سري', 'مدينة سرية',
            'حالة صحية سرية', 'تفاصيل صحية سرية', '1990-04-23', 'person_code', 'person_id', 'membership_id', '"id"',
            'alternate_mobile', 'birth_date', 'health', 'otp', 'password', Person::find($member->person_id)->person_code] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $body);
        }
        // Listing the directory reveals nothing, so it records no reveal event.
        $this->assertCount(0, $this->events());
    }

    public function test_a_deceased_member_has_no_age_and_an_unavailable_person_is_a_placeholder(): void
    {
        ['family' => $family, 'user' => $user] = $this->scenario();
        $this->member($family, ['full_name' => 'متوفى تجريبي', 'life_status' => 'DECEASED', 'birth_date' => '1950-01-01']);
        $gone = $this->member($family, ['full_name' => 'محذوف تجريبي', 'national_id' => self::NID]);
        Person::find($gone->person_id)->delete();

        $members = collect($this->directory($user, $family->family_code)->assertOk()->json('data.members'));

        $this->assertNull($members->firstWhere('full_name', 'متوفى تجريبي')['age']);
        $placeholder = $members->firstWhere('available', false);
        $this->assertNotNull($placeholder);
        $this->assertNull($placeholder['full_name']);
        $this->assertNull($placeholder['national_id_masked']);
        // The unavailable Person cannot be revealed.
        $this->reveal($user, $family->family_code, $placeholder['member_ref'])->assertNotFound()->assertExactJson(self::MEMBER_NOT_FOUND);
    }

    // ------------------------------------------------------------ permissions

    public function test_the_member_view_permission_is_required_for_the_directory_and_the_reveal(): void
    {
        ['family' => $family, 'user' => $user] = $this->scenario();
        $ref = $this->refOf($this->member($family, ['national_id' => self::NID]));
        Role::findByName('COORDINATOR', 'web')->revokePermissionTo('coordinator-member.view');

        // The summary (unchanged permission) still answers.
        $this->actingAs($user->fresh())->getJson("/api/v1/family/coordinator/families/{$family->family_code}")->assertOk();
        $this->directory($user, $family->family_code)->assertForbidden();
        $this->reveal($user, $family->family_code, $ref)->assertForbidden();
        $this->assertCount(0, $this->events());
    }

    public function test_without_the_sensitive_permission_the_directory_stays_masked_and_the_reveal_is_refused(): void
    {
        ['family' => $family, 'user' => $user] = $this->scenario();
        $ref = $this->refOf($this->member($family, ['national_id' => self::NID]));
        Role::findByName('COORDINATOR', 'web')->revokePermissionTo('coordinator-member.view-sensitive');

        $response = $this->directory($user, $family->family_code)->assertOk();
        $this->assertFalse($response->json('data.can_reveal'));
        $this->assertStringNotContainsString(self::NID, $response->getContent());

        $denied = $this->reveal($user, $family->family_code, $ref)->assertForbidden();
        $this->assertStringNotContainsString(self::NID, $denied->getContent());
        $this->assertCount(0, $this->events());
    }

    /** @return array<string, array{0: string}> */
    public static function refusedAccounts(): array
    {
        return [
            'staff session holding the permissions' => ['staff'],
            'family user without coordinator' => ['family-user'],
            'coordinator only (invalid account)' => ['coordinator-only'],
            'role without assignment' => ['no-assignment'],
            'assignment without role' => ['no-role'],
            'coordinator who lost eligibility' => ['ineligible'],
        ];
    }

    #[DataProvider('refusedAccounts')]
    public function test_refused_accounts_reach_no_directory_and_no_reveal(string $case): void
    {
        $clan = $this->clan();
        $family = $this->familyIn($clan);
        $ref = $this->refOf($this->member($family, ['national_id' => self::NID]));
        $user = match ($case) {
            'staff' => tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN')),
            'family-user' => $this->activatedHead('111111110')['user'],
            'coordinator-only' => $this->familyUser(['COORDINATOR']),
            'no-assignment' => $this->coordinator(),
            'no-role' => $this->coordinator(roles: ['FAMILY_USER']),
            'ineligible' => (function () {
                $head = $this->activatedHead('111111110', ['FAMILY_USER', 'COORDINATOR']);
                $head['family']->delete();

                return $head['user'];
            })(),
        };
        if ($case !== 'no-assignment') {
            $this->assign($user, $clan);
        }
        if ($case === 'staff') {
            $user->givePermissionTo(['coordinator-space.access', 'coordinator-family.view-summary', 'coordinator-member.view', 'coordinator-member.view-sensitive']);
        }

        $this->directory($user, $family->family_code)->assertForbidden();
        $this->assertStringNotContainsString(self::NID, $this->reveal($user, $family->family_code, $ref)->assertForbidden()->getContent());
        $this->assertCount(0, $this->events());
    }

    // ------------------------------------------------------------------ scope

    public function test_clan_branch_and_multiple_branch_scopes_are_honoured(): void
    {
        $clanA = $this->clan('CLAN_A');
        $clanB = $this->clan('CLAN_B');
        $b1 = $this->branch($clanA, null, 'BR_1');
        $b2 = $this->branch($clanA, null, 'BR_2');
        $b3 = $this->branch($clanA, null, 'BR_3');
        $f1 = $this->familyIn($clanA, $b1);
        $f2 = $this->familyIn($clanA, $b2);
        $f3 = $this->familyIn($clanA, $b3);
        $fB = $this->familyIn($clanB, $this->branch($clanB, null, 'BR_B'));
        $unbranched = $this->familyIn($clanA);

        // Several branches: their union, nothing else.
        $branches = $this->coordinator('111111110');
        $this->assign($branches, $b1);
        $this->assign($branches, $b2);
        $this->directory($branches, $f1->family_code)->assertOk();
        $this->directory($branches, $f2->family_code)->assertOk();
        $this->directory($branches, $f3->family_code)->assertNotFound()->assertExactJson(self::FAMILY_NOT_FOUND);
        $this->directory($branches, $fB->family_code)->assertNotFound();
        $this->directory($branches, $unbranched->family_code)->assertNotFound();

        // A whole Clan: every Family of that Clan (unbranched included), none of another.
        $clanScope = $this->coordinator('222222220');
        $this->assign($clanScope, $clanA);
        foreach ([$f1, $f2, $f3, $unbranched] as $family) {
            $this->directory($clanScope, $family->family_code)->assertOk();
        }
        $this->directory($clanScope, $fB->family_code)->assertNotFound();
    }

    public function test_out_of_scope_and_nonexistent_families_are_the_same_404_and_the_reveal_is_audited_as_denied(): void
    {
        ['clan' => $clan, 'user' => $user] = $this->scenario();
        $outside = $this->familyIn($clan, $this->branch($clan, null, 'BR_OUT'));
        $outsideRef = $this->refOf($this->member($outside, ['national_id' => self::NID]));

        $this->directory($user, $outside->family_code)->assertNotFound()->assertExactJson(self::FAMILY_NOT_FOUND);
        $this->directory($user, 'FAM-999999')->assertNotFound()->assertExactJson(self::FAMILY_NOT_FOUND);

        $response = $this->reveal($user, $outside->family_code, $outsideRef)->assertNotFound()->assertExactJson(self::MEMBER_NOT_FOUND);
        $this->assertStringNotContainsString(self::NID, $response->getContent());

        $event = $this->events()->sole();
        $this->assertSame('DENIED', $event->outcome->value);
        $this->assertSame('FAMILY_NOT_IN_SCOPE', $event->reason_code);
        // The out-of-scope Family is never looked up, so never recorded.
        $this->assertNull($event->family_id);
        $this->assertNull($event->person_id);
        $this->assertSame($user->id, $event->actor_user_id);
    }

    public function test_a_revoked_assignment_closes_access_on_the_next_request(): void
    {
        $clan = $this->clan();
        $b1 = $this->branch($clan, null, 'BR_1');
        $b2 = $this->branch($clan, null, 'BR_2');
        $f1 = $this->familyIn($clan, $b1);
        $f2 = $this->familyIn($clan, $b2);
        $ref = $this->refOf($this->member($f1, ['national_id' => self::NID]));
        $user = $this->coordinator();
        $a1 = $this->assign($user, $b1);
        $a2 = $this->assign($user, $b2);

        $this->reveal($user, $f1->family_code, $ref)->assertOk()->assertJsonPath('data.value', self::NID);

        // One scope revoked: that Family is gone, the other remains.
        $a1->forceFill(['revoked_at' => now(), 'revoked_by' => $user->id, 'revoke_reason' => 'SCOPE_CHANGED'])->save();
        $this->directory($user, $f1->family_code)->assertNotFound();
        $this->reveal($user, $f1->family_code, $ref)->assertNotFound();
        $this->directory($user, $f2->family_code)->assertOk();

        // Every scope revoked: no Coordinator Space at all.
        $a2->forceFill(['revoked_at' => now(), 'revoked_by' => $user->id, 'revoke_reason' => 'ADMINISTRATIVE'])->save();
        $this->directory($user, $f2->family_code)->assertForbidden();
    }

    // ------------------------------------------------------------------- IDOR

    public function test_member_references_resolve_only_inside_their_own_in_scope_family(): void
    {
        ['clan' => $clan, 'branch' => $branch, 'family' => $family, 'user' => $user] = $this->scenario();
        $other = $this->familyIn($clan, $branch);
        $foreign = $this->member($other, ['national_id' => '807700112']);
        $mine = $this->member($family, ['national_id' => self::NID]);
        $ended = $this->member($family, ['national_id' => '809900331']);
        $ended->forceFill(['is_active' => false, 'ended_at' => now()->toDateString()])->save();

        $cases = [
            // Another (in-scope) Family's member under this Family's code.
            $this->refOf($foreign),
            // The household-portal member_ref of the same membership: another domain.
            KeyedFingerprint::of(FingerprintContext::MEMBER_REF, $mine->family_id.':'.$mine->id),
            // An ended membership.
            $this->refOf($ended),
            // Random, upper-case, short, internal ids.
            str_repeat('a', 64),
            strtoupper($this->refOf($mine)),
            substr($this->refOf($mine), 0, 63),
            (string) $mine->id,
            (string) $mine->person_id,
        ];
        foreach ($cases as $ref) {
            $body = $this->reveal($user, $family->family_code, $ref)->assertNotFound()->assertExactJson(self::MEMBER_NOT_FOUND)->getContent();
            foreach ([self::NID, '807700112', '809900331'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
        }

        $events = $this->events();
        $this->assertCount(count($cases), $events);
        $events->each(function (AuthSecurityEvent $event) use ($family) {
            $this->assertSame('DENIED', $event->outcome->value);
            $this->assertSame('MEMBER_NOT_FOUND', $event->reason_code);
            $this->assertSame($family->id, $event->family_id);
            $this->assertNull($event->person_id);
        });

        // The foreign reference works under its own Family's code.
        $this->reveal($user, $other->family_code, $this->refOf($foreign))->assertOk()->assertJsonPath('data.value', '807700112');
    }

    public function test_the_target_comes_only_from_the_path(): void
    {
        ['family' => $family, 'user' => $user] = $this->scenario();
        $ref = $this->refOf($this->member($family, ['national_id' => self::NID]));

        foreach (['person_id' => 1, 'family_code' => $family->family_code, 'member_ref' => $ref, 'branch' => 'BR_A', 'scope' => 'CLAN', 'value' => 'x'] as $key => $value) {
            $this->reveal($user, $family->family_code, $ref, ['field' => 'NATIONAL_ID', $key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->reveal($user, $family->family_code, $ref, ['field' => 'NATIONAL_ID'], '?person_id=1')->assertUnprocessable();
        foreach (['ALTERNATE_MOBILE', 'BIRTH_DATE', 'national_id', ''] as $field) {
            $this->reveal($user, $family->family_code, $ref, ['field' => $field])->assertUnprocessable()->assertJsonValidationErrors('field');
        }
        $this->assertCount(0, $this->events());
    }

    public function test_no_member_route_binds_a_model_and_each_carries_its_permissions(): void
    {
        $found = 0;
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/family/coordinator/families/{familyCode}/members')) {
                $found++;
                $middleware = $route->gatherMiddleware();
                $this->assertSame([], $route->signatureParameters(['subClass' => Model::class]));
                foreach (['coordinator.space', 'can:coordinator-family.view-summary', 'can:coordinator-member.view'] as $required) {
                    $this->assertContains($required, $middleware, $route->uri());
                }
                if (str_ends_with($route->uri(), '/reveal')) {
                    $this->assertContains('can:coordinator-member.view-sensitive', $middleware);
                    $this->assertContains('throttle:coordinator-member-reveal', $middleware);
                } else {
                    $this->assertContains('throttle:coordinator-member-directory', $middleware);
                }
            }
        }
        $this->assertSame(2, $found);
    }

    // ----------------------------------------------------------------- reveal

    public function test_a_reveal_returns_one_field_and_records_one_audit_event_without_the_value(): void
    {
        ['family' => $family, 'user' => $user] = $this->scenario();
        $member = $this->member($family, ['national_id' => self::NID, 'mobile' => self::MOBILE]);
        $ref = $this->refOf($member);

        $nid = $this->reveal($user, $family->family_code, $ref)->assertOk()->assertExactJson(['data' => ['field' => 'NATIONAL_ID', 'value' => self::NID]]);
        $this->assertStringContainsString('no-store', (string) $nid->headers->get('Cache-Control'));
        $mobile = $this->reveal($user, $family->family_code, $ref, ['field' => 'MOBILE'])->assertOk()->assertExactJson(['data' => ['field' => 'MOBILE', 'value' => self::MOBILE]]);
        // One field at a time: the National ID is not in the mobile answer.
        $this->assertStringNotContainsString(self::NID, $mobile->getContent());

        $events = $this->events();
        $this->assertCount(2, $events);
        foreach ($events as $i => $event) {
            $this->assertSame('SUCCESS', $event->outcome->value);
            $this->assertNull($event->reason_code);
            $this->assertSame($user->id, $event->actor_user_id);
            $this->assertSame($user->id, $event->user_id);
            $this->assertSame($family->id, $event->family_id);
            $this->assertSame($member->person_id, $event->person_id);
            $this->assertSame(['field' => $i === 0 ? 'NATIONAL_ID' : 'MOBILE'], $event->metadata);
            $this->assertNotNull($event->created_at);
        }
        // The stored rows hold no value, mask or reference in any column.
        $raw = json_encode(DB::table('auth_security_events')->get());
        foreach ([self::NID, self::MOBILE, '*****5667', '05*****112', $ref] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $raw);
        }
    }

    public function test_a_missing_value_is_null_and_still_audited(): void
    {
        ['family' => $family, 'user' => $user] = $this->scenario();
        $ref = $this->refOf($this->member($family, ['mobile' => null]));

        $this->reveal($user, $family->family_code, $ref, ['field' => 'MOBILE'])->assertOk()->assertExactJson(['data' => ['field' => 'MOBILE', 'value' => null]]);
        $this->assertSame('SUCCESS', $this->events()->sole()->outcome->value);
    }

    public function test_reveals_are_rate_limited_per_coordinator(): void
    {
        config(['family_auth.coordinator_reveal.limits.user_minute' => 2]);
        ['family' => $family, 'user' => $user] = $this->scenario();
        $ref = $this->refOf($this->member($family, ['national_id' => self::NID]));

        $this->reveal($user, $family->family_code, $ref)->assertOk();
        // Another reference does not reset the count.
        $this->reveal($user, $family->family_code, str_repeat('b', 64))->assertNotFound();
        $limited = $this->reveal($user, $family->family_code, $ref)->assertStatus(429);
        $this->assertStringNotContainsString(self::NID, $limited->getContent());
        $this->assertCount(2, $this->events());
    }

    public function test_existing_security_events_keep_a_null_family(): void
    {
        $this->activatedHead('333333330');
        $other = AuthSecurityEvent::query()->create(['event_type' => AuthSecurityEventType::LOGIN_SUCCEEDED, 'outcome' => 'SUCCESS']);

        $this->assertNull($other->fresh()->family_id);
    }
}
