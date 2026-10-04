<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A life-status confirmation was refused (ConfirmPersonAliveAction). Thrown
 * inside the Domain Action's transaction, so nothing changed. Rendered as
 * 409 with a stable code and fixed text.
 */
class PersonLifeStatusException extends RuntimeException
{
    public const PERSON_ALREADY_ALIVE = 'PERSON_ALREADY_ALIVE';

    public const PERSON_DECEASED = 'PERSON_DECEASED';

    public const INCONSISTENT_LIFE_RECORD = 'INCONSISTENT_LIFE_RECORD';

    private const MESSAGES = [
        self::PERSON_ALREADY_ALIVE => 'الحالة الحياتية لهذا الشخص مسجّلة مسبقًا: حي.',
        self::PERSON_DECEASED => 'وفاة هذا الشخص مسجّلة، ولا يمكن تأكيد أنه على قيد الحياة.',
        self::INCONSISTENT_LIFE_RECORD => 'سجل الحالة الحياتية لهذا الشخص غير متّسق (يحمل تاريخ وفاة). يلزم تصحيح السجل أولًا.',
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
