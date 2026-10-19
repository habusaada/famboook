<?php

namespace App\Support\Notifications;

use App\Exceptions\NotificationInboxException;
use App\Models\NotificationRecipient;
use App\Models\User;
use App\Support\AccountSide;
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Support\Str;

/**
 * Who may record a family's view or acknowledgement, and of which row
 * (FB-NOTIFY-01B.2b.6b; ADR FB-NOTIFY-01 ADR-05) — defence in depth inside
 * the Domain Actions, whatever the route adds (the ChangeRequestActors
 * convention):
 *
 * - the trusted family.context result with a resolved Family, on an active
 *   family-side account holding family-portal.access — Staff never, and a
 *   coordinator only as the head of their OWN household;
 * - the recipient row of THAT Family for the message uuid, locked FOR UPDATE,
 *   of a PUBLISHED or ARCHIVED message — anything else is NOT_FOUND.
 *
 * The event recorder then re-checks the actor's CURRENT family context
 * against the recipient's family before writing.
 */
final class FamilyNotificationRecipient
{
    public static function actor(FamilyAccessResult $context): User
    {
        $user = $context->user;
        if (! $context->hasFamilyContext() || $user === null || ! $user->is_active
            || ! AccountSide::isFamily($user) || ! $user->can('family-portal.access')) {
            throw new NotificationInboxException(NotificationInboxException::ACTOR_NOT_ALLOWED);
        }

        return $user;
    }

    /** The context Family's recipient row for this message, locked; NOT_FOUND otherwise. */
    public static function lock(FamilyAccessResult $context, string $messageUuid): NotificationRecipient
    {
        $visible = Str::isUuid($messageUuid)
            ? app(FamilyNotificationInbox::class)->find((int) $context->family->getKey(), $messageUuid)
            : null;
        if ($visible === null) {
            throw new NotificationInboxException(NotificationInboxException::NOT_FOUND);
        }

        $locked = NotificationRecipient::query()->whereKey($visible->getKey())->lockForUpdate()->first();
        if ($locked === null || (int) $locked->family_id !== (int) $context->family->getKey()) {
            throw new NotificationInboxException(NotificationInboxException::NOT_FOUND);
        }

        return $locked->setRelation('message', $visible->message);
    }
}
