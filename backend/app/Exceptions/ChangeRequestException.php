<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A refused Change Request operation (PWA-5b, docs/05 WF-ADR-049). Thrown by
 * the Change Request Domain Actions and type handlers. The message is fixed
 * Arabic text: never a payload value, National ID, mobile, internal id,
 * fingerprint, SQL or another exception's message.
 */
class ChangeRequestException extends RuntimeException
{
    public const INVALID_TRANSITION = 'CHANGE_REQUEST_INVALID_TRANSITION';

    public const TYPE_UNAVAILABLE = 'CHANGE_REQUEST_TYPE_UNAVAILABLE';

    public const NOT_FOUND = 'CHANGE_REQUEST_NOT_FOUND';

    public const BASE_CHANGED = 'CHANGE_REQUEST_BASE_CHANGED';

    public const PRECONDITION_FAILED = 'CHANGE_REQUEST_PRECONDITION_FAILED';

    public const ALREADY_OPEN = 'CHANGE_REQUEST_ALREADY_OPEN';

    public const NOT_APPLICABLE = 'CHANGE_REQUEST_NOT_APPLICABLE';

    public const APPLY_FAILED = 'CHANGE_REQUEST_APPLY_FAILED';

    // The same client_reference was reused for a materially different request.
    public const IDEMPOTENCY_CONFLICT = 'CHANGE_REQUEST_IDEMPOTENCY_CONFLICT';

    // The acting account is on the wrong side or lacks the transition's permission.
    public const ACTOR_NOT_ALLOWED = 'CHANGE_REQUEST_ACTOR_NOT_ALLOWED';

    private const MESSAGES = [
        self::INVALID_TRANSITION => 'لا يمكن تنفيذ هذا الإجراء على الطلب في حالته الحالية.',
        self::TYPE_UNAVAILABLE => 'هذا النوع من الطلبات غير متاح حاليًا.',
        self::NOT_FOUND => 'الطلب غير موجود.',
        self::BASE_CHANGED => 'تغيّرت البيانات المسجلة بعد تقديم الطلب.',
        self::PRECONDITION_FAILED => 'لم تعد شروط تنفيذ هذا الطلب متحققة.',
        self::ALREADY_OPEN => 'يوجد طلب مفتوح بنفس المضمون.',
        self::NOT_APPLICABLE => 'لم يعد الطلب قابلًا للتنفيذ.',
        self::APPLY_FAILED => 'تعذّر تطبيق الطلب على السجل حاليًا. يمكن إعادة المحاولة.',
        self::IDEMPOTENCY_CONFLICT => 'رقم مرجع الطلب مستخدم لطلب مختلف.',
        self::ACTOR_NOT_ALLOWED => 'لا تملك صلاحية تنفيذ هذا الإجراء على الطلب.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason]);
    }

    public function render(Request $request): JsonResponse
    {
        $status = match ($this->reason) {
            self::NOT_FOUND => 404,
            self::TYPE_UNAVAILABLE, self::PRECONDITION_FAILED, self::NOT_APPLICABLE => 422,
            self::ACTOR_NOT_ALLOWED => 403,
            self::APPLY_FAILED => 500,
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
