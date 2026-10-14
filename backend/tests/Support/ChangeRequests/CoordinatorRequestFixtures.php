<?php

namespace Tests\Support\ChangeRequests;

use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\SubmissionChannel;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use App\Models\Branch;
use App\Models\ChangeRequest;
use App\Models\Clan;
use App\Models\Family;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestBase;
use App\Support\ChangeRequests\ChangeRequestOutcome;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\Handlers\AddFamilyMemberHandler;
use App\Support\ChangeRequests\WorkflowEventRecorder;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Coordinator-submitted Change Requests (CO-02.3b): a Coordinator with a
 * CLAN scope, a household in that Clan with its own activated head (the
 * target of PERSON_ADD), the real ADD_FAMILY_MEMBER handler, the test-only
 * OTHER and REGISTER_FAMILY handlers, and both submission switches open.
 * Synthetic data and National IDs only. Requires CoordinatorFixtures and
 * FamilyIdentityFixtures.
 */
trait CoordinatorRequestFixtures
{
    /** A synthetic National ID for the proposed member. */
    protected const MEMBER_NID = '401234567';

    protected Clan $scopeClan;

    protected User $coordinatorUser;

    /** The household in scope and its own head (the Family Portal side). */
    protected FamilyAccessResult $household;

    protected function setUpCoordinatorRequests(): void
    {
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        config([
            'change_requests.family_submission_mode' => 'GENERAL',
            'change_requests.coordinator_submission_mode' => 'GENERAL',
        ]);
        FakeChangeRequestHandler::reset();
        FakeProposedFamilyHandler::reset();
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::fake([
            ChangeRequestType::ADD_FAMILY_MEMBER->value => new AddFamilyMemberHandler,
            ChangeRequestType::OTHER->value => new FakeChangeRequestHandler,
            ChangeRequestType::REGISTER_FAMILY->value => new FakeProposedFamilyHandler,
        ]));

        $this->scopeClan = $this->clan('SCOPE_CLAN');
        $this->coordinatorUser = $this->coordinator('111111110');
        $this->assign($this->coordinatorUser, $this->scopeClan);
        $this->household = $this->householdIn($this->scopeClan, '223456789');
    }

    /** An activated household head whose Family is placed in the given Clan / Branch. */
    protected function householdIn(Clan $clan, string $nationalId, ?Branch $branch = null): FamilyAccessResult
    {
        $head = $this->activatedHead($nationalId);
        $head['family']->forceFill(['clan_id' => $clan->id, 'branch_id' => $branch?->id])->save();

        return app(FamilyAccessResolver::class)->familyContext($head['user']->fresh());
    }

    /** @return array<string, mixed> a valid ADD_FAMILY_MEMBER proposal */
    protected function member(array $overrides = []): array
    {
        return [
            'full_name' => 'فرد تجريبي جديد', 'national_id' => self::MEMBER_NID, 'gender' => 'FEMALE', 'relationship' => 'DAUGHTER',
            'birth_date' => '2010-05-01', 'marital_status' => 'SINGLE', ...$overrides,
        ];
    }

    protected function coordinatorSubmits(?Family $family = null, array $data = [], ?string $reference = null, ?User $as = null, ChangeRequestType $type = ChangeRequestType::ADD_FAMILY_MEMBER): ChangeRequestOutcome
    {
        return app(SubmitChangeRequestAction::class)->handleCoordinator(
            ($as ?? $this->coordinatorUser)->fresh(),
            $family ?? $this->household->family,
            new ChangeRequestSubmission($type, $this->member($data), null, $reference ?? (string) Str::uuid()),
        );
    }

    protected function coordinatorRequest(array $data = []): ChangeRequest
    {
        return $this->coordinatorSubmits(data: $data)->request;
    }

    /**
     * A SUBMITTED REGISTER_FAMILY proposal, written row by row (its
     * submission is a later phase): no Family, a placement, the
     * Coordinator's SUBMITTED event.
     */
    protected function proposedFamily(?Clan $clan = null, ?Branch $branch = null, ?User $by = null): ChangeRequest
    {
        $by ??= $this->coordinatorUser;
        $person = app(FamilyAccessResolver::class)->familyContext($by->fresh())->person;

        return DB::transaction(function () use ($clan, $branch, $by, $person) {
            $request = new ChangeRequest([
                'family_id' => null,
                'submission_channel' => SubmissionChannel::COORDINATOR,
                'type' => ChangeRequestType::REGISTER_FAMILY,
                'payload_version' => 1,
                'submitted_data' => ['head' => ['full_name' => 'رب أسرة مقترح']],
                'proposed_clan_id' => ($clan ?? $this->scopeClan)->id,
                'proposed_branch_id' => $branch?->id,
                'submitted_by' => $by->id,
                'submitted_by_person_id' => $person->id,
                'client_reference' => (string) Str::uuid(),
            ]);
            $base = ChangeRequestBase::of((new FakeProposedFamilyHandler)->proposalBaseValues($request));
            $request->forceFill([
                'base_fingerprint' => $base['fingerprint'], 'base_key_version' => $base['key_version'],
                'status' => ChangeRequestStatus::SUBMITTED, 'submitted_at' => now(),
            ])->save();
            WorkflowEventRecorder::record($request, null, WorkflowEventType::SUBMITTED, WorkflowActorSide::COORDINATOR, $by->id);

            return $request;
        });
    }
}
