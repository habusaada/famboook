<?php

namespace Tests\Support\ChangeRequests;

use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\ChangeRequestType;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Shared setup for the PWA-5b engine tests: the seeded permission baseline,
 * the fake handler registered for OTHER (tests only), a REAL family.context
 * result from FamilyAccessResolver for an activated head, and Staff users.
 * Synthetic data only. Use with FamilyIdentityFixtures.
 */
trait ChangeRequestFixtures
{
    protected const FAKE_TYPE = ChangeRequestType::OTHER;

    protected function setUpChangeRequestEngine(): void
    {
        $this->useFamilyAuthKey();
        // The engine tests submit; the switch itself is tested in the Family API tests.
        config(['change_requests.family_submission_enabled' => true]);
        $this->seed(RolePermissionSeeder::class);
        FakeChangeRequestHandler::reset();
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::fake([
            self::FAKE_TYPE->value => new FakeChangeRequestHandler,
        ]));
    }

    /** The trusted Family context of a freshly activated household head. */
    protected function headContext(string $nationalId = '123456789', array $roles = ['FAMILY_USER']): FamilyAccessResult
    {
        $head = $this->activatedHead($nationalId, $roles);
        $context = app(FamilyAccessResolver::class)->familyContext($head['user']);
        $this->assertTrue($context->hasFamilyContext(), 'the fixture head must be eligible');

        return $context;
    }

    protected function staff(string $role = 'REVIEWER'): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'web'));

        return $user;
    }

    /** @param array<string, mixed> $input */
    protected function submit(FamilyAccessResult $context, array $input = ['paper_form_no' => 'PF-NEW-1'], ?string $reference = null, ?string $reason = null): ChangeRequest
    {
        return app(SubmitChangeRequestAction::class)
            ->handle($context, new ChangeRequestSubmission(self::FAKE_TYPE, $input, $reason, $reference ?? (string) Str::uuid()))
            ->request;
    }
}
