<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Notifications\NotificationReadiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The notification publication readiness gate (FB-NOTIFY-01B.2b, ADR
 * FB-NOTIFY-01 ADR-08). FAILS CLOSED with 503
 * NOTIFICATION_PUBLICATION_UNAVAILABLE when the mode is OFF, the signed-in
 * sender is not on the PILOT allow-list, the queue is not asynchronous (sync
 * is refused: there is no synchronous fallback), the worker heartbeat is
 * stale or absent, or the configuration is invalid. The reasons stay
 * server-side: the response carries one generic code.
 *
 * Runs after authentication (it needs the sender); with no user it refuses.
 * It is a precondition, NEVER authorization: passing it grants nothing, and
 * the route's permission and policy checks still decide who may publish.
 */
class EnsureNotificationPublicationReady
{
    public const CODE = 'NOTIFICATION_PUBLICATION_UNAVAILABLE';

    public function handle(Request $request, Closure $next): Response
    {
        $sender = $request->user();
        if (! $sender instanceof User || NotificationReadiness::forSender($sender) !== []) {
            return response()
                ->json(['message' => 'نشر الإشعارات غير متاح حاليًا.', 'code' => self::CODE], 503)
                ->header('Cache-Control', 'no-store, private');
        }

        return $next($request);
    }
}
