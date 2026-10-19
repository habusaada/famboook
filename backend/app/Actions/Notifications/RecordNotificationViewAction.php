<?php

namespace App\Actions\Notifications;

use App\Enums\NotificationEventType;
use App\Models\NotificationRecipient;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\Notifications\FamilyNotificationRecipient;
use App\Support\Notifications\NotificationEventRecorder;
use Illuminate\Support\Facades\DB;

/**
 * The household head opens a notification's details (FB-NOTIFY-01B.2b.6b;
 * ADR FB-NOTIFY-01 ADR-05): an explicit, non-cacheable operation — listing
 * or reading never records a view.
 *
 * Only the family's own household head in their CURRENT family context
 * (FamilyNotificationRecipient); never Staff, never a coordinator for another
 * family. Under the recipient row lock: the FIRST view wins — viewed_at and
 * viewed_by_user_id once, one VIEWED event in the same transaction; a repeat
 * is a replay that writes nothing. Works on an archived or expired
 * notification too (history stays viewable).
 */
class RecordNotificationViewAction
{
    public function __construct(private readonly NotificationEventRecorder $events) {}

    /** @return array{0: NotificationRecipient, 1: bool} the recipient and whether this call recorded the view */
    public function handle(FamilyAccessResult $context, string $messageUuid): array
    {
        $user = FamilyNotificationRecipient::actor($context);

        return DB::transaction(function () use ($context, $messageUuid, $user) {
            $recipient = FamilyNotificationRecipient::lock($context, $messageUuid);
            if ($recipient->viewed_at !== null) {
                return [$recipient, false];
            }

            $recipient->forceFill(['viewed_at' => now(), 'viewed_by_user_id' => $user->getKey()])->save();
            $this->events->recipient($recipient, NotificationEventType::VIEWED, $user);

            return [$recipient, true];
        });
    }
}
