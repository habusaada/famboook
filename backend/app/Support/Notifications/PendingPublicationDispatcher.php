<?php

namespace App\Support\Notifications;

use App\Contracts\NotificationPublicationDispatcher;
use App\Models\NotificationMessage;
use Illuminate\Support\Facades\Log;

/**
 * TEMPORARY development binding of NotificationPublicationDispatcher
 * (FB-NOTIFY-01B.2b.4): the publication worker does not exist yet
 * (FB-NOTIFY-01B.2b.5), so nothing is handed off — no job, no queue write,
 * no synchronous publication, no recipient. The message simply stays QUEUED
 * (committed), exactly as after a lost hand-off.
 *
 * NOT an activation path. Before notifications are enabled anywhere (the
 * mode defaults to OFF), FB-NOTIFY-01B.2b.5 must replace this binding with
 * the queued publication job AND provide reconciliation for the QUEUED
 * messages this placeholder leaves behind.
 *
 * Logs the public message uuid only: never content, sender or audience.
 */
final class PendingPublicationDispatcher implements NotificationPublicationDispatcher
{
    public function dispatch(NotificationMessage $message): void
    {
        Log::info('Notification queued; the publication worker is not installed yet.', ['message' => $message->uuid]);
    }
}
