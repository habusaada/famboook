<?php

namespace App\Support\Notifications;

use App\Enums\NotificationMode;
use App\Models\Family;
use App\Models\NotificationRecipient;
use App\Models\User;

/**
 * Who SEES the notification UI (FB-NOTIFY-01 pilot isolation, ADR §2h;
 * reported by GET /api/v1/family/me). VISIBILITY ONLY: it authorizes
 * nothing — every notification endpoint keeps its own permission, policy,
 * scope and readiness checks, and publication is narrowed by
 * NotificationAudienceResolver::assertPilotAudience(). Fails closed: an
 * invalid notification configuration shows nothing.
 *
 * Family (C1):
 *   OFF    nothing
 *   PILOT  a pilot household (NOTIFICATIONS_PILOT_FAMILY_IDS), or a household
 *          that already holds notifications (a recipient row: its own,
 *          legitimately published history)
 *   ON     every household with a Family context, even with an empty inbox
 *
 * Coordinator (C3):
 *   OFF    nothing
 *   PILOT  an allow-listed sender (NOTIFICATIONS_PILOT_SENDER_USER_IDS)
 *   ON     every coordinator
 */
final class NotificationVisibility
{
    public static function forFamily(?Family $family): bool
    {
        if ($family === null || NotificationConfig::invalidKeys() !== []) {
            return false;
        }

        return match (NotificationConfig::mode()) {
            NotificationMode::OFF => false,
            NotificationMode::ON => true,
            NotificationMode::PILOT => in_array((int) $family->getKey(), NotificationConfig::pilotFamilyIds() ?? [], true)
                || NotificationRecipient::query()->where('family_id', $family->getKey())->exists(),
        };
    }

    public static function forCoordinator(User $user, bool $coordinator): bool
    {
        if (! $coordinator || NotificationConfig::invalidKeys() !== []) {
            return false;
        }

        return match (NotificationConfig::mode()) {
            NotificationMode::OFF => false,
            NotificationMode::ON => true,
            NotificationMode::PILOT => in_array((int) $user->getKey(), NotificationConfig::pilotSenderUserIds() ?? [], true),
        };
    }
}
