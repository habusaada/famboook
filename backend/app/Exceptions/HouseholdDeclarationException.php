<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A household declaration was refused (RecordHouseholdDeclarationAction).
 * Thrown inside the Domain Action's transaction, so nothing changed.
 * Rendered as 409 with a stable code and fixed text.
 */
class HouseholdDeclarationException extends RuntimeException
{
    // The Family's current declaration is not the one the caller saw: a
    // newer one was recorded in the meantime (stale screen, parallel write).
    public const HOUSEHOLD_DECLARATION_CHANGED = 'HOUSEHOLD_DECLARATION_CHANGED';

    private const MESSAGES = [
        self::HOUSEHOLD_DECLARATION_CHANGED => 'تغيّر الإقرار الحالي لهذه الأسرة منذ فتح الصفحة. راجع الإقرار المحدَّث قبل تسجيل إقرار جديد.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason]);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason], 409);
    }

    /** Expected business outcome, not an error to report. */
    public function report(): bool
    {
        return true;
    }
}
