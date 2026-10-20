<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A refused follow-up management operation — recording a contact or closing
 * manually (FB-NOTIFY-01E.3-F3.1; ADR FB-NOTIFY-01 ADR-06, §2c). Fixed Arabic
 * text and a stable code; never a family, a note or an owner. Nothing is
 * written when it is thrown. Rendered by the F3.2 follow-up API (no-store;
 * BUSY adds Retry-After).
 */
class NotificationFollowupException extends RuntimeException
{
    // No such follow-up.
    public const NOT_FOUND = 'FOLLOWUP_NOT_FOUND';

    // Not central Staff with notification-followup.manage, nor the family's
    // CURRENT primary coordinator (never for their own household).
    public const ACTOR_NOT_ALLOWED = 'FOLLOWUP_ACTOR_NOT_ALLOWED';

    // Already CLOSED (whatever the outcome): never reopened or changed.
    public const NOT_OPEN = 'FOLLOWUP_NOT_OPEN';

    // The message reached expires_at: expiry wins (the scheduler closes it EXPIRED).
    public const EXPIRED = 'FOLLOWUP_EXPIRED';

    // Not a code of the contact-outcome / manual-closure catalogue.
    public const REASON_INVALID = 'FOLLOWUP_REASON_INVALID';

    // An internal note of 1..1000 characters, or none.
    public const NOTE_INVALID = 'FOLLOWUP_NOTE_INVALID';

    // The family's coordinator candidates changed while the authorization was
    // being locked: nothing was written; the caller may retry.
    public const OWNERSHIP_CHANGED = 'FOLLOWUP_OWNERSHIP_CHANGED';

    // A contact's client_reference (F3.2 idempotency) is not a uuid.
    public const CLIENT_REFERENCE_INVALID = 'FOLLOWUP_CLIENT_REFERENCE_INVALID';

    // The same client_reference was already used by this actor on this
    // follow-up for a DIFFERENT contact (outcome or note).
    public const CLIENT_REFERENCE_CONFLICT = 'FOLLOWUP_CLIENT_REFERENCE_CONFLICT';

    // A lock wait timed out or a deadlock was resolved (SQLSTATE 55P03 /
    // 40P01): the transaction rolled back, nothing was written — retry.
    public const BUSY = 'FOLLOWUP_BUSY';

    private const MESSAGES = [
        self::NOT_FOUND => 'المتابعة غير موجودة.',
        self::ACTOR_NOT_ALLOWED => 'لا تملك صلاحية إدارة هذه المتابعة.',
        self::NOT_OPEN => 'هذه المتابعة مغلقة.',
        self::EXPIRED => 'انتهت صلاحية الإشعار، وتُغلق المتابعة تلقائيًا.',
        self::REASON_INVALID => 'السبب المختار غير صالح.',
        self::NOTE_INVALID => 'الملاحظة الداخلية يجب ألا تكون فارغة وألا تتجاوز 1000 حرف.',
        self::OWNERSHIP_CHANGED => 'تغيّرت مسؤولية المتابعة أثناء التنفيذ. أعد المحاولة.',
        self::CLIENT_REFERENCE_INVALID => 'مرجع العملية غير صالح.',
        self::CLIENT_REFERENCE_CONFLICT => 'استُخدم مرجع العملية نفسه لتواصل مختلف.',
        self::BUSY => 'النظام مشغول حاليًا. أعد المحاولة بعد لحظات.',
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
            self::REASON_INVALID, self::NOTE_INVALID, self::CLIENT_REFERENCE_INVALID => 422,
            self::BUSY => 503,
            default => 409,
        };

        $response = response()
            ->json(['message' => $this->getMessage(), 'code' => $this->reason], $status)
            ->header('Cache-Control', 'no-store, private');

        return $this->reason === self::BUSY ? $response->header('Retry-After', '1') : $response;
    }

    /** An expected business outcome, not an error to report. */
    public function report(): bool
    {
        return true;
    }
}
