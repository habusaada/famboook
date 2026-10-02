<?php

namespace Tests\Feature\FamilyAuth;

use App\Enums\AuthIdentityStatus;
use App\Enums\FamilyAccessDenial;
use App\Enums\FamilyStatus;
use App\Enums\LifeStatus;
use App\Enums\UserPersonLinkStatus;
use App\Models\AuthSecurityEvent;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Support\AccountSide;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Spatie\Permission\Models\Role;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1D: the authoritative Family access resolver (docs/11 §30a). Identity
 * validity and Family context are separate; the Family is derived on the
 * server only. Synthetic data only.
 */
class FamilyAccessResolverTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private FamilyAccessResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->resolver = app(FamilyAccessResolver::class);
    }

    private function assertIdentityDenied(FamilyAccessDenial $expected, User $user): void
    {
        foreach ([$this->resolver->identity($user->fresh()), $this->resolver->familyContext($user->fresh())] as $result) {
            $this->assertFalse($result->allowed());
            $this->assertSame($expected, $result->denial);
            $this->assertTrue($result->denial->isIdentity());
            // A denial carries nothing else.
            $this->assertNull($result->user);
            $this->assertNull($result->person);
            $this->assertNull($result->family);
            $this->assertFalse($result->hasFamilyContext());
        }
    }

    private function assertContextDenied(FamilyAccessDenial $expected, User $user): void
    {
        // The identity is still valid; only the Family context is missing.
        $this->assertTrue($this->resolver->identity($user->fresh())->allowed());

        $result = $this->resolver->familyContext($user->fresh());
        $this->assertSame($expected, $result->denial);
        $this->assertFalse($result->denial->isIdentity());
        $this->assertNull($result->family);
    }

    // ---------------------------------------------------------------- success

    public function test_identity_resolves_for_an_activated_household_head(): void
    {
        $a = $this->activatedHead();

        $result = $this->resolver->identity($a['user']);

        $this->assertTrue($result->allowed());
        $this->assertNull($result->denial);
        $this->assertTrue($result->user->is($a['user']));
        $this->assertTrue($result->link->is($a['link']));
        $this->assertTrue($result->person->is($a['person']));
        $this->assertTrue($result->authIdentity->is($a['identity']));
        // Identity alone is not a Family context.
        $this->assertNull($result->family);
        $this->assertNull($result->membership);
        $this->assertFalse($result->hasFamilyContext());
    }

    public function test_family_context_resolves_the_family_from_the_membership(): void
    {
        $a = $this->activatedHead();
        Family::factory()->count(2)->create();

        $result = $this->resolver->familyContext($a['user']);

        $this->assertTrue($result->allowed());
        $this->assertTrue($result->hasFamilyContext());
        $this->assertTrue($result->family->is($a['family']));
        $this->assertTrue($result->membership->is($a['membership']));
        $this->assertTrue($result->person->is($a['person']));
    }

    public function test_a_dual_role_account_resolves_in_either_role_order(): void
    {
        $a = $this->activatedHead('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $b = $this->activatedHead('987654321', ['COORDINATOR', 'FAMILY_USER']);

        $this->assertTrue($this->resolver->familyContext($a['user'])->hasFamilyContext());
        $this->assertTrue($this->resolver->familyContext($b['user'])->hasFamilyContext());
    }

    public function test_each_user_only_ever_resolves_their_own_family(): void
    {
        $a = $this->activatedHead('123456789');
        $b = $this->activatedHead('987654321');

        $this->assertTrue($this->resolver->familyContext($a['user'])->family->is($a['family']));
        $this->assertTrue($this->resolver->familyContext($b['user'])->family->is($b['family']));
        $this->assertFalse($this->resolver->familyContext($a['user'])->family->is($b['family']));
    }

    public function test_nothing_lets_a_caller_supply_a_family(): void
    {
        // The public API takes a User (or a Person) and nothing else…
        foreach (['identity' => User::class, 'familyContext' => User::class, 'headEligibility' => Person::class] as $method => $type) {
            $parameters = (new ReflectionMethod(FamilyAccessResolver::class, $method))->getParameters();
            $this->assertCount(1, $parameters, $method);
            $this->assertInstanceOf(ReflectionNamedType::class, $parameters[0]->getType());
            $this->assertSame($type, $parameters[0]->getType()->getName());
        }
        // …and a result cannot be constructed or mutated from outside.
        $class = new ReflectionClass(FamilyAccessResult::class);
        $this->assertTrue($class->isReadOnly());
        $this->assertTrue($class->getConstructor()->isPrivate());

        // A request that names another Family changes nothing.
        $a = $this->activatedHead('123456789');
        $b = $this->activatedHead('987654321');
        $this->app['request']->merge(['family_id' => $b['family']->id, 'family' => $b['family']->family_code]);
        $this->app['request']->headers->set('X-Family-Id', (string) $b['family']->id);

        $this->assertTrue($this->resolver->familyContext($a['user'])->family->is($a['family']));
    }

    public function test_the_resolver_is_evaluated_afresh_and_records_nothing(): void
    {
        $a = $this->activatedHead();
        $this->assertTrue($this->resolver->familyContext($a['user'])->allowed());

        // No cache: a change is visible on the very next call.
        $a['person']->forceFill(['life_status' => LifeStatus::DECEASED->value])->save();
        $this->assertSame(FamilyAccessDenial::PERSON_NOT_ALIVE, $this->resolver->familyContext($a['user']->fresh())->denial);

        $a['person']->forceFill(['life_status' => LifeStatus::ALIVE->value])->save();
        $this->assertTrue($this->resolver->familyContext($a['user']->fresh())->allowed());

        // Reads are never audit events.
        $this->assertSame(0, AuthSecurityEvent::count());
    }

    // ------------------------------------------------------ identity denials

    public function test_an_inactive_user_is_denied(): void
    {
        $a = $this->activatedHead();
        $a['user']->forceFill(['is_active' => false])->save();

        $this->assertIdentityDenied(FamilyAccessDenial::USER_INACTIVE, $a['user']);
    }

    public function test_an_account_that_is_not_family_side_is_denied(): void
    {
        // No FAMILY_USER at all.
        $none = $this->activatedHead('111111111', []);
        // COORDINATOR without FAMILY_USER.
        $coordinatorOnly = $this->activatedHead('222222222', ['COORDINATOR']);
        // A Staff role mixed with FAMILY_USER, in both role orders.
        $mixed = $this->activatedHead('333333333', ['DATA_ENTRY', 'FAMILY_USER']);
        $mixedOther = $this->activatedHead('444444444', ['FAMILY_USER', 'SUPER_ADMIN']);
        // A Staff account.
        $staff = $this->activatedHead('555555555', ['ADMINISTRATOR']);

        foreach ([$none, $coordinatorOnly, $mixed, $mixedOther, $staff] as $account) {
            $this->assertIdentityDenied(FamilyAccessDenial::NOT_FAMILY_SIDE, $account['user']);
        }
        $this->assertSame(AccountSide::INVALID, AccountSide::of($mixed['user']->fresh()));
    }

    public function test_a_user_without_a_link_is_denied(): void
    {
        $user = $this->familyUser();
        $this->assertIdentityDenied(FamilyAccessDenial::NO_LINK, $user);

        // An ended link is history, not a link.
        $a = $this->activatedHead();
        $a['link']->forceFill(['status' => UserPersonLinkStatus::ENDED->value, 'ended_at' => now(), 'end_reason' => 'ADMINISTRATIVE'])->save();
        $this->assertIdentityDenied(FamilyAccessDenial::NO_LINK, $a['user']);
    }

    public function test_a_suspended_link_is_denied(): void
    {
        $a = $this->activatedHead();
        $a['link']->forceFill(['status' => UserPersonLinkStatus::SUSPENDED->value, 'suspended_at' => now()])->save();

        $this->assertIdentityDenied(FamilyAccessDenial::LINK_SUSPENDED, $a['user']);
    }

    public function test_a_soft_deleted_person_is_denied(): void
    {
        $a = $this->activatedHead();
        $a['person']->delete();

        $this->assertIdentityDenied(FamilyAccessDenial::PERSON_DELETED, $a['user']);
    }

    public function test_an_inactive_person_is_denied(): void
    {
        $a = $this->activatedHead();
        $a['person']->forceFill(['is_active' => false])->save();

        $this->assertIdentityDenied(FamilyAccessDenial::PERSON_INACTIVE, $a['user']);
    }

    public function test_only_an_alive_person_is_eligible(): void
    {
        foreach ([LifeStatus::DECEASED, LifeStatus::UNKNOWN] as $i => $status) {
            $a = $this->activatedHead('12345678'.$i);
            $a['person']->forceFill(['life_status' => $status->value])->save();

            $this->assertIdentityDenied(FamilyAccessDenial::PERSON_NOT_ALIVE, $a['user']);
        }
    }

    public function test_a_user_without_an_active_auth_identity_is_denied(): void
    {
        $a = $this->activatedHead('111111111');
        DB::table('family_auth_identities')->where('id', $a['identity']->id)->delete();
        $this->assertIdentityDenied(FamilyAccessDenial::NO_AUTH_IDENTITY, $a['user']);

        $b = $this->activatedHead('222222222');
        $b['identity']->forceFill(['status' => AuthIdentityStatus::SUPERSEDED->value, 'superseded_at' => now(), 'supersede_reason' => 'KEY_ROTATION'])->save();
        $this->assertIdentityDenied(FamilyAccessDenial::NO_AUTH_IDENTITY, $b['user']);

        $c = $this->activatedHead('333333333');
        $c['identity']->forceFill(['status' => AuthIdentityStatus::SUSPENDED->value])->save();
        $this->assertIdentityDenied(FamilyAccessDenial::AUTH_IDENTITY_SUSPENDED, $c['user']);
    }

    public function test_a_stored_national_id_that_is_not_nine_digits_is_denied(): void
    {
        foreach ([null, '', '12345678', '1234567890', '12345678X', 'ABC'] as $i => $stored) {
            $a = $this->activatedHead('10000000'.$i);
            DB::table('persons')->where('id', $a['person']->id)->update(['national_id' => $stored]);

            $this->assertIdentityDenied(FamilyAccessDenial::NATIONAL_ID_INVALID, $a['user']);
        }
    }

    public function test_a_fingerprint_mismatch_is_denied(): void
    {
        // The registry value changed behind the identity's back (a direct
        // write that bypassed CorrectNationalIdAction): the old identifier
        // must not keep working.
        $a = $this->activatedHead('123456789');
        DB::table('persons')->where('id', $a['person']->id)->update(['national_id' => '987654321']);

        $this->assertIdentityDenied(FamilyAccessDenial::IDENTITY_MISMATCH, $a['user']);
    }

    public function test_a_formatted_but_equal_national_id_still_matches(): void
    {
        $a = $this->activatedHead('123456789');
        DB::table('persons')->where('id', $a['person']->id)->update(['national_id' => ' ١٢٣-٤٥٦-٧٨٩ ']);

        $this->assertTrue($this->resolver->familyContext($a['user']->fresh())->allowed());
    }

    public function test_a_missing_fingerprint_key_fails_closed(): void
    {
        $a = $this->activatedHead();
        config(['family_auth.fingerprint.key' => null]);

        $this->assertIdentityDenied(FamilyAccessDenial::FINGERPRINT_UNAVAILABLE, $a['user']);
    }

    public function test_the_identity_is_checked_under_its_own_key_version(): void
    {
        $a = $this->activatedHead();
        $this->assertTrue($this->resolver->identity($a['user'])->allowed());

        // The key rotated: version 1 stays readable as the previous key.
        config(['family_auth.fingerprint' => [
            'key' => 'another-test-only-family-auth-key-9876543210', 'key_version' => 2,
            'previous_key' => 'test-only-family-auth-key-0123456789-abcdef', 'previous_key_version' => 1,
        ]]);
        $this->assertTrue($this->resolver->identity($a['user']->fresh())->allowed());

        // Without the previous key the version-1 identity cannot be verified.
        config(['family_auth.fingerprint.previous_key' => null, 'family_auth.fingerprint.previous_key_version' => null]);
        $this->assertIdentityDenied(FamilyAccessDenial::FINGERPRINT_UNAVAILABLE, $a['user']);
    }

    // ------------------------------------------------ family-context denials

    public function test_a_person_without_an_active_membership_has_no_family_context(): void
    {
        $a = $this->activatedHead();
        $a['membership']->forceFill(['is_active' => false, 'ended_at' => now()])->save();

        $this->assertContextDenied(FamilyAccessDenial::NO_ACTIVE_MEMBERSHIP, $a['user']);
    }

    public function test_a_member_who_is_not_the_household_head_has_no_family_context(): void
    {
        $a = $this->activatedHead();
        $a['membership']->forceFill(['is_household_head' => false])->save();

        $this->assertContextDenied(FamilyAccessDenial::NOT_HOUSEHOLD_HEAD, $a['user']);
    }

    public function test_a_family_that_is_not_active_gives_no_family_context(): void
    {
        foreach ([FamilyStatus::INACTIVE, FamilyStatus::ARCHIVED] as $i => $status) {
            $a = $this->activatedHead('12345678'.$i);
            $a['family']->forceFill(['status' => $status->value])->save();

            $this->assertContextDenied(FamilyAccessDenial::FAMILY_NOT_ACTIVE, $a['user']);
        }
    }

    public function test_a_soft_deleted_family_gives_no_family_context(): void
    {
        $a = $this->activatedHead();
        $a['family']->delete();

        $this->assertContextDenied(FamilyAccessDenial::FAMILY_DELETED, $a['user']);
    }

    // -------------------------------------------------------- head eligibility

    public function test_head_eligibility_needs_no_account(): void
    {
        [$person] = $this->eligibleHead();
        $this->assertNull($this->resolver->headEligibility($person));

        // Not a head.
        $member = Person::factory()->create(['national_id' => '222222222']);
        FamilyMembership::factory()->create(['person_id' => $member->id, 'is_household_head' => false]);
        $this->assertSame(FamilyAccessDenial::NOT_HOUSEHOLD_HEAD, $this->resolver->headEligibility($member));

        // No membership at all.
        $this->assertSame(FamilyAccessDenial::NO_ACTIVE_MEMBERSHIP, $this->resolver->headEligibility(Person::factory()->create()));

        // Person-level reasons.
        [$deceased] = $this->eligibleHead('333333333', ['life_status' => LifeStatus::DECEASED->value]);
        $this->assertSame(FamilyAccessDenial::PERSON_NOT_ALIVE, $this->resolver->headEligibility($deceased));
        [$unknown] = $this->eligibleHead('444444444', ['life_status' => LifeStatus::UNKNOWN->value]);
        $this->assertSame(FamilyAccessDenial::PERSON_NOT_ALIVE, $this->resolver->headEligibility($unknown));
        [$inactive] = $this->eligibleHead('555555555', ['is_active' => false]);
        $this->assertSame(FamilyAccessDenial::PERSON_INACTIVE, $this->resolver->headEligibility($inactive));
        [$deleted] = $this->eligibleHead('666666666');
        $deleted->delete();
        $this->assertSame(FamilyAccessDenial::PERSON_DELETED, $this->resolver->headEligibility($deleted));

        // Family-level reasons.
        [$a, $inactiveFamily] = $this->eligibleHead('777777777');
        $inactiveFamily->forceFill(['status' => FamilyStatus::INACTIVE->value])->save();
        $this->assertSame(FamilyAccessDenial::FAMILY_NOT_ACTIVE, $this->resolver->headEligibility($a));
        [$b, $deletedFamily] = $this->eligibleHead('888888888');
        $deletedFamily->delete();
        $this->assertSame(FamilyAccessDenial::FAMILY_DELETED, $this->resolver->headEligibility($b));
    }

    public function test_every_denial_code_belongs_to_exactly_one_layer(): void
    {
        $context = [
            FamilyAccessDenial::NO_ACTIVE_MEMBERSHIP, FamilyAccessDenial::NOT_HOUSEHOLD_HEAD,
            FamilyAccessDenial::FAMILY_NOT_ACTIVE, FamilyAccessDenial::FAMILY_DELETED,
        ];
        foreach (FamilyAccessDenial::cases() as $denial) {
            $this->assertSame(! in_array($denial, $context, true), $denial->isIdentity(), $denial->value);
        }
        $this->assertCount(16, FamilyAccessDenial::cases());
        Role::findOrCreate('FAMILY_USER', 'web');
    }
}
