<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A refused follow-up management operation — recording a contact or closing
 * manually (FB-NOTIFY-01E.3-F3.1; ADR FB-NOTIFY-01 ADR-06, §2c). Fixed Arabic
 * text and a stable code; never a family, a note or an owner. Nothing is
 * written when it is thrown. (No route uses it yet: its HTTP rendering is
 * for the later API stage.)
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

    private const MESSAGES = [
        self::NOT_FOUND => 'المتابعة غير موجودة.',
        self::ACTOR_NOT_ALLOWED => 'لا تملك صلاحية إدارة هذه المتابعة.',
        self::NOT_OPEN => 'هذه المتابعة مغلقة.',
        self::EXPIRED => 'انتهت صلاحية الإشعار، وتُغلق المتابعة تلقائيًا.',
        self::REASON_INVALID => 'السبب المختار غير صالح.',
        self::NOTE_INVALID => 'الملاحظة الداخلية يجب ألا تكون فارغة وألا تتجاوز 1000 حرف.',
        self::OWNERSHIP_CHANGED => 'تغيّرت مسؤولية المتابعة أثناء التنفيذ. أعد المحاولة.',
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
            self::REASON_INVALID, self::NOTE_INVALID => 422,
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
