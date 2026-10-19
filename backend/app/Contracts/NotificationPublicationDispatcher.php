<?php

namespace App\Contracts;

use App\Models\NotificationMessage;

/**
 * The ONE boundary between the announcement lifecycle and the publication
 * worker (FB-NOTIFY-01B.2b.4; ADR FB-NOTIFY-01 ADR-08). The lifecycle
 * actions call it only from DB::afterCommit(), once the message is QUEUED
 * and committed — never inside the transaction and never for a rollback.
 *
 * An implementation must NEVER publish synchronously: it only hands the
 * message to the asynchronous worker. Losing a hand-off is safe — the
 * message stays QUEUED and reconciliation re-dispatches it (ADR-09) — and
 * the worker claims a message only while it is still QUEUED
 * (NotificationLifecycle::mayStartPublishing), so a cancelled message is
 * never published whatever was dispatched.
 *
 * Bound in AppServiceProvider to QueueNotificationPublicationDispatcher
 * (FB-NOTIFY-01B.2b.5c): PublishNotificationJob on the `notifications`
 * queue. The 2b.4 placeholder that handed nothing off is retired.
 */
interface NotificationPublicationDispatcher
{
    public function dispatch(NotificationMessage $message): void;
}
