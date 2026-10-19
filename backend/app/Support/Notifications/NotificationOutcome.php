<?php

namespace App\Support\Notifications;

use App\Models\NotificationMessage;

/**
 * The result of an announcement lifecycle action (FB-NOTIFY-01B.2b.4):
 * the message as stored, and whether the call was a replay that changed
 * nothing (no write, no event, no dispatch).
 */
final readonly class NotificationOutcome
{
    public function __construct(
        public NotificationMessage $message,
        public bool $replayed = false,
    ) {}
}
