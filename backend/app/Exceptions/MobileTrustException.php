<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A mobile trust operation was refused (docs/11 §30a). Thrown inside the
 * Domain Action's transaction, so nothing is left half-done. The reason is
 * an internal code; the rendered message is fixed text and never carries a
 * mobile number.
 */
class MobileTrustException extends RuntimeException
{
    public const PERSON_NOT_ELIGIBLE = 'PERSON_NOT_ELIGIBLE';

    public const NO_VALID_MOBILE = 'NO_VALID_MOBILE';

    public const ALREADY_TRUSTED = 'ALREADY_TRUSTED';

    public const NOT_TRUSTED = 'NOT_TRUSTED';

    private const MESSAGES = [
        self::PERSON_NOT_ELIGIBLE => 'لا يمكن توثيق جوال هذا الشخص: السجل محذوف أو غير نشط أو الشخص غير حي.',
        self::NO_VALID_MOBILE => 'لا يوجد رقم جوال صالح مسجّل لهذا الشخص. حدّث الرقم أولًا ثم وثّقه.',
        self::ALREADY_TRUSTED => 'رقم الجوال الحالي لهذا الشخص موثّق مسبقًا.',
        self::NOT_TRUSTED => 'لا يوجد توثيق جوال ساري لهذا الشخص.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason]);
    }

    public function render(Request $request): JsonResponse
    {
        $conflict = in_array($this->reason, [self::ALREADY_TRUSTED, self::NOT_TRUSTED], true);

        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason], $conflict ? 409 : 422);
    }

    /** Expected business outcome, not an error to report. */
    public function report(): bool
    {
        return true;
    }
}
