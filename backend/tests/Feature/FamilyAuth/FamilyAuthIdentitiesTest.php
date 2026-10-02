<?php

namespace Tests\Feature\FamilyAuth;

use App\Enums\AuthIdentityStatus;
use App\Enums\AuthIdentitySupersedeReason;
use App\Enums\FingerprintContext;
use App\Exceptions\FamilyIdentityException;
use App\Models\FamilyAuthIdentity;
use App\Models\Person;
use App\Support\AccountSide;
use App\Support\FamilyAuth\FamilyAuthIdentities;
use App\Support\FamilyAuth\KeyedFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1D: the Family Auth identity service (docs/11 §30a) and the account
 * side classifier it relies on. Synthetic data only.
 */
class FamilyAuthIdentitiesTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private FamilyAuthIdentities $identities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        $this->identities = app(FamilyAuthIdentities::class);
    }

    public function test_create_stores_a_fingerprint_never_the_national_id(): void
    {
        $user = $this->familyUser();
        $person = Person::factory()->create(['national_id' => ' ١٢٣-٤٥٦-٧٨٩ ']);

        $identity = DB::transaction(fn () => $this->identities->create($user, $person))->fresh();

        $this->assertSame(AuthIdentityStatus::ACTIVE, $identity->status);
        $this->assertSame(1, $identity->key_version);
        $this->assertSame(KeyedFingerprint::of(FingerprintContext::LOGIN_ID, '123456789'), $identity->login_key);
        $this->assertStringNotContainsString('123456789', $identity->login_key);
        $this->assertTrue($this->identities->isConsistent($identity, $person));
        $this->assertTrue($this->identities->current($user)->is($identity));
    }

    public function test_create_refuses_an_invalid_national_id_a_second_identity_and_a_taken_key(): void
    {
        $user = $this->familyUser();
        foreach ([null, '12345678', '12345678X'] as $stored) {
            $person = Person::factory()->create(['national_id' => $stored]);
            try {
                DB::transaction(fn () => $this->identities->create($user, $person));
                $this->fail('An identity was created from an invalid National ID.');
            } catch (FamilyIdentityException $e) {
                $this->assertSame(FamilyIdentityException::NATIONAL_ID_INVALID, $e->reason);
            }
        }

        $person = Person::factory()->create(['national_id' => '123456789']);
        DB::transaction(fn () => $this->identities->create($user, $person));
        try {
            DB::transaction(fn () => $this->identities->create($user, $person));
            $this->fail('A second current identity was created.');
        } catch (FamilyIdentityException $e) {
            $this->assertSame(FamilyIdentityException::IDENTITY_EXISTS, $e->reason);
        }

        // Another account can never take an ACTIVE key.
        $other = $this->familyUser();
        $twin = Person::factory()->create(['national_id' => '123 456 789']);
        try {
            DB::transaction(fn () => $this->identities->create($other, $twin));
            $this->fail('An ACTIVE login key was duplicated.');
        } catch (FamilyIdentityException $e) {
            $this->assertSame(FamilyIdentityException::LOGIN_KEY_TAKEN, $e->reason);
            $this->assertStringNotContainsString('123', $e->getMessage());
        }
        $this->assertSame(1, FamilyAuthIdentity::count());
    }

    public function test_identity_writes_require_a_transaction(): void
    {
        $user = $this->familyUser();
        $person = Person::factory()->create(['national_id' => '123456789']);
        // RefreshDatabase already wraps the test: leave it to prove the rule.
        DB::rollBack();
        try {
            $this->identities->create($user, $person);
            $this->fail('An identity was written outside a transaction.');
        } catch (LogicException) {
        } finally {
            DB::beginTransaction();
        }
        $this->assertTrue(true);
    }

    public function test_is_consistent_follows_the_current_stored_national_id(): void
    {
        $a = $this->activatedHead('123456789');

        $this->assertTrue($this->identities->isConsistent($a['identity'], $a['person']));

        $a['person']->forceFill(['national_id' => '987654321'])->save();
        $this->assertFalse($this->identities->isConsistent($a['identity'], $a['person']));

        $a['person']->forceFill(['national_id' => 'not an id'])->save();
        $this->assertFalse($this->identities->isConsistent($a['identity'], $a['person']));
    }

    public function test_find_by_national_id_input_returns_only_an_active_identity(): void
    {
        $a = $this->activatedHead('123456789');

        foreach (['123456789', ' 123 456 789 ', '١٢٣٤٥٦٧٨٩', '123-456-789'] as $input) {
            $this->assertTrue($this->identities->findByNationalIdInput($input)->is($a['identity']), $input);
        }
        foreach (['987654321', '12345678', '12a345678-9x', '', null, 123456789, ['123456789']] as $input) {
            $this->assertNull($this->identities->findByNationalIdInput($input));
        }

        // SUSPENDED and SUPERSEDED identities never authenticate.
        $a['identity']->forceFill(['status' => AuthIdentityStatus::SUSPENDED->value])->save();
        $this->assertNull($this->identities->findByNationalIdInput('123456789'));
        DB::transaction(fn () => $this->identities->supersede($a['identity'], AuthIdentitySupersedeReason::KEY_ROTATION));
        $this->assertNull($this->identities->findByNationalIdInput('123456789'));
        $this->assertSame(AuthIdentitySupersedeReason::KEY_ROTATION, $a['identity']->fresh()->supersede_reason);
        $this->assertNotNull($a['identity']->fresh()->superseded_at);
    }

    public function test_lookup_supports_the_current_and_the_previous_key_version(): void
    {
        $old = $this->activatedHead('123456789');

        // The key rotates to version 2; version 1 becomes the previous key.
        config(['family_auth.fingerprint' => [
            'key' => 'another-test-only-family-auth-key-9876543210', 'key_version' => 2,
            'previous_key' => 'test-only-family-auth-key-0123456789-abcdef', 'previous_key_version' => 1,
        ]]);
        $user = $this->familyUser();
        $person = Person::factory()->create(['national_id' => '987654321']);
        $new = DB::transaction(fn () => $this->identities->create($user, $person));

        $this->assertSame(2, $new->key_version);
        $this->assertTrue($this->identities->findByNationalIdInput('987654321')->is($new));
        $this->assertTrue($this->identities->findByNationalIdInput('123456789')->is($old['identity']));

        // Once the previous key is gone, a version-1 identity is unreachable.
        config(['family_auth.fingerprint.previous_key' => null, 'family_auth.fingerprint.previous_key_version' => null]);
        $this->assertNull($this->identities->findByNationalIdInput('123456789'));
        $this->assertTrue($this->identities->findByNationalIdInput('987654321')->is($new));
    }

    public function test_account_side_classification_ignores_role_order(): void
    {
        $cases = [
            [['DATA_ENTRY'], AccountSide::STAFF],
            [['SUPER_ADMIN'], AccountSide::STAFF],
            [['FAMILY_USER'], AccountSide::FAMILY],
            [['FAMILY_USER', 'COORDINATOR'], AccountSide::FAMILY],
            [['COORDINATOR', 'FAMILY_USER'], AccountSide::FAMILY],
            [['COORDINATOR'], AccountSide::INVALID],
            [['DATA_ENTRY', 'FAMILY_USER'], AccountSide::INVALID],
            [['FAMILY_USER', 'DATA_ENTRY'], AccountSide::INVALID],
            [['SUPER_ADMIN', 'COORDINATOR'], AccountSide::INVALID],
            [['COORDINATOR', 'FAMILY_USER', 'REVIEWER'], AccountSide::INVALID],
            [[], AccountSide::NONE],
            [['SOME_TEST_ROLE'], AccountSide::NONE],
        ];
        foreach ($cases as [$roles, $expected]) {
            $user = $this->familyUser($roles);
            $label = implode('+', $roles) ?: '(none)';

            $this->assertSame($expected, AccountSide::of($user), $label);
            $this->assertSame($expected === AccountSide::STAFF, AccountSide::isStaff($user), $label);
            $this->assertSame($expected === AccountSide::FAMILY, AccountSide::isFamily($user), $label);
            $this->assertSame(
                array_intersect($roles, AccountSide::FAMILY_SIDE_ROLES) !== [],
                AccountSide::holdsFamilySideRole($user),
                $label,
            );
        }
    }

    public function test_the_staff_role_is_canonical_not_the_first_role(): void
    {
        $this->assertSame('DATA_ENTRY', AccountSide::staffRole($this->familyUser(['DATA_ENTRY'])));
        // Whatever order the roles were attached in, the answer is the same.
        $this->assertSame('ADMINISTRATOR', AccountSide::staffRole($this->familyUser(['REVIEWER', 'ADMINISTRATOR'])));
        $this->assertSame('ADMINISTRATOR', AccountSide::staffRole($this->familyUser(['ADMINISTRATOR', 'REVIEWER'])));
        // Never a role for an account that is not Staff-side.
        foreach ([['FAMILY_USER'], ['COORDINATOR'], ['DATA_ENTRY', 'FAMILY_USER'], []] as $roles) {
            $this->assertNull(AccountSide::staffRole($this->familyUser($roles)));
        }
    }
}
