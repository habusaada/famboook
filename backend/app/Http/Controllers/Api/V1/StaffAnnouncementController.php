<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationSenderSide;

/**
 * The Staff announcement API (FB-NOTIFY-01B.2b.6c): /api/v1/notifications,
 * behind staff.side and the announcement.* permissions. A Staff sender's
 * statistics cover all the recipients of their own announcement.
 */
class StaffAnnouncementController extends AnnouncementController
{
    protected function side(): NotificationSenderSide
    {
        return NotificationSenderSide::STAFF;
    }
}
