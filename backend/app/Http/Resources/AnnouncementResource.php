<?php

namespace App\Http\Resources;

use App\Enums\NotificationRetryDecision;
use App\Enums\NotificationStatus;
use App\Models\NotificationMessage;
use App\Support\Notifications\NotificationAudience;
use App\Support\Notifications\NotificationRetryPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the sender's OWN announcements (FB-NOTIFY-01B.2b.6c), for Staff and
 * coordinators alike. The summary is the publication state; the detail
 * (detail()) adds the body, the audience SHAPE (its type and how many items
 * each criterion selects — never the internal ids it names), the aggregate
 * statistics and the actions the state allows.
 *
 * NEVER: an internal id, the sender (it is the reader), the sender scope
 * snapshot, the fencing token, the cancelling user, the client reference,
 * or anything about a recipient family beyond aggregate counts.
 *
 * @property NotificationMessage $resource
 */
class AnnouncementResource extends JsonResource
{
    /** @var array<string, int|null>|null */
    private ?array $statistics = null;

    private ?string $statisticsBasis = null;

    private bool $detail = false;

    /**
     * @param  string|null  $basis  ALL_RECIPIENTS, CURRENT_COORDINATOR_SCOPE or NO_CURRENT_SCOPE; NULL before publication
     * @param  array<string, int|null>|null  $statistics  NULL before publication and without a current scope
     */
    public function detail(?string $basis, ?array $statistics): self
    {
        $this->detail = true;
        $this->statisticsBasis = $basis;
        $this->statistics = $statistics;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $message = $this->resource;
        $summary = [
            'id' => $message->uuid,
            'notice_code' => $message->notice_code,
            'level' => $message->level->value,
            'status' => $message->status->value,
            'title' => $message->title,
            'audience_type' => $message->audience['type'] ?? null,
            'expires_at' => $message->expires_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'queued_at' => $message->queued_at?->toIso8601String(),
            'published_at' => $message->published_at?->toIso8601String(),
            // The publication-time fact (D2): never recomputed, whatever the reader's scope now.
            'recipient_count_at_publication' => $message->recipient_count,
            'publish_attempts' => $message->publish_attempts,
            'publish_failure_code' => $message->publish_failure_code,
            'publish_failed_at' => $message->publish_failed_at?->toIso8601String(),
            'archived_at' => $message->archived_at?->toIso8601String(),
            'cancelled_at' => $message->cancelled_at?->toIso8601String(),
            'cancellation_reason_code' => $message->cancellation_reason_code,
        ];
        if (! $this->detail) {
            return $summary;
        }

        return [
            ...$summary,
            'body' => $message->body,
            'audience_selection' => array_map('count', array_intersect_key($message->audience ?? [], array_flip(NotificationAudience::SELECTION_KEYS))),
            // What the reader may count NOW, and on which basis.
            'statistics_basis' => $this->statisticsBasis,
            'statistics' => $this->statistics,
            'available_actions' => $this->actions($message),
        ];
    }

    /** @return list<string> what the message's STATE allows (permissions are still checked per request) */
    private function actions(NotificationMessage $message): array
    {
        $status = $message->status;

        return array_values(array_filter([
            $status === NotificationStatus::DRAFT ? 'PUBLISH' : null,
            in_array($status, [NotificationStatus::DRAFT, NotificationStatus::QUEUED, NotificationStatus::PUBLISH_FAILED], true) ? 'CANCEL' : null,
            $status === NotificationStatus::PUBLISH_FAILED && app(NotificationRetryPolicy::class)->manual($message) === NotificationRetryDecision::ALLOWED ? 'RETRY' : null,
        ]));
    }
}
