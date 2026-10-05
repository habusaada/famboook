<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\EndFamilyMembershipAction;
use App\Actions\EndUserPersonLinkAction;
use App\Actions\RecordPersonDeathAction;
use App\Actions\SuspendUserPersonLinkAction;
use App\Enums\FamilyStatus;
use App\Enums\LifeStatusVerificationMethod;
use App\Enums\UserPersonLinkEndReason;
use App\Enums\UserPersonLinkSuspensionReason;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1I: eligibility changes while a Family session (of a household head who
 * is also a Coordinator) is alive. On the very next request the Family
 * context — and with it Coordinator Space — is gone. Death and the terminal
 * or suspending link transitions also revoke the sessions; head, membership
 * and Family changes leave the session row but take the context away
 * immediately (FU-01 Head Succession is not implemented: headship is changed
 * row by row here). Synthetic data only.
 */
class EligibilityDuringSessionTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    /** @var array<string, mixed> */
    private array $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        config(['session.driver' => 'database']);
        $this->head = $this->activatedHead('123456789', ['FAMILY_USER', 'COORDINATOR']);
        $this->assign($this->head['user'], $this->clan());
    }

    private function as(string $uri): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->head['user']->fresh())->getJson($uri);
    }

    private function staff(): User
    {
        return User::factory()->create()->assignRole('SUPER_ADMIN');
    }

    /** @return array<string, array{0: string, 1: bool, 2: int}> transition, sessions revoked, /me status */
    public static function transitions(): array
    {
        return [
            'person died' => ['death', true, 200],
            'headship lost' => ['headship', false, 200],
            'membership ended' => ['membership', false, 200],
            'family deactivated' => ['family', false, 200],
            'user deactivated' => ['user', false, 401],
            'link suspended' => ['suspend', true, 200],
            'link ended' => ['end', true, 200],
        ];
    }

    private function apply(string $transition): void
    {
        $person = $this->head['person'];
        match ($transition) {
            'death' => app(RecordPersonDeathAction::class)->handle($person, '2026-09-01', LifeStatusVerificationMethod::IN_PERSON, null),
            'headship' => $this->head['membership']->forceFill(['is_household_head' => false])->save(),
            'membership' => $this->head['membership']->forceFill(['is_active' => false, 'is_household_head' => false])->save(),
            'family' => $this->head['family']->forceFill(['status' => FamilyStatus::INACTIVE])->save(),
            'user' => $this->head['user']->forceFill(['is_active' => false])->save(),
            'suspend' => app(SuspendUserPersonLinkAction::class)->handle($this->staff(), $this->head['link'], UserPersonLinkSuspensionReason::ADMINISTRATIVE),
            'end' => app(EndUserPersonLinkAction::class)->handle($this->staff(), $this->head['link'], UserPersonLinkEndReason::ADMINISTRATIVE),
        };
    }

    public function test_before_any_change_the_head_has_a_context_and_coordinator_space(): void
    {
        $this->as('/api/v1/family/me')->assertOk()
            ->assertJsonPath('user.context.available', true)
            ->assertJsonPath('user.coordinator_space', true);
        $this->as('/api/v1/family/coordinator/context')->assertOk();
    }

    #[DataProvider('transitions')]
    public function test_the_context_is_gone_on_the_next_request(string $transition, bool $revoked, int $meStatus): void
    {
        $this->sessionRowFor($this->head['user']);

        $this->apply($transition);

        $this->assertSame($revoked ? 0 : 1, DB::table('sessions')->where('user_id', $this->head['user']->id)->count(), 'session rows');
        $me = $this->as('/api/v1/family/me')->assertStatus($meStatus);
        if ($meStatus === 200) {
            $me->assertJsonPath('user.context.available', false)
                ->assertJsonPath('user.context.family', null)
                ->assertJsonPath('user.coordinator_space', false);
        }
        $coordinator = $this->as('/api/v1/family/coordinator/context');
        $this->assertContains($coordinator->status(), [401, 403]);
        $this->as('/api/v1/family/coordinator/families')->assertStatus($coordinator->status());
    }

    public function test_the_current_head_cannot_simply_be_ended_out_of_the_family(): void
    {
        // Head Succession (FU-01) is not implemented: the membership action
        // refuses the head instead of leaving a family without one.
        $this->expectException(HttpException::class);

        app(EndFamilyMembershipAction::class)->handle($this->head['family'], $this->head['person'], 'synthetic', null);
    }
}
