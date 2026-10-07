<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A refused Digital Family Card operation (docs/11 FP-ADR-070). Thrown inside
 * the Domain Action's transaction. The message is fixed text; it never
 * carries a token, a card number or a National ID.
 */
class CredentialException extends RuntimeException
{
    public const FAMILY_NOT_ACTIVE = 'FAMILY_NOT_ACTIVE';

    public const CARD_ALREADY_ACTIVE = 'CARD_ALREADY_ACTIVE';

    public const NO_ACTIVE_CARD = 'NO_ACTIVE_CARD';

    public const ISSUANCE_DISABLED = 'ISSUANCE_DISABLED';

    private const MESSAGES = [
        self::FAMILY_NOT_ACTIVE => 'لا يمكن إصدار بطاقة لأسرة غير نشطة.',
        self::CARD_ALREADY_ACTIVE => 'لهذه الأسرة بطاقة رقمية سارية.',
        self::NO_ACTIVE_CARD => 'لا توجد بطاقة رقمية سارية لهذه الأسرة.',
        self::ISSUANCE_DISABLED => 'إصدار بطاقات الأسرة الرقمية غير متاح حاليًا.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason]);
    }

    public function render(Request $request): JsonResponse
    {
        $status = $this->reason === self::ISSUANCE_DISABLED ? 503 : 409;

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
