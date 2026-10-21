<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A refused notification audience (FB-NOTIFY-01B.2b.3; ADR FB-NOTIFY-01
 * ADR-03 / ADR-04). Thrown by NotificationAudienceResolver at request
 * validation and when the audience is re-resolved at publication. The
 * message is fixed Arabic text: never a family id, code, name, a count of
 * the families refused or the coordinator's scope — an ineligible family and
 * a family outside the scope are the same refusal.
 */
class NotificationAudienceException extends RuntimeException
{
    // The audience is malformed, or does not fit the sender's side.
    public const INVALID = 'NOTIFICATION_AUDIENCE_INVALID';

    // The sender may not address this audience (side, scope or send-all).
    public const NOT_AUTHORIZED = 'NOTIFICATION_AUDIENCE_NOT_AUTHORIZED';

    // An explicitly selected family is not ACTIVE, deleted, unknown or out of scope.
    public const FAMILY_NOT_ELIGIBLE = 'NOTIFICATION_AUDIENCE_FAMILY_NOT_ELIGIBLE';

    // The audience resolves to no family.
    public const EMPTY = 'NOTIFICATION_AUDIENCE_EMPTY';

    // PILOT (pilot isolation, ADR §2h): only the pilot households, selected
    // one by one — never a whole scope, every family, a structure, or a
    // family outside NOTIFICATIONS_PILOT_FAMILY_IDS.
    public const NOT_IN_PILOT = 'NOTIFICATION_AUDIENCE_NOT_IN_PILOT';

    private const MESSAGES = [
        self::INVALID => 'تحديد المستلمين غير صالح.',
        self::NOT_AUTHORIZED => 'لا تملك صلاحية الإرسال إلى هذه الفئة من الأسر.',
        self::FAMILY_NOT_ELIGIBLE => 'إحدى الأسر المحددة غير متاحة للإرسال.',
        self::EMPTY => 'لا توجد أسر مستلمة ضمن هذا التحديد.',
        self::NOT_IN_PILOT => 'خلال التجربة المضبوطة يُرسل الإعلان إلى أسر التجربة المعتمدة فقط، بتحديدها أسرةً أسرة.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason]);
    }

    public function render(Request $request): JsonResponse
    {
        return response()
            ->json(['message' => $this->getMessage(), 'code' => $this->reason], $this->reason === self::NOT_AUTHORIZED ? 403 : 422)
            ->header('Cache-Control', 'no-store, private');
    }

    /** An expected business outcome, not an error to report. */
    public function report(): bool
    {
        return true;
    }
}
