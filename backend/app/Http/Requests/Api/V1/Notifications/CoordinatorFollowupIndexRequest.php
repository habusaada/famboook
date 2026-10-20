<?php

namespace App\Http\Requests\Api\V1\Notifications;

use App\Support\Notifications\FollowupInbox;

/**
 * Follow-up list filters, coordinator side (FB-NOTIFY-01E.3-F3.2): the Staff
 * filters plus `ownership` (all | mine — the families whose CURRENT primary
 * coordinator the reader is, set-based in SQL), a page of at most 25.
 */
class CoordinatorFollowupIndexRequest extends FollowupIndexRequest
{
    protected function maxPerPage(): int
    {
        return FollowupInbox::COORDINATOR_MAX_PER_PAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'ownership' => ['sometimes', 'in:all,mine']];
    }
}
