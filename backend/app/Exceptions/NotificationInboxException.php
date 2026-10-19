<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A refused family inbox operation (FB-NOTIFY-01B.2b.6b; ADR FB-NOTIFY-01
 * ADR-03, ADR-05). Fixed Arabic text and a stable code: never a title, a
 * body, another family or a sender. Another family's notification and a
 * nonexistent one are the same NOT_FOUND.
 */
class NotificationInboxException extends RuntimeException
{
    // Not a published notification of this family.
    public const NOT_FOUND = 'NOTIFICATION_NOT_FOUND';

    // Only the family's own household head, in their current family context.
    public const ACTOR_NOT_ALLOWED = 'NOTIFICATION_ACTOR_NOT_ALLOWED';

    // Only an IMPORTANT notification is acknowledged.
    public const NOT_ACKNOWLEDGEABLE = 'NOTIFICATION_NOT_ACKNOWLEDGEABLE';

    private const MESSAGES = [
        self::NOT_FOUND => 'الإشعار غير موجود.',
        self::ACTOR_NOT_ALLOWED => 'لا تملك صلاحية تنفيذ هذا الإجراء على الإشعار.',
        self::NOT_ACKNOWLEDGEABLE => 'هذا الإشعار لا يتطلب تأكيد الاطلاع.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason]);
    }

    public function render(Request $request): JsonResponse
    {
        $status = match ($this->reason) {
            self::NOT_FOUND => 404,
            self::ACTOR_NOT_ALLOWED => 403,
            default => 409,
        };

        return response()
            ->json(['message' => $this->getMessage(), 'code' => $this->reason], $status)
            ->header('Cache-Control', 'no-store, private');
    }

    /** An expected business outcome, not an error to report. */
    public function report(): bool
    {
        return true;
    }
}
