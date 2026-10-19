<?php

namespace App\Enums;

// What one run of the publication worker did (FB-NOTIFY-01B.2b.5c). Codes
// only: for logs and tests, never shown to families.
enum NotificationPublicationResult: string
{
    // T2 committed: recipients and PUBLISHED together.
    case PUBLISHED = 'PUBLISHED';

    // T3 committed: PUBLISH_FAILED with a NotificationFailureCode.
    case FAILED = 'FAILED';

    // Nothing to do: the message is missing or no longer QUEUED (cancelled,
    // already claimed, published) — a duplicate or stale delivery.
    case NOT_CLAIMED = 'NOT_CLAIMED';

    // Not claimed on purpose, no attempt consumed: the feature is OFF, the
    // sender is outside PILOT, the SYSTEM gate is closed, the configuration
    // is invalid or the claim limit is reached. The message stays QUEUED.
    case PAUSED = 'PAUSED';

    // The claim's fencing token is no longer current: another worker or a
    // recovery owns the message now. Nothing was written.
    case SUPERSEDED = 'SUPERSEDED';
}
