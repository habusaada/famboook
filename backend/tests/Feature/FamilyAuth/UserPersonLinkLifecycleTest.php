<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\EndUserPersonLinkAction;
use App\Actions\EstablishFamilyIdentityAction;
use App\Actions\ResumeUserPersonLinkAction;
use App\Actions\SuspendUserPersonLinkAction;
use App\Enums\AuthIdentityStatus;
use App\Enums\AuthIdentitySupersedeReason;
use App\Enums\AuthSecurityEventType;
use App\Enums\FamilyAccessDenial;
use App\Enums\LifeStatus;
use App\Enums\UserPersonLinkEndReason;
use App\Enums\UserPersonLinkStatus;
use App\Enums\UserPersonLinkSuspensionReason;
use App\Enums\UserPersonLinkType;
use App\Enums\UserPersonLinkVerificationMethod;
use App\Exceptions\FamilyIdentityException;
use App\Models\AuthSecurityEvent;
use App\Models\FamilyAuthIdentity;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAuthIdentities;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1D: the User-Person Link lifecycle (docs/05 §53b). Domain Actions
 * only — there is no endpoint and no UI. Synthetic data only.
 */
class UserPersonLinkLifecycleTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private FamilyAccessResolver $resolver;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        config(['session.driver' => 'database']);
        $this->resolver = app(FamilyAccessResolver::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMINISTRATOR');
    }

    /** @return list<string> */
    private function events(): array
    {
        return AuthSecurityEvent::orderBy('id')->get()->map(fn ($e) => $e->event_type->value)->all();
    }

    private function establish(User $user, Person $person): UserPersonLink
    {
        return DB::transaction(fn () => app(EstablishFamilyIdentityAction::class)->handle($user, $person));
    }

    private function assertRefused(string $reason, callable $operation): void
    {
        try {
            $operation();
        } catch (FamilyIdentityException $e) {
            $this->assertSame($reason, $e->reason);

            return;
        }
        $this->fail("The operation was not refused with {$reason}.");
    }

    // -------------------------------------------------------------- establish

    public function test_establish_creates_an_active_link_and_identity_together(): void
    {
        [$person, $family] = $this->eligibleHead('123456789');
        $user = $this->familyUser();

        $link = $this->establish($user, $person)->fresh();

        $this->assertSame(UserPersonLinkStatus::ACTIVE, $link->status);
        $this->assertSame(UserPersonLinkType::SELF, $link->link_type);
        $this->assertSame(UserPersonLinkVerificationMethod::SYSTEM_OTP_ACTIVATION, $link->verification_method);
        // Verified by the system process, not by a person.
        $this->assertNull($link->verified_by);
        $this->assertNotNull($link->verified_at);
        $this->assertNotNull($link->activated_at);

        $identity = app(FamilyAuthIdentities::class)->current($user);
        $this->assertSame(AuthIdentityStatus::ACTIVE, $identity->status);
        $this->assertTrue(app(FamilyAuthIdentities::class)->findByNationalIdInput('123456789')->is($identity));

        $result = $this->resolver->familyContext($user);
        $this->assertTrue($result->hasFamilyContext());
        $this->assertTrue($result->family->is($family));

        $this->assertSame(['LINK_ACTIVATED'], $this->events());
        $event = AuthSecurityEvent::sole();
        $this->assertSame($person->id, $event->person_id);
        $this->assertSame($user->id, $event->user_id);
        $this->assertNull($event->actor_user_id);
        $this->assertStringNotContainsString('123456789', json_encode($event->getAttributes()));
    }

    public function test_establish_must_run_inside_a_transaction(): void
    {
        [$person] = $this->eligibleHead();
        $user = $this->familyUser();
        DB::rollBack();
        try {
            app(EstablishFamilyIdentityAction::class)->handle($user, $person);
            $this->fail('A Family identity was established outside a transaction.');
        } catch (LogicException) {
        } finally {
            DB::beginTransaction();
        }
        $this->assertTrue(true);
    }

    public function test_establish_refuses_accounts_that_are_not_valid_family_side_accounts(): void
    {
        [$person] = $this->eligibleHead();

        foreach ([[], ['COORDINATOR'], ['DATA_ENTRY'], ['DATA_ENTRY', 'FAMILY_USER'], ['FAMILY_USER', 'SUPER_ADMIN']] as $roles) {
            $user = $this->familyUser($roles);
            $this->assertRefused(FamilyIdentityException::NOT_FAMILY_SIDE, fn () => $this->establish($user, $person));
        }
        $inactive = $this->familyUser(['FAMILY_USER'], ['is_active' => false]);
        $this->assertRefused(FamilyIdentityException::USER_INACTIVE, fn () => $this->establish($inactive, $person));

        $this->assertSame(0, UserPersonLink::count());
        $this->assertSame(0, FamilyAuthIdentity::count());
        $this->assertSame([], $this->events());
    }

    public function test_establish_refuses_a_person_who_is_not_an_eligible_household_head(): void
    {
        $user = $this->familyUser();
        [$deceased] = $this->eligibleHead('111111111', ['life_status' => LifeStatus::DECEASED->value]);
        [$unknown] = $this->eligibleHead('222222222', ['life_status' => LifeStatus::UNKNOWN->value]);
        $notHead = Person::factory()->create(['national_id' => '333333333']);
        [$badId] = $this->eligibleHead('12345678');
        [$noId] = $this->eligibleHead('444444444');
        DB::table('persons')->where('id', $noId->id)->update(['national_id' => null]);

        foreach ([$deceased, $unknown, $notHead] as $person) {
            try {
                $this->establish($user, $person);
                $this->fail('An ineligible Person was linked.');
            } catch (FamilyIdentityException $e) {
                $this->assertSame(FamilyIdentityException::NOT_ELIGIBLE, $e->reason);
                // The internal reason is a resolver code, never shown to a client.
                $this->assertNotNull(FamilyAccessDenial::tryFrom((string) $e->detail));
                $this->assertStringNotContainsString((string) $e->detail, $e->getMessage());
            }
        }
        $this->assertRefused(FamilyIdentityException::NATIONAL_ID_INVALID, fn () => $this->establish($user, $badId));
        $this->assertRefused(FamilyIdentityException::NATIONAL_ID_INVALID, fn () => $this->establish($user, $noId->fresh()));
        $this->assertSame(0, UserPersonLink::count());
        $this->assertSame(0, FamilyAuthIdentity::count());
    }

    public function test_establish_allows_one_current_link_per_user_and_per_person(): void
    {
        [$person] = $this->eligibleHead('123456789');
        [$other] = $this->eligibleHead('987654321');
        $user = $this->familyUser();
        $this->establish($user, $person);

        // The Person is taken, and the User is taken.
        $this->assertRefused(FamilyIdentityException::LINK_EXISTS, fn () => $this->establish($this->familyUser(), $person));
        $this->assertRefused(FamilyIdentityException::LINK_EXISTS, fn () => $this->establish($user, $other));

        // A suspended link still occupies the slot.
        app(SuspendUserPersonLinkAction::class)->handle($this->admin, UserPersonLink::sole(), UserPersonLinkSuspensionReason::ADMINISTRATIVE);
        $this->assertRefused(FamilyIdentityException::LINK_EXISTS, fn () => $this->establish($this->familyUser(), $person));

        $this->assertSame(1, UserPersonLink::count());
        $this->assertSame(1, FamilyAuthIdentity::count());
    }

    public function test_a_failed_establish_leaves_nothing_behind(): void
    {
        // Another account already holds this login key (a duplicate Person
        // record): the link is created first, then the identity fails — and
        // the caller's transaction takes the link down with it.
        $this->activatedHead('123456789');
        [$twin] = $this->eligibleHead('123456789');
        $user = $this->familyUser();

        $this->assertRefused(FamilyIdentityException::LOGIN_KEY_TAKEN, fn () => $this->establish($user, $twin));

        $this->assertSame(0, UserPersonLink::where('user_id', $user->id)->count());
        $this->assertSame(0, FamilyAuthIdentity::where('user_id', $user->id)->count());
        $this->assertSame([], $this->events());
    }

    // --------------------------------------------------------- suspend/resume

    public function test_suspend_blocks_access_revokes_sessions_and_keeps_identity_and_account(): void
    {
        $a = $this->activatedHead();
        $this->sessionRowFor($a['user']);
        $this->sessionRowFor($a['user']);

        $link = app(SuspendUserPersonLinkAction::class)
            ->handle($this->admin, $a['link'], UserPersonLinkSuspensionReason::REPORTED_COMPROMISE)->fresh();

        $this->assertSame(UserPersonLinkStatus::SUSPENDED, $link->status);
        $this->assertSame('REPORTED_COMPROMISE', $link->suspension_reason);
        $this->assertSame($this->admin->id, $link->suspended_by);
        $this->assertNotNull($link->suspended_at);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $a['user']->id)->count());
        // The identity and the account are separate concepts: both untouched.
        $this->assertSame(AuthIdentityStatus::ACTIVE, $a['identity']->fresh()->status);
        $this->assertTrue($a['user']->fresh()->is_active);
        $this->assertSame(FamilyAccessDenial::LINK_SUSPENDED, $this->resolver->identity($a['user']->fresh())->denial);
        $this->assertSame(['SESSIONS_REVOKED', 'LINK_SUSPENDED'], $this->events());
    }

    public function test_resume_restores_an_active_link(): void
    {
        $a = $this->activatedHead();
        app(SuspendUserPersonLinkAction::class)->handle($this->admin, $a['link'], UserPersonLinkSuspensionReason::UNDER_INVESTIGATION);

        $link = app(ResumeUserPersonLinkAction::class)->handle($this->admin, $a['link'])->fresh();

        $this->assertSame(UserPersonLinkStatus::ACTIVE, $link->status);
        $this->assertNull($link->suspended_at);
        $this->assertNull($link->suspended_by);
        $this->assertNull($link->suspension_reason);
        // The original verification is unchanged.
        $this->assertNotNull($link->verified_at);
        $this->assertTrue($this->resolver->familyContext($a['user']->fresh())->hasFamilyContext());
        $this->assertSame(['SESSIONS_REVOKED', 'LINK_SUSPENDED', 'LINK_RESUMED'], $this->events());
        // The suspension stays in the audit trail.
        $this->assertSame('UNDER_INVESTIGATION', AuthSecurityEvent::where('event_type', 'LINK_SUSPENDED')->sole()->reason_code);
    }

    public function test_suspension_can_be_repeated_after_a_resume(): void
    {
        $a = $this->activatedHead();
        $suspend = app(SuspendUserPersonLinkAction::class);
        $resume = app(ResumeUserPersonLinkAction::class);

        $suspend->handle($this->admin, $a['link'], UserPersonLinkSuspensionReason::ADMINISTRATIVE);
        $resume->handle($this->admin, $a['link']);
        $suspend->handle($this->admin, $a['link'], UserPersonLinkSuspensionReason::ADMINISTRATIVE);

        $this->assertSame(UserPersonLinkStatus::SUSPENDED, $a['link']->fresh()->status);
        $this->assertSame(1, UserPersonLink::count());
    }

    // -------------------------------------------------------------------- end

    public function test_ending_a_link_supersedes_the_identity_and_keeps_the_account(): void
    {
        $a = $this->activatedHead();
        $this->sessionRowFor($a['user']);

        $link = app(EndUserPersonLinkAction::class)
            ->handle($this->admin, $a['link'], UserPersonLinkEndReason::IDENTITY_ERROR)->fresh();

        $this->assertSame(UserPersonLinkStatus::ENDED, $link->status);
        $this->assertSame('IDENTITY_ERROR', $link->end_reason);
        $this->assertSame($this->admin->id, $link->ended_by);
        $this->assertNotNull($link->ended_at);

        // The identity is retired with the link — SUPERSEDED / LINK_ENDED,
        // never an indefinitely SUSPENDED one.
        $identity = $a['identity']->fresh();
        $this->assertSame(AuthIdentityStatus::SUPERSEDED, $identity->status);
        $this->assertSame(AuthIdentitySupersedeReason::LINK_ENDED, $identity->supersede_reason);
        $this->assertNotNull($identity->superseded_at);
        $this->assertNull(app(FamilyAuthIdentities::class)->current($a['user']));
        $this->assertNull(app(FamilyAuthIdentities::class)->findByNationalIdInput('123456789'));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $a['user']->id)->count());

        // The account itself is NOT deactivated: that is a separate operation.
        $this->assertTrue($a['user']->fresh()->is_active);
        $this->assertTrue($a['user']->fresh()->hasRole('FAMILY_USER'));
        // It is simply unusable for the Family Portal.
        $this->assertSame(FamilyAccessDenial::NO_LINK, $this->resolver->identity($a['user']->fresh())->denial);

        $this->assertSame(['SESSIONS_REVOKED', 'LINK_ENDED'], $this->events());
        $this->assertSame('IDENTITY_ERROR', AuthSecurityEvent::where('event_type', 'LINK_ENDED')->sole()->reason_code);
    }

    public function test_a_suspended_link_can_be_ended(): void
    {
        $a = $this->activatedHead();
        app(SuspendUserPersonLinkAction::class)->handle($this->admin, $a['link'], UserPersonLinkSuspensionReason::UNDER_INVESTIGATION);

        $link = app(EndUserPersonLinkAction::class)->handle($this->admin, $a['link'], UserPersonLinkEndReason::ADMINISTRATIVE)->fresh();

        $this->assertSame(UserPersonLinkStatus::ENDED, $link->status);
        $this->assertSame(AuthIdentitySupersedeReason::LINK_ENDED, $a['identity']->fresh()->supersede_reason);
    }

    public function test_ended_is_terminal(): void
    {
        $a = $this->activatedHead();
        app(EndUserPersonLinkAction::class)->handle($this->admin, $a['link'], UserPersonLinkEndReason::ADMINISTRATIVE);

        $invalid = FamilyIdentityException::INVALID_TRANSITION;
        $this->assertRefused($invalid, fn () => app(ResumeUserPersonLinkAction::class)->handle($this->admin, $a['link']));
        $this->assertRefused($invalid, fn () => app(SuspendUserPersonLinkAction::class)
            ->handle($this->admin, $a['link'], UserPersonLinkSuspensionReason::ADMINISTRATIVE));
        $this->assertRefused($invalid, fn () => app(EndUserPersonLinkAction::class)
            ->handle($this->admin, $a['link'], UserPersonLinkEndReason::ADMINISTRATIVE));

        $this->assertSame(UserPersonLinkStatus::ENDED, $a['link']->fresh()->status);
    }

    public function test_after_an_end_the_person_can_be_activated_again_as_a_new_link(): void
    {
        $a = $this->activatedHead('123456789');
        app(EndUserPersonLinkAction::class)->handle($this->admin, $a['link'], UserPersonLinkEndReason::IDENTITY_ERROR);

        // The slot and the login key are free: a new account takes them.
        $user = $this->familyUser();
        $link = $this->establish($user, $a['person']);

        $this->assertSame(2, UserPersonLink::where('person_id', $a['person']->id)->count());
        $this->assertFalse($link->is($a['link']));
        $this->assertTrue($this->resolver->familyContext($user)->hasFamilyContext());
        $this->assertTrue(app(FamilyAuthIdentities::class)->findByNationalIdInput('123456789')->user->is($user));
        // The old account stays as it was: active, with an ended link only.
        $this->assertSame(FamilyAccessDenial::NO_LINK, $this->resolver->identity($a['user']->fresh())->denial);
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $a = $this->activatedHead();
        $invalid = FamilyIdentityException::INVALID_TRANSITION;

        // Resuming a link that is not suspended.
        $this->assertRefused($invalid, fn () => app(ResumeUserPersonLinkAction::class)->handle($this->admin, $a['link']));
        // Suspending twice.
        app(SuspendUserPersonLinkAction::class)->handle($this->admin, $a['link'], UserPersonLinkSuspensionReason::ADMINISTRATIVE);
        $this->assertRefused($invalid, fn () => app(SuspendUserPersonLinkAction::class)
            ->handle($this->admin, $a['link'], UserPersonLinkSuspensionReason::ADMINISTRATIVE));

        $this->assertSame(409, (new FamilyIdentityException($invalid))->render(request())->status());
    }

    public function test_link_administration_needs_a_staff_side_actor_with_the_permission(): void
    {
        $a = $this->activatedHead();
        $actors = [];
        foreach (['DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $actors[$role] = tap(User::factory()->create(), fn (User $u) => $u->assignRole($role));
        }
        // A family-side account, even holding the permission directly.
        $actors['family'] = tap($this->familyUser(), fn (User $u) => $u->givePermissionTo('user-person-link.manage'));
        $actors['dual'] = tap($this->familyUser(['FAMILY_USER', 'COORDINATOR']), fn (User $u) => $u->givePermissionTo('user-person-link.manage'));
        // A mixed account is never a Staff-side actor.
        $actors['mixed'] = $this->familyUser(['ADMINISTRATOR', 'FAMILY_USER']);
        // An inactive administrator.
        $actors['inactive'] = tap(User::factory()->create(['is_active' => false]), fn (User $u) => $u->assignRole('ADMINISTRATOR'));

        foreach ($actors as $label => $actor) {
            foreach ([
                fn () => app(SuspendUserPersonLinkAction::class)->handle($actor, $a['link'], UserPersonLinkSuspensionReason::ADMINISTRATIVE),
                fn () => app(ResumeUserPersonLinkAction::class)->handle($actor, $a['link']),
                fn () => app(EndUserPersonLinkAction::class)->handle($actor, $a['link'], UserPersonLinkEndReason::ADMINISTRATIVE),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail("{$label} administered a User-Person Link.");
                } catch (AuthorizationException) {
                }
            }
        }
        $this->assertSame(UserPersonLinkStatus::ACTIVE, $a['link']->fresh()->status);
        $this->assertSame([], $this->events());

        // SUPER_ADMIN and ADMINISTRATOR may.
        $super = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        app(SuspendUserPersonLinkAction::class)->handle($super, $a['link'], UserPersonLinkSuspensionReason::ADMINISTRATIVE);
        $this->assertSame(UserPersonLinkStatus::SUSPENDED, $a['link']->fresh()->status);
    }

    public function test_a_system_end_needs_a_transaction_but_no_link_permission(): void
    {
        $a = $this->activatedHead();

        $link = DB::transaction(fn () => app(EndUserPersonLinkAction::class)
            ->bySystem($a['link'], UserPersonLinkEndReason::PERSON_DECEASED))->fresh();

        $this->assertSame(UserPersonLinkStatus::ENDED, $link->status);
        $this->assertSame('PERSON_DECEASED', $link->end_reason);
        $this->assertNull($link->ended_by);
    }

    public function test_a_failed_lifecycle_action_changes_nothing(): void
    {
        $a = $this->activatedHead();
        $this->sessionRowFor($a['user']);

        try {
            DB::transaction(function () use ($a) {
                app(EndUserPersonLinkAction::class)->bySystem($a['link'], UserPersonLinkEndReason::PERSON_DECEASED);
                throw new \RuntimeException('the surrounding action failed');
            });
        } catch (\RuntimeException) {
        }

        // Link, identity, sessions and audit are all exactly as before.
        $this->assertSame(UserPersonLinkStatus::ACTIVE, $a['link']->fresh()->status);
        $this->assertSame(AuthIdentityStatus::ACTIVE, $a['identity']->fresh()->status);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $a['user']->id)->count());
        $this->assertSame([], $this->events());
        $this->assertTrue($this->resolver->familyContext($a['user']->fresh())->hasFamilyContext());
    }

    public function test_lifecycle_events_never_carry_an_identifier(): void
    {
        $a = $this->activatedHead('123456789');
        app(SuspendUserPersonLinkAction::class)->handle($this->admin, $a['link'], UserPersonLinkSuspensionReason::ADMINISTRATIVE);
        app(ResumeUserPersonLinkAction::class)->handle($this->admin, $a['link']);
        app(EndUserPersonLinkAction::class)->handle($this->admin, $a['link'], UserPersonLinkEndReason::ADMINISTRATIVE);

        foreach (AuthSecurityEvent::all() as $event) {
            $this->assertStringNotContainsString('123456789', json_encode($event->getAttributes()));
            $this->assertNull($event->login_key);
        }
        $this->assertContains(AuthSecurityEventType::LINK_ENDED->value, $this->events());
    }
}
