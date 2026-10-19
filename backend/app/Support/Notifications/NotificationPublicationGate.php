<?php

namespace App\Support\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationMode;
use App\Models\NotificationMessage;
use Illuminate\Database\Eloquent\Builder;

/**
 * The feature gates for publishing ONE message (FB-NOTIFY-01B.2b.5c / 5d),
 * shared by the worker (NotificationPublisher T1 / T2) and the reconciler.
 * Fail closed: invalid configuration, mode OFF, a sender outside the PILOT
 * allow-list, or — for SYSTEM — anything but ON with its own gate and a
 * valid activation cutoff. Authorization (permission, scope) is NOT a gate:
 * the worker re-checks it in T2.
 */
final class NotificationPublicationGate
{
    /** Why this message may not be published now (a cause CODE), or NULL. */
    public static function blocks(NotificationMessage $message): ?string
    {
        if (NotificationConfig::invalidKeys() !== []) {
            return 'CONFIG_INVALID';
        }
        $mode = NotificationConfig::mode();
        if ($mode === NotificationMode::OFF) {
            return 'FEATURE_OFF';
        }
        if ($message->kind === NotificationKind::SYSTEM) {
            return match (true) {
                $mode !== NotificationMode::ON => 'SYSTEM_REQUIRES_ON',
                ! NotificationConfig::systemDiscoveryEnabled() => 'SYSTEM_GATE_CLOSED',
                NotificationConfig::systemCutoff() === null => 'SYSTEM_CUTOFF_INVALID',
                default => null,
            };
        }
        if ($mode === NotificationMode::PILOT
            && ! in_array((int) $message->sender_user_id, NotificationConfig::pilotSenderUserIds() ?? [], true)) {
            return 'PILOT_NOT_ELIGIBLE';
        }

        return null;
    }

    /**
     * Narrows a notification_messages query to the messages the gates could
     * let through (the same rules in SQL), so blocked messages never take a
     * reconciliation batch slot. blocks() stays authoritative under the lock.
     *
     * @param  Builder<NotificationMessage>  $query
     * @return Builder<NotificationMessage>
     */
    public static function narrow(Builder $query): Builder
    {
        $mode = NotificationConfig::mode();
        if (NotificationConfig::invalidKeys() !== [] || $mode === NotificationMode::OFF) {
            return $query->whereRaw('1 = 0');
        }
        $systemOpen = $mode === NotificationMode::ON
            && NotificationConfig::systemDiscoveryEnabled()
            && NotificationConfig::systemCutoff() !== null;
        if ($mode === NotificationMode::PILOT) {
            $query->where('notification_messages.kind', NotificationKind::ANNOUNCEMENT->value)
                ->whereIn('notification_messages.sender_user_id', NotificationConfig::pilotSenderUserIds() ?? []);
        } elseif (! $systemOpen) {
            $query->where('notification_messages.kind', NotificationKind::ANNOUNCEMENT->value);
        }

        return $query;
    }
}
