<?php

namespace App\Support\Notifications;

use App\Enums\NotificationStatus;
use App\Models\NotificationRecipient;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ONE read model of a family's notifications (FB-NOTIFY-01B.2b.6b; ADR
 * FB-NOTIFY-01 ADR-03, ADR-07, D11). Every query starts from the family's
 * OWN recipient rows — the family id comes from the family.context boundary,
 * never from the client — so another family's notification simply does not
 * exist here.
 *
 * Only PUBLISHED and ARCHIVED messages are ever visible. Expiry is effective
 * at expires_at, before the sweep archives the message:
 *
 *   active   PUBLISHED and (no expires_at, or expires_at still ahead)
 *   archive  ARCHIVED, or PUBLISHED with expires_at reached
 *   unread   active and not viewed (archived / expired never count)
 *
 * Newest first (published_at, then id). Pure reads: listing or opening a
 * notification never records a view (ADR-05).
 */
final class FamilyNotificationInbox
{
    /** @return Builder<NotificationRecipient> */
    public function active(int $familyId): Builder
    {
        $now = now();

        return $this->visible($familyId)
            ->where('nm.status', NotificationStatus::PUBLISHED->value)
            ->where(fn (Builder $q) => $q->whereNull('nm.expires_at')->orWhere('nm.expires_at', '>', $now));
    }

    /** @return Builder<NotificationRecipient> */
    public function archive(int $familyId): Builder
    {
        $now = now();

        return $this->visible($familyId)
            ->where(fn (Builder $q) => $q
                ->where('nm.status', NotificationStatus::ARCHIVED->value)
                ->orWhere(fn (Builder $x) => $x->where('nm.status', NotificationStatus::PUBLISHED->value)
                    ->whereNotNull('nm.expires_at')
                    ->where('nm.expires_at', '<=', $now)));
    }

    public function unreadCount(int $familyId): int
    {
        return $this->active($familyId)->whereNull('notification_recipients.viewed_at')->count();
    }

    /** One visible notification of this family by its message uuid, or NULL. */
    public function find(int $familyId, string $messageUuid): ?NotificationRecipient
    {
        return $this->visible($familyId)->where('nm.uuid', $messageUuid)->first();
    }

    /**
     * The family's recipient rows of PUBLISHED or ARCHIVED messages, newest
     * first, with their message loaded.
     *
     * @return Builder<NotificationRecipient>
     */
    private function visible(int $familyId): Builder
    {
        return NotificationRecipient::query()
            ->select('notification_recipients.*')
            ->join('notification_messages as nm', 'nm.id', '=', 'notification_recipients.message_id')
            ->where('notification_recipients.family_id', $familyId)
            ->whereIn('nm.status', [NotificationStatus::PUBLISHED->value, NotificationStatus::ARCHIVED->value])
            ->with('message')
            ->orderByDesc('nm.published_at')
            ->orderByDesc('nm.id');
    }
}
