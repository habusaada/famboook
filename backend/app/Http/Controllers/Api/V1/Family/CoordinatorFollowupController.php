<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Http\Controllers\Api\V1\FollowupController;
use App\Http\Requests\Api\V1\Notifications\CoordinatorFollowupIndexRequest;
use App\Http\Resources\FollowupEventResource;
use App\Models\Family;
use App\Models\NotificationFollowup;
use App\Models\NotificationRecipientEvent;
use App\Support\FamilyAuth\CoordinatorAccessResult;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\Notifications\FollowupInbox;
use App\Support\Notifications\FollowupOwnership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Coordinator follow-up API (FB-NOTIFY-01E.3-F3.2):
 * /api/v1/family/coordinator/followups behind family.side +
 * coordinator.space (the CURRENT scope);
 * coordinator-notification-followup.view for reads, .manage for contact and
 * closure — which only the family's CURRENT primary coordinator passes (the
 * Domain Actions decide under their locks; in scope but not primary, or the
 * own household: 403). Outside the current scope a follow-up does not exist
 * (404, the same as a nonexistent one). The history labels actors (SYSTEM /
 * STAFF / YOU / COORDINATOR) without naming another coordinator, and shows
 * notes only outside the reader's own household.
 */
class CoordinatorFollowupController extends FollowupController
{
    public function __construct(
        FollowupInbox $inbox,
        private readonly FollowupOwnership $ownership,
        private readonly FamilyAccessResolver $resolver,
    ) {
        parent::__construct($inbox);
    }

    public function index(CoordinatorFollowupIndexRequest $request): JsonResponse
    {
        return $this->list($request, $request->validated());
    }

    protected function visible(Request $request, string $uuid): ?NotificationFollowup
    {
        return $this->inbox->coordinatorFollowup($this->context($request), $uuid);
    }

    protected function listQuery(Request $request, array $filters): Builder
    {
        return $this->inbox->coordinator($this->context($request), $filters);
    }

    protected function summaryCounts(Request $request): array
    {
        return $this->inbox->coordinatorSummary($this->context($request));
    }

    protected function detailExtra(Request $request, NotificationFollowup $followup): array
    {
        $family = Family::query()->find($followup->getAttributes()['family_id']);

        return ['can_manage' => $family !== null && $this->ownership->coordinatorMayManage($request->user(), $family)];
    }

    protected function eventResource(Request $request, NotificationFollowup $followup, NotificationRecipientEvent $event): FollowupEventResource
    {
        $own = $this->resolver->familyContext($request->user());
        $ownHousehold = $own->hasFamilyContext() && (int) $own->family->getKey() === (int) $followup->getAttributes()['family_id'];

        return new FollowupEventResource($event, staffReader: false, readerId: (int) $request->user()->getKey(), withNotes: ! $ownHousehold);
    }

    private function context(Request $request): CoordinatorAccessResult
    {
        return $request->attributes->get(CoordinatorAccessResult::class);
    }
}
