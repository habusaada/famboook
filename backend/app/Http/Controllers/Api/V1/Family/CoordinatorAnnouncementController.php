<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Enums\NotificationSenderSide;
use App\Enums\NotificationStatus;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Models\NotificationMessage;
use App\Support\FamilyAuth\CoordinatorAccessResult;
use App\Support\FamilyAuth\CoordinatorScopes;
use Illuminate\Http\Request;

/**
 * The Coordinator announcement API (FB-NOTIFY-01B.2b.6c):
 * /api/v1/family/coordinator/notifications, coordinator-announcement.* only —
 * never announcement.*.
 *
 * Operational routes (audience preview, draft, publish, retry) sit behind
 * coordinator.space: the CURRENT scope decides, and a revoked coordinator
 * can do none of them. Three routes sit outside it, for the original sender
 * only (ownership is checked on every lookup):
 * - the list and the detail — READ-ONLY history (ADR-04 "past scope still
 *   visible"); they grant no operation;
 * - /cancel — withdrawal of an own unpublished message (approved in 2b.4).
 *
 * Recipient statistics are counted only over the families of the CURRENT
 * scope, re-resolved on every request. Without a current scope there are none
 * (NO_CURRENT_SCOPE) — never the all-recipients aggregate; the publication-
 * time recipient count stays as the historical fact.
 */
class CoordinatorAnnouncementController extends AnnouncementController
{
    protected function side(): NotificationSenderSide
    {
        return NotificationSenderSide::COORDINATOR;
    }

    protected function statistics(Request $request, NotificationMessage $message): array
    {
        if (! in_array($message->status, [NotificationStatus::PUBLISHED, NotificationStatus::ARCHIVED], true)) {
            return [null, null];
        }
        // Inside coordinator.space the context is on the request; on the
        // history routes it is resolved now — the CURRENT scope either way.
        $scope = $request->attributes->get(CoordinatorAccessResult::class) ?? app(CoordinatorScopes::class)->context($request->user());
        if (! $scope instanceof CoordinatorAccessResult || ! $scope->allowed()) {
            return ['NO_CURRENT_SCOPE', null];
        }

        return ['CURRENT_COORDINATOR_SCOPE', $this->announcements->statistics($message, $scope)];
    }
}
