<?php

namespace App\Support\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationLevel;
use App\Enums\NotificationSenderSide;
use App\Enums\NotificationStatus;
use App\Exceptions\NotificationPublicationException;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Support\FamilyAuth\CoordinatorAccessResult;
use App\Support\FamilyAuth\CoordinatorScopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The sender's own announcements (FB-NOTIFY-01B.2b.6c; ADR FB-NOTIFY-01
 * ADR-03, ADR-04, ADR-08 rule 5). Every query starts from the account's OWN
 * ANNOUNCEMENT rows on its side — never another sender's, never a SYSTEM
 * message, no administrator override — so an unowned uuid is NOT_FOUND,
 * exactly like a nonexistent one.
 *
 * A coordinator keeps their past messages as history after a scope change
 * (ADR-04 "past scope still visible"), but the recipient statistics count
 * only the families of their CURRENT scope (CoordinatorScopes::families()):
 * nothing about a family outside it is ever counted or shown.
 *
 * Statistics are one SQL aggregate over notification_recipients (ADR-03
 * reporting states) — counts only, never a family, person or contact value.
 */
final class SenderAnnouncements
{
    public function __construct(private readonly CoordinatorScopes $scopes) {}

    /** @return Builder<NotificationMessage> the account's own announcements, newest first */
    public function own(User $user, NotificationSenderSide $side): Builder
    {
        return NotificationMessage::query()
            ->where('kind', NotificationKind::ANNOUNCEMENT->value)
            ->where('sender_side', $side->value)
            ->where('sender_user_id', $user->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /** One of the account's own announcements by uuid; NOT_FOUND otherwise. */
    public function find(User $user, NotificationSenderSide $side, string $uuid): NotificationMessage
    {
        $found = Str::isUuid($uuid) ? $this->own($user, $side)->where('uuid', $uuid)->first() : null;

        return $found ?? throw new NotificationPublicationException(NotificationPublicationException::NOT_FOUND);
    }

    /**
     * The recipient aggregate of a published (or archived) message — for a
     * coordinator ($scope), only the recipients that are families of the
     * CURRENT scope. NULL before publication.
     *
     * The basis (all recipients, or the current coordinator scope) is the
     * caller's to state: AnnouncementResource::statistics_basis.
     *
     * @return array<string, int|null>|null
     */
    public function statistics(NotificationMessage $message, ?CoordinatorAccessResult $scope = null): ?array
    {
        if (! in_array($message->status, [NotificationStatus::PUBLISHED, NotificationStatus::ARCHIVED], true)) {
            return null;
        }

        $row = DB::table('notification_recipients')
            ->where('message_id', $message->getKey())
            ->when($scope !== null, fn ($q) => $q->whereIn('family_id', $this->scopes->families($scope)->select('families.id')->toBase()))
            ->selectRaw('COUNT(*) AS recipients')
            ->selectRaw('SUM(CASE WHEN had_family_account THEN 1 ELSE 0 END) AS with_account')
            ->selectRaw('SUM(CASE WHEN viewed_at IS NOT NULL THEN 1 ELSE 0 END) AS viewed')
            ->selectRaw('SUM(CASE WHEN acknowledged_at IS NOT NULL THEN 1 ELSE 0 END) AS acknowledged')
            ->selectRaw('SUM(CASE WHEN acknowledged_late THEN 1 ELSE 0 END) AS acknowledged_late')
            ->first();

        $recipients = (int) $row->recipients;
        $important = $message->level === NotificationLevel::IMPORTANT;
        $acknowledged = (int) $row->acknowledged;

        return [
            'recipients' => $recipients,
            'with_account' => (int) $row->with_account,
            'without_account' => $recipients - (int) $row->with_account,
            'viewed' => (int) $row->viewed,
            'not_viewed' => $recipients - (int) $row->viewed,
            'acknowledged' => $important ? $acknowledged : null,
            'acknowledged_late' => $important ? (int) $row->acknowledged_late : null,
            'pending_acknowledgement' => $important ? $recipients - $acknowledged : null,
        ];
    }
}
