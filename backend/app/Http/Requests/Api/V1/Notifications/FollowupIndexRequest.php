<?php

namespace App\Http\Requests\Api\V1\Notifications;

use App\Enums\NotificationFollowupOutcome;
use App\Enums\NotificationFollowupStage;
use App\Enums\NotificationFollowupStatus;
use App\Enums\NotificationLevel;
use App\Support\Notifications\FollowupInbox;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Follow-up list filters, Staff side (FB-NOTIFY-01E.3-F3.2): allow-listed
 * keys only, applied in SQL by FollowupInbox. Status OPEN by default; a page
 * of at most 50.
 */
class FollowupIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function maxPerPage(): int
    {
        return FollowupInbox::STAFF_MAX_PER_PAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(NotificationFollowupStatus::class)],
            'stage' => ['sometimes', Rule::enum(NotificationFollowupStage::class)],
            'level' => ['sometimes', Rule::enum(NotificationLevel::class)],
            'outcome' => ['sometimes', Rule::enum(NotificationFollowupOutcome::class)],
            'family_code' => ['sometimes', 'string', 'max:50'],
            'due_before' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$this->maxPerPage()],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
