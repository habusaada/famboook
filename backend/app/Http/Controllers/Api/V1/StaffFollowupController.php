<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Notifications\FollowupIndexRequest;
use App\Http\Resources\FollowupEventResource;
use App\Models\Family;
use App\Models\NotificationFollowup;
use App\Models\NotificationRecipientEvent;
use App\Support\Notifications\FollowupInbox;
use App\Support\Notifications\FollowupOwnership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff follow-up API (FB-NOTIFY-01E.3-F3.2): /api/v1/notifications/followups
 * behind staff.side; notification-followup.view for reads,
 * notification-followup.manage for contact and closure (re-checked by the
 * Domain Actions: active Staff SUPER_ADMIN / ADMINISTRATOR). Every follow-up,
 * CENTRAL ones included. The detail shows the ownership KIND only; the
 * history the actors' names and the notes.
 */
class StaffFollowupController extends FollowupController
{
    public function __construct(FollowupInbox $inbox, private readonly FollowupOwnership $ownership)
    {
        parent::__construct($inbox);
    }

    public function index(FollowupIndexRequest $request): JsonResponse
    {
        return $this->list($request, $request->validated());
    }

    protected function visible(Request $request, string $uuid): ?NotificationFollowup
    {
        return $this->inbox->staffFollowup($uuid);
    }

    protected function listQuery(Request $request, array $filters): Builder
    {
        return $this->inbox->staff($filters);
    }

    protected function summaryCounts(Request $request): array
    {
        return $this->inbox->staffSummary();
    }

    protected function detailExtra(Request $request, NotificationFollowup $followup): array
    {
        $family = Family::withTrashed()->find($followup->getAttributes()['family_id']);
        $central = $family === null || $this->ownership->resolve($family)->isCentral();

        return ['ownership' => ['kind' => $central ? 'CENTRAL' : 'PRIMARY_COORDINATOR']];
    }

    protected function eventResource(Request $request, NotificationFollowup $followup, NotificationRecipientEvent $event): FollowupEventResource
    {
        return new FollowupEventResource($event, staffReader: true, readerId: (int) $request->user()->getKey(), withNotes: true);
    }
}
