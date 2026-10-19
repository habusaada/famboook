<?php

namespace App\Support\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationSenderSide;
use App\Exceptions\NotificationPublicationException;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Support\AccountSide;
use App\Support\FamilyAuth\CoordinatorScopes;

/**
 * Who may act on an announcement (FB-NOTIFY-01B.2b.4; ADR FB-NOTIFY-01
 * ADR-04, docs/06 AUTH-ADR-097) — the ChangeRequestActors convention:
 * defence in depth inside the Domain Actions, whatever the route adds.
 *
 * - Staff: an active Staff-side account holding announcement.create (draft,
 *   cancel a draft) or announcement.send (request, retry, cancel after
 *   queueing). announcement.send-all for every ACTIVE family is checked by
 *   NotificationAudienceResolver.
 * - Coordinator: an active family-side account holding
 *   coordinator-announcement.create / .send AND a granted Coordinator context
 *   right now (CoordinatorScopes::context: own eligible household, role,
 *   coordinator-space.access, an effective assignment). announcement.* never
 *   authorizes a coordinator, and coordinator-announcement.* never Staff.
 * - The side is the account's, never the client's. A family-side account
 *   without coordinator rights (a household head) never sends; Family
 *   Portal reads stay behind family.context.
 * - Only a message's own sender acts on it; anyone else's message is
 *   NOT_FOUND, exactly like a nonexistent one.
 *
 * Every actor refusal is NOTIFICATION_ACTOR_NOT_ALLOWED (no reason
 * disclosed). The permissions are PROPOSED and not seeded (AUTH-ADR-097):
 * until they are, nobody passes.
 */
final class NotificationActors
{
    public const STAFF_CREATE = 'announcement.create';

    public const STAFF_SEND = 'announcement.send';

    public const COORDINATOR_CREATE = 'coordinator-announcement.create';

    public const COORDINATOR_SEND = 'coordinator-announcement.send';

    /** The account acting now, with the create (or, $send, the send) permission of its side. */
    public static function sender(User $user, bool $send): NotificationSender
    {
        if ($user->is_active && AccountSide::isStaff($user) && $user->can($send ? self::STAFF_SEND : self::STAFF_CREATE)) {
            return new NotificationSender($user, NotificationSenderSide::STAFF);
        }
        if ($user->is_active && AccountSide::isFamily($user) && $user->can($send ? self::COORDINATOR_SEND : self::COORDINATOR_CREATE)) {
            $context = app(CoordinatorScopes::class)->context($user);
            if ($context->allowed()) {
                return new NotificationSender($user, NotificationSenderSide::COORDINATOR, $context);
            }
        }

        throw new NotificationPublicationException(NotificationPublicationException::ACTOR_NOT_ALLOWED);
    }

    /**
     * The account withdrawing ITS OWN announcement (CancelPublicationAction).
     * Ownership is checked FIRST against the stored sender — anyone else's
     * message is NOT_FOUND. Then an active account of the message's own side
     * (Staff-side for STAFF, family-side for COORDINATOR) holding that side's
     * create permission (the send permission once queued is checked by the
     * action).
     *
     * Deliberately NO Coordinator context: a coordinator whose assignments
     * were revoked can still withdraw what they sent, which only reduces
     * exposure. The result carries no scope and authorizes nothing else —
     * drafting, requesting and retrying keep going through sender(), which
     * requires the CURRENT context.
     */
    public static function canceller(User $user, NotificationMessage $message): NotificationSender
    {
        if ($message->kind !== NotificationKind::ANNOUNCEMENT || (int) $message->sender_user_id !== (int) $user->getKey()) {
            throw new NotificationPublicationException(NotificationPublicationException::NOT_FOUND);
        }
        [$sideAccount, $permission] = match ($message->sender_side) {
            NotificationSenderSide::STAFF => [AccountSide::isStaff($user), self::STAFF_CREATE],
            NotificationSenderSide::COORDINATOR => [AccountSide::isFamily($user), self::COORDINATOR_CREATE],
            default => [false, null],
        };
        if (! $user->is_active || ! $sideAccount || ! $user->can($permission)) {
            throw new NotificationPublicationException(NotificationPublicationException::ACTOR_NOT_ALLOWED);
        }

        return new NotificationSender($user, $message->sender_side);
    }

    /** May this (already resolved) sender also send — not only draft? */
    public static function maySend(NotificationSender $sender): bool
    {
        return $sender->user->can($sender->side === NotificationSenderSide::COORDINATOR ? self::COORDINATOR_SEND : self::STAFF_SEND);
    }

    /** The message is an announcement of THIS sender, on THIS side; otherwise it does not exist for them. */
    public static function ownMessage(NotificationMessage $message, NotificationSender $sender): void
    {
        if ($message->kind !== NotificationKind::ANNOUNCEMENT
            || (int) $message->sender_user_id !== (int) $sender->user->getKey()
            || $message->sender_side !== $sender->side) {
            throw new NotificationPublicationException(NotificationPublicationException::NOT_FOUND);
        }
    }

    /**
     * Infrastructure readiness for THIS sender (mode, PILOT allow-list,
     * asynchronous queue, fresh heartbeat, valid configuration). Readiness
     * grants nothing: it is checked after the actor, never instead of it.
     * The reasons stay server-side.
     */
    public static function assertReady(NotificationSender $sender): void
    {
        if (NotificationReadiness::forSender($sender->user) !== []) {
            throw new NotificationPublicationException(NotificationPublicationException::PUBLICATION_UNAVAILABLE);
        }
    }
}
