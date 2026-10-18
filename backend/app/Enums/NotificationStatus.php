<?php

namespace App\Enums;

// The publication lifecycle of a notification message (ADR FB-NOTIFY-01
// ADR-08). Families see only PUBLISHED and ARCHIVED messages. ARCHIVED is
// reached only at expiry; CANCELLED only before publication.
enum NotificationStatus: string
{
    case DRAFT = 'DRAFT';
    case QUEUED = 'QUEUED';
    case PUBLISHING = 'PUBLISHING';
    case PUBLISHED = 'PUBLISHED';
    case PUBLISH_FAILED = 'PUBLISH_FAILED';
    case ARCHIVED = 'ARCHIVED';
    case CANCELLED = 'CANCELLED';
}
