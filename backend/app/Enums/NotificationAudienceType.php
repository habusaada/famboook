<?php

namespace App\Enums;

// The requested audience of a notification message (FB-NOTIFY-01B.2b.3; ADR
// FB-NOTIFY-01 ADR-03 / ADR-04), stored in notification_messages.audience
// and resolved only on the server by NotificationAudienceResolver.
enum NotificationAudienceType: string
{
    // Staff: one or more explicitly selected ACTIVE families.
    case STAFF_FAMILIES = 'STAFF_FAMILIES';

    // Staff: every ACTIVE family (announcement.send-all).
    case STAFF_ALL_ACTIVE = 'STAFF_ALL_ACTIVE';

    // Coordinator: every ACTIVE family of the CURRENT effective scope.
    case COORDINATOR_SCOPE = 'COORDINATOR_SCOPE';

    // Coordinator: selected families / branches / branch groups / clans,
    // always intersected with the CURRENT effective scope.
    case COORDINATOR_SELECTION = 'COORDINATOR_SELECTION';

    // SYSTEM: the family of the originating change request (ADR-09).
    case SYSTEM_ORIGIN = 'SYSTEM_ORIGIN';

    public function senderSide(): NotificationSenderSide
    {
        return match ($this) {
            self::STAFF_FAMILIES, self::STAFF_ALL_ACTIVE => NotificationSenderSide::STAFF,
            self::COORDINATOR_SCOPE, self::COORDINATOR_SELECTION => NotificationSenderSide::COORDINATOR,
            self::SYSTEM_ORIGIN => NotificationSenderSide::SYSTEM,
        };
    }
}
