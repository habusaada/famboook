<?php

namespace App\Support\Notifications;

use App\Enums\NotificationEventType;
use App\Enums\NotificationStatus;
use App\Enums\WorkflowActorSide;
use App\Models\NotificationMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The expiry archival sweep (FB-NOTIFY-01B.2b.6b; ADR FB-NOTIFY-01 ADR-07,
 * D4): a PUBLISHED message whose expires_at is reached becomes ARCHIVED, with
 * archived_at and a SYSTEM EXPIRED_ARCHIVED event, in one transaction. The
 * only way a message is ever archived (no manual archive).
 *
 * Read models already treat it as expired from expires_at on
 * (FamilyNotificationInbox); this makes it persistent. Recipients, views and
 * acknowledgements are untouched — a late acknowledgement stays possible.
 * Not a publication: it runs whatever the feature mode (OFF included).
 *
 * At most $limit messages per run, each in its own short transaction on its
 * row locked FOR UPDATE SKIP LOCKED and re-checked; idempotent (an archived
 * message is no longer a candidate). archived_at = now() >= expires_at
 * (chk_nm_archive_on_expiry). Counters only in logs.
 */
final class NotificationExpirySweeper
{
    public const DEFAULT_LIMIT = 100;

    public function __construct(private readonly NotificationEventRecorder $events) {}

    /** @return array{scanned: int, archived: int, skipped: int, errors: int} */
    public function run(int $limit = self::DEFAULT_LIMIT): array
    {
        $counts = ['scanned' => 0, 'archived' => 0, 'skipped' => 0, 'errors' => 0];
        $ids = NotificationMessage::query()
            ->where('status', NotificationStatus::PUBLISHED->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')->orderBy('id')
            ->limit(max(0, $limit))
            ->pluck('id');

        foreach ($ids as $id) {
            $counts['scanned']++;
            try {
                $outcome = DB::transaction(function () use ($id) {
                    NotificationLifecycle::limitTransaction();
                    $message = NotificationLifecycle::lockOrSkip((int) $id);
                    if ($message === null || $message->status !== NotificationStatus::PUBLISHED
                        || $message->expires_at === null || $message->expires_at->getTimestamp() > now()->getTimestamp()) {
                        return 'skipped';
                    }
                    NotificationLifecycle::assertAllowed($message->status, NotificationStatus::ARCHIVED);
                    $message->forceFill(['status' => NotificationStatus::ARCHIVED, 'archived_at' => now()])->save();
                    $this->events->message($message, NotificationEventType::EXPIRED_ARCHIVED, WorkflowActorSide::SYSTEM);

                    return 'archived';
                });
                $counts[$outcome]++;
            } catch (Throwable $e) {
                $counts['errors']++;
                Log::warning('Notification expiry sweep step failed.', ['message_id' => (int) $id, 'code' => 'EXPIRY_SWEEP_ERROR', 'exception' => $e::class]);
            }
        }

        if ($counts['scanned'] > 0) {
            Log::info('Notification expiry sweep.', $counts);
        }

        return $counts;
    }
}
