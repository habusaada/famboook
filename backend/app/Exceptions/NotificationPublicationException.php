<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A refused notification lifecycle operation (FB-NOTIFY-01B.2b.4; ADR
 * FB-NOTIFY-01 ADR-04, ADR-08). Thrown by the announcement Domain Actions.
 * The message is fixed Arabic text: never a title, body, family, scope,
 * readiness reason or another exception's message. Audience refusals keep
 * their own NotificationAudienceException.
 */
class NotificationPublicationException extends RuntimeException
{
    // The account may not perform this operation (side, permission, scope).
    public const ACTOR_NOT_ALLOWED = 'NOTIFICATION_ACTOR_NOT_ALLOWED';

    // No such message for this sender — another sender's message alike.
    public const NOT_FOUND = 'NOTIFICATION_NOT_FOUND';

    // The message's current state does not allow this operation.
    public const INVALID_TRANSITION = 'NOTIFICATION_INVALID_TRANSITION';

    // The same client_reference was reused for a different announcement.
    public const IDEMPOTENCY_CONFLICT = 'NOTIFICATION_IDEMPOTENCY_CONFLICT';

    // Publication is not available now (mode, pilot, queue, worker, configuration).
    public const PUBLICATION_UNAVAILABLE = 'NOTIFICATION_PUBLICATION_UNAVAILABLE';

    // expires_at is less than the minimum lead ahead (R2).
    public const EXPIRY_TOO_SOON = 'NOTIFICATION_EXPIRY_TOO_SOON';

    // The last failure is permanent: the message can only be cancelled.
    public const RETRY_NOT_ALLOWED = 'NOTIFICATION_RETRY_NOT_ALLOWED';

    // The maximum number of publication attempts was reached (R4).
    public const RETRY_LIMIT_REACHED = 'NOTIFICATION_RETRY_LIMIT_REACHED';

    private const MESSAGES = [
        self::ACTOR_NOT_ALLOWED => 'لا تملك صلاحية تنفيذ هذا الإجراء على الإشعار.',
        self::NOT_FOUND => 'الإشعار غير موجود.',
        self::INVALID_TRANSITION => 'لا يمكن تنفيذ هذا الإجراء على الإشعار في حالته الحالية.',
        self::IDEMPOTENCY_CONFLICT => 'رقم مرجع الإشعار مستخدم لإشعار مختلف.',
        self::PUBLICATION_UNAVAILABLE => 'نشر الإشعارات غير متاح حاليًا.',
        self::EXPIRY_TOO_SOON => 'يجب أن يكون موعد انتهاء الإشعار بعد ساعة على الأقل.',
        self::RETRY_NOT_ALLOWED => 'لا يمكن إعادة محاولة نشر هذا الإشعار، ويمكن إلغاؤه.',
        self::RETRY_LIMIT_REACHED => 'تم بلوغ الحد الأقصى لمحاولات النشر، ويمكن إلغاء الإشعار.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason]);
    }

    public function render(Request $request): JsonResponse
    {
        $status = match ($this->reason) {
            self::ACTOR_NOT_ALLOWED => 403,
            self::NOT_FOUND => 404,
            self::EXPIRY_TOO_SOON => 422,
            self::PUBLICATION_UNAVAILABLE => 503,
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
