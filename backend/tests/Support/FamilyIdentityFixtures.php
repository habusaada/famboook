<?php

namespace Tests\Support;

use App\Enums\AuthIdentityStatus;
use App\Enums\FingerprintContext;
use App\Models\Family;
use App\Models\FamilyAuthIdentity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\FamilyAuth\KeyedFingerprint;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Synthetic Family Portal identities for tests (PWA-1D). The fingerprint key
 * is a throwaway test string set through config(); nothing here is real.
 */
trait FamilyIdentityFixtures
{
    protected function useFamilyAuthKey(): void
    {
        config(['family_auth.fingerprint' => [
            'key' => 'test-only-family-auth-key-0123456789-abcdef',
            'key_version' => 1,
            'previous_key' => null,
            'previous_key_version' => null,
        ]]);
    }

    /** A family-side account holding FAMILY_USER (and any extra roles). */
    protected function familyUser(array $roles = ['FAMILY_USER'], array $attributes = []): User
    {
        $user = User::factory()->familySide()->create($attributes);
        foreach ($roles as $role) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }

        return $user;
    }

    /**
     * An eligible household head: an ALIVE Person with a nine-digit National
     * ID who is the active head of an ACTIVE Family.
     *
     * @return array{0: Person, 1: Family, 2: FamilyMembership}
     */
    protected function eligibleHead(string $nationalId = '123456789', array $person = []): array
    {
        $person = Person::factory()->create(['national_id' => $nationalId, ...$person]);
        $family = Family::factory()->create();
        $membership = FamilyMembership::factory()->create([
            'family_id' => $family->id, 'person_id' => $person->id, 'is_household_head' => true,
        ]);

        return [$person, $family, $membership];
    }

    /**
     * A fully activated family-side account, built row by row (no action):
     * ACTIVE link and ACTIVE identity matching the Person's National ID.
     *
     * @return array{user: User, person: Person, family: Family, membership: FamilyMembership, link: UserPersonLink, identity: FamilyAuthIdentity}
     */
    protected function activatedHead(string $nationalId = '123456789', array $roles = ['FAMILY_USER']): array
    {
        [$person, $family, $membership] = $this->eligibleHead($nationalId);
        $user = $this->familyUser($roles);
        $link = UserPersonLink::factory()->create(['user_id' => $user->id, 'person_id' => $person->id]);
        $identity = FamilyAuthIdentity::factory()->create([
            'user_id' => $user->id,
            'login_key' => KeyedFingerprint::of(FingerprintContext::LOGIN_ID, $nationalId),
            'key_version' => 1,
            'status' => AuthIdentityStatus::ACTIVE->value,
        ]);

        return compact('user', 'person', 'family', 'membership', 'link', 'identity');
    }

    protected function sessionRowFor(User $user): void
    {
        DB::table('sessions')->insert([
            'id' => bin2hex(random_bytes(20)), 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => '', 'last_activity' => time(),
        ]);
    }
}
