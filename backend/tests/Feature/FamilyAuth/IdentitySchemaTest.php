<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\ManageStaffUsersAction;
use App\Enums\AuthIdentityStatus;
use App\Enums\AuthSecurityEventType;
use App\Enums\CoordinatorScopeType;
use App\Enums\MobileTrustStatus;
use App\Enums\OtpPurpose;
use App\Enums\UserPersonLinkStatus;
use App\Models\AuthOtpChallenge;
use App\Models\AuthSecurityEvent;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use App\Models\CoordinatorScopeAssignment;
use App\Models\FamilyAuthIdentity;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Models\UserPersonLink;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * PWA-1C schema foundation (docs/04 §55b): nullable users.email and the six
 * Family Portal identity tables. Structural guarantees only — the partial
 * unique indexes behave the same on SQLite and PostgreSQL. No lifecycle,
 * activation, OTP or authorization behaviour exists yet. Synthetic data only.
 */
class IdentitySchemaTest extends TestCase
{
    use RefreshDatabase;

    /** Runs the write in a savepoint and expects the database to refuse it. */
    private function assertRejected(callable $write, string $message = ''): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail($message ?: 'The database accepted a row it must refuse.');
    }

    // ------------------------------------------------------------ users.email

    public function test_users_email_is_nullable_and_still_unique(): void
    {
        $a = User::factory()->familySide()->create();
        $b = User::factory()->familySide()->create();

        // Many accounts without an email; never a synthetic one.
        $this->assertNull($a->fresh()->email);
        $this->assertNull($b->fresh()->email);

        User::factory()->create(['email' => 'staff@example.test']);
        $this->assertRejected(fn () => User::factory()->create(['email' => 'staff@example.test']));
    }

    public function test_staff_account_creation_still_requires_an_email(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $super = User::factory()->create();
        $super->assignRole('SUPER_ADMIN');
        $action = app(ManageStaffUsersAction::class);

        foreach ([[], ['email' => null], ['email' => ''], ['email' => 'not-an-email']] as $email) {
            try {
                $action->create($super, ['name' => 'موظف', 'role' => 'DATA_ENTRY', 'password' => 'a-long-test-password', ...$email]);
                $this->fail('A Staff account was created without a valid email.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('email', $e->errors());
            }
        }
        $this->assertSame(1, User::count());
    }

    // ------------------------------------------------------- user_person_links

    public function test_a_person_has_at_most_one_active_or_suspended_link(): void
    {
        $person = Person::factory()->create();
        UserPersonLink::factory()->create(['person_id' => $person->id]);

        $this->assertRejected(fn () => UserPersonLink::factory()->create(['person_id' => $person->id]));
        // A suspended link still occupies the slot.
        $this->assertRejected(fn () => UserPersonLink::factory()->suspended()->create(['person_id' => $person->id]));

        $other = Person::factory()->create();
        UserPersonLink::factory()->suspended()->create(['person_id' => $other->id]);
        $this->assertRejected(fn () => UserPersonLink::factory()->create(['person_id' => $other->id]));
    }

    public function test_a_user_has_at_most_one_active_or_suspended_link(): void
    {
        $user = User::factory()->familySide()->create();
        UserPersonLink::factory()->create(['user_id' => $user->id]);

        $this->assertRejected(fn () => UserPersonLink::factory()->create(['user_id' => $user->id]));
        $this->assertRejected(fn () => UserPersonLink::factory()->suspended()->create(['user_id' => $user->id]));
    }

    public function test_ended_links_are_history_and_free_the_slot(): void
    {
        $user = User::factory()->familySide()->create();
        $person = Person::factory()->create();
        UserPersonLink::factory()->ended()->count(2)->create(['user_id' => $user->id, 'person_id' => $person->id]);
        $current = UserPersonLink::factory()->create(['user_id' => $user->id, 'person_id' => $person->id]);

        $this->assertSame(3, UserPersonLink::count());
        $this->assertSame([$current->id], UserPersonLink::query()->current()->pluck('id')->all());
        $this->assertSame(UserPersonLinkStatus::ACTIVE, $current->fresh()->status);
        $this->assertNotNull($current->uuid);
        $this->assertTrue($user->personLinks()->count() === 3 && $person->userLinks()->count() === 3);
    }

    // -------------------------------------------------- family_auth_identities

    public function test_two_active_identities_can_never_share_a_login_key(): void
    {
        $identity = FamilyAuthIdentity::factory()->create();

        $this->assertRejected(fn () => FamilyAuthIdentity::factory()->create(['login_key' => $identity->login_key]));

        // An obsolete key (SUPERSEDED or SUSPENDED) does not reserve it.
        FamilyAuthIdentity::factory()->superseded()->create(['login_key' => $identity->login_key]);
        FamilyAuthIdentity::factory()->suspended()->create(['login_key' => $identity->login_key]);
        $this->assertSame(3, FamilyAuthIdentity::where('login_key', $identity->login_key)->count());
    }

    public function test_a_user_has_at_most_one_active_or_suspended_identity(): void
    {
        $user = User::factory()->familySide()->create();
        FamilyAuthIdentity::factory()->superseded()->count(2)->create(['user_id' => $user->id]);
        FamilyAuthIdentity::factory()->create(['user_id' => $user->id]);

        $this->assertRejected(fn () => FamilyAuthIdentity::factory()->create(['user_id' => $user->id]));
        $this->assertRejected(fn () => FamilyAuthIdentity::factory()->suspended()->create(['user_id' => $user->id]));
        $this->assertSame(3, $user->familyAuthIdentities()->count());
        $this->assertSame(AuthIdentityStatus::ACTIVE, $user->familyAuthIdentities()->latest('id')->first()->status);
    }

    // ---------------------------------------------------- person_mobile_trusts

    public function test_a_shared_mobile_fingerprint_is_allowed_across_persons(): void
    {
        $fingerprint = hash('sha256', 'shared-synthetic-number');
        $a = PersonMobileTrust::factory()->trusted()->create(['mobile_fingerprint' => $fingerprint]);
        $b = PersonMobileTrust::factory()->trusted()->create(['mobile_fingerprint' => $fingerprint]);
        $c = PersonMobileTrust::factory()->create(['mobile_fingerprint' => $fingerprint]);

        // Not unique: each Person is verified individually.
        $this->assertCount(3, array_unique([$a->person_id, $b->person_id, $c->person_id]));
        $this->assertSame(3, PersonMobileTrust::where('mobile_fingerprint', $fingerprint)->count());
    }

    public function test_a_person_has_at_most_one_trusted_and_one_pending_trust(): void
    {
        $person = Person::factory()->create();
        PersonMobileTrust::factory()->trusted()->create(['person_id' => $person->id]);
        PersonMobileTrust::factory()->create(['person_id' => $person->id]);

        $this->assertRejected(fn () => PersonMobileTrust::factory()->trusted()->create(['person_id' => $person->id]));
        $this->assertRejected(fn () => PersonMobileTrust::factory()->create(['person_id' => $person->id]));

        // History is unlimited.
        PersonMobileTrust::factory()->stale()->count(2)->create(['person_id' => $person->id]);
        PersonMobileTrust::factory()->revoked()->create(['person_id' => $person->id]);
        $this->assertSame(5, $person->mobileTrusts()->count());
        $this->assertSame(1, $person->mobileTrusts()->where('status', MobileTrustStatus::TRUSTED->value)->count());
    }

    public function test_assistance_is_recorded_separately_from_the_grant(): void
    {
        $assistant = User::factory()->familySide()->create();
        $trust = PersonMobileTrust::factory()->trusted()->create(['assisted_by' => $assistant->id, 'assisted_at' => now()]);

        $this->assertTrue($trust->assistant->is($assistant));
        $this->assertFalse($trust->verifier->is($assistant));
        $this->assertNotNull($trust->verified_at);
    }

    public function test_no_trust_row_exists_for_a_person_with_only_a_stored_mobile(): void
    {
        // An imported mobile is UNVERIFIED by absence: nothing is derived.
        $person = Person::factory()->create(['mobile' => '0591234567']);

        $this->assertSame(0, $person->mobileTrusts()->count());
        $this->assertSame(0, PersonMobileTrust::count());
    }

    // ----------------------------------------------------- auth_otp_challenges

    public function test_one_open_challenge_per_person_and_purpose(): void
    {
        $challenge = AuthOtpChallenge::factory()->create();
        $same = ['person_id' => $challenge->person_id, 'mobile_trust_id' => $challenge->mobile_trust_id];

        $this->assertRejected(fn () => AuthOtpChallenge::factory()->create($same));

        // Another purpose is a different slot.
        $user = User::factory()->familySide()->create();
        AuthOtpChallenge::factory()->create([...$same, 'purpose' => OtpPurpose::PASSWORD_RESET->value, 'user_id' => $user->id]);

        // An EXPIRED challenge still occupies the slot (PWA-1E supersedes it).
        $challenge->forceFill(['expires_at' => now()->subHour()])->save();
        $this->assertRejected(fn () => AuthOtpChallenge::factory()->create($same));
    }

    public function test_finished_challenges_free_the_slot(): void
    {
        $person = Person::factory()->create();
        $trust = PersonMobileTrust::factory()->trusted()->create(['person_id' => $person->id]);
        $same = ['person_id' => $person->id, 'mobile_trust_id' => $trust->id];

        AuthOtpChallenge::factory()->superseded()->create($same);
        AuthOtpChallenge::factory()->consumed()->create($same);
        AuthOtpChallenge::factory()->locked()->create($same);
        $open = AuthOtpChallenge::factory()->create($same);

        $this->assertSame(4, AuthOtpChallenge::where('person_id', $person->id)->count());
        $this->assertSame(OtpPurpose::ACTIVATION, $open->fresh()->purpose);
        $this->assertTrue($open->mobileTrust->is($trust));
    }

    // ---------------------------------------------------- auth_security_events

    public function test_security_events_are_append_only(): void
    {
        $event = AuthSecurityEvent::factory()->create(['metadata' => ['attempts' => 3, 'purpose' => 'ACTIVATION']]);

        $this->assertSame(AuthSecurityEventType::LOGIN_FAILED, $event->fresh()->event_type);
        // jsonb (PostgreSQL) does not keep key order: compare the pairs.
        $metadata = $event->fresh()->metadata;
        ksort($metadata);
        $this->assertSame(['attempts' => 3, 'purpose' => 'ACTIVATION'], $metadata);
        $this->assertNotNull($event->fresh()->created_at);

        try {
            $event->update(['reason_code' => 'CHANGED']);
            $this->fail('A security event was updated.');
        } catch (LogicException) {
        }
        try {
            $event->delete();
            $this->fail('A security event was deleted.');
        } catch (LogicException) {
        }
        $this->assertSame(1, AuthSecurityEvent::count());
        $this->assertNull($event->fresh()->reason_code);
    }

    public function test_security_event_metadata_cannot_carry_identifiers_or_secrets(): void
    {
        foreach ([
            ['national_id' => 'ANYTHING'],          // key not allow-listed
            ['mobile' => 'ANYTHING'],
            ['otp' => 123456],
            ['password' => 'ANYTHING'],
            ['purpose' => '123456789'],             // a digit string is not a code
            ['purpose' => '0591234567'],
            ['attempts' => 123456],                 // larger than any counter
            ['attempts' => '123456'],
            ['purpose' => 'free text'],
            ['purpose' => ['nested' => 'ACTIVATION']],
            ['attempts' => -1],
            ['attempts' => 1.5],
        ] as $metadata) {
            try {
                AuthSecurityEvent::factory()->create(['metadata' => $metadata]);
                $this->fail('Unsafe security event metadata was stored: '.json_encode($metadata));
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, AuthSecurityEvent::count());
    }

    public function test_security_events_have_no_free_text_or_raw_identifier_column(): void
    {
        $columns = Schema::getColumnListing('auth_security_events');
        sort($columns);

        $this->assertSame([
            'actor_user_id', 'created_at', 'event_type', 'id', 'ip', 'login_key', 'metadata', 'mobile_trust_id',
            'otp_challenge_uuid', 'outcome', 'person_id', 'reason_code', 'user_agent_hash', 'user_id',
            'user_person_link_id', 'uuid',
        ], $columns);
    }

    public function test_a_security_event_outlives_its_purged_challenge(): void
    {
        $challenge = AuthOtpChallenge::factory()->consumed()->create();
        $event = AuthSecurityEvent::factory()->create([
            'event_type' => AuthSecurityEventType::OTP_CONSUMED->value,
            'outcome' => 'SUCCESS',
            'person_id' => $challenge->person_id,
            'otp_challenge_uuid' => $challenge->uuid,
        ]);

        // No foreign key: the 90-day purge of challenges never touches events.
        $challenge->delete();

        $this->assertSame(0, AuthOtpChallenge::count());
        $this->assertSame($challenge->uuid, $event->fresh()->otp_challenge_uuid);
    }

    // ------------------------------------------- coordinator_scope_assignments

    /** @return array{0: Clan, 1: BranchGroup, 2: Branch, 3: Branch} */
    private function structure(string $code = 'TEST_CLAN'): array
    {
        $clan = Clan::query()->firstOrCreate(['code' => $code], ['name' => 'عشيرة '.$code]);
        $group = BranchGroup::create(['clan_id' => $clan->id, 'code' => 'G01', 'name' => 'مجموعة تجريبية', 'sort_order' => 1]);
        $a = Branch::create(['branch_group_id' => $group->id, 'clan_id' => $clan->id, 'code' => 'BR_A', 'name' => 'فرع أ', 'sort_order' => 1]);
        $b = Branch::create(['branch_group_id' => null, 'clan_id' => $clan->id, 'code' => 'BR_B', 'name' => 'فرع ب', 'sort_order' => 2]);

        return [$clan, $group, $a, $b];
    }

    public function test_the_same_active_scope_cannot_be_assigned_twice_at_any_level(): void
    {
        [$clan, $group, $branch] = $this->structure();
        $user = User::factory()->familySide()->create();
        $base = ['user_id' => $user->id, 'clan_id' => $clan->id];
        $levels = [
            [...$base, 'scope_type' => CoordinatorScopeType::CLAN->value],
            [...$base, 'scope_type' => CoordinatorScopeType::BRANCH_GROUP->value, 'branch_group_id' => $group->id],
            [...$base, 'scope_type' => CoordinatorScopeType::BRANCH->value, 'branch_id' => $branch->id],
        ];

        foreach ($levels as $level) {
            CoordinatorScopeAssignment::factory()->create($level);
            $this->assertRejected(
                fn () => CoordinatorScopeAssignment::factory()->create($level),
                'A duplicate active '.$level['scope_type'].' assignment was accepted.',
            );
        }

        // Several different scopes for one coordinator are valid.
        $this->assertSame(3, $user->coordinatorScopeAssignments()->active()->count());
    }

    public function test_a_coordinator_may_hold_several_scopes_and_reassign_after_revocation(): void
    {
        [$clan, , $branchA, $branchB] = $this->structure();
        $user = User::factory()->familySide()->create();
        $other = User::factory()->familySide()->create();
        $branch = fn (Branch $b) => ['clan_id' => $clan->id, 'scope_type' => CoordinatorScopeType::BRANCH->value, 'branch_id' => $b->id];

        CoordinatorScopeAssignment::factory()->create(['user_id' => $user->id, ...$branch($branchA)]);
        CoordinatorScopeAssignment::factory()->create(['user_id' => $user->id, ...$branch($branchB)]);
        // The same scope for another coordinator is fine.
        CoordinatorScopeAssignment::factory()->create(['user_id' => $other->id, ...$branch($branchA)]);

        // Revoked assignments are history and do not block a new one.
        CoordinatorScopeAssignment::factory()->revoked()->count(2)->create(['user_id' => $other->id, ...$branch($branchB)]);
        $again = CoordinatorScopeAssignment::factory()->create(['user_id' => $other->id, ...$branch($branchB)]);

        $this->assertSame(2, $user->coordinatorScopeAssignments()->active()->count());
        $this->assertSame(2, $other->coordinatorScopeAssignments()->active()->count());
        $this->assertSame(4, $other->coordinatorScopeAssignments()->count());
        $this->assertSame(CoordinatorScopeType::BRANCH, $again->fresh()->scope_type);
        $this->assertTrue($again->branch->is($branchB) && $again->clan->is($clan));
    }

    public function test_a_group_or_branch_must_belong_to_the_assignment_clan(): void
    {
        [, $group, $branch] = $this->structure();
        $elsewhere = Clan::query()->firstOrCreate(['code' => 'OTHER_CLAN'], ['name' => 'عشيرة أخرى']);
        $user = User::factory()->familySide()->create();
        $base = ['user_id' => $user->id, 'clan_id' => $elsewhere->id];

        $this->assertRejected(fn () => CoordinatorScopeAssignment::factory()->create(
            [...$base, 'scope_type' => CoordinatorScopeType::BRANCH->value, 'branch_id' => $branch->id]
        ));
        $this->assertRejected(fn () => CoordinatorScopeAssignment::factory()->create(
            [...$base, 'scope_type' => CoordinatorScopeType::BRANCH_GROUP->value, 'branch_group_id' => $group->id]
        ));
    }

    // ------------------------------------------------------------- foundation

    public function test_identity_history_is_protected_by_restrict_foreign_keys(): void
    {
        $link = UserPersonLink::factory()->create();
        $trust = PersonMobileTrust::factory()->trusted()->create();
        $identity = FamilyAuthIdentity::factory()->create();

        $this->assertRejected(fn () => DB::table('users')->where('id', $link->user_id)->delete());
        $this->assertRejected(fn () => DB::table('persons')->where('id', $link->person_id)->delete());
        $this->assertRejected(fn () => DB::table('users')->where('id', $trust->verified_by)->delete());
        $this->assertRejected(fn () => DB::table('users')->where('id', $identity->user_id)->delete());
        $this->assertSame(1, UserPersonLink::count());
    }

    public function test_fingerprints_and_hashes_are_hidden_from_serialization(): void
    {
        $this->assertArrayNotHasKey('login_key', FamilyAuthIdentity::factory()->create()->toArray());
        $this->assertArrayNotHasKey('mobile_fingerprint', PersonMobileTrust::factory()->create()->toArray());
        $this->assertArrayNotHasKey('code_hash', AuthOtpChallenge::factory()->create()->toArray());
        $this->assertArrayNotHasKey('login_key', AuthSecurityEvent::factory()->create(['login_key' => hash('sha256', 'x')])->toArray());
    }

    public function test_the_foundation_creates_no_rows_and_no_registry_constraint(): void
    {
        // Persons with a mobile and a National ID exist; nothing is derived.
        Person::factory()->count(3)->create(['mobile' => '0591234567']);
        // persons.national_id received no UNIQUE constraint (docs/11 §30a).
        Person::factory()->count(2)->create(['national_id' => '123456789']);

        foreach (['user_person_links', 'family_auth_identities', 'person_mobile_trusts', 'auth_otp_challenges',
            'auth_security_events', 'coordinator_scope_assignments'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame(0, User::count());
    }
}
