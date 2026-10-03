<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A coordinator administration operation was refused (docs/11 §8, §30a).
 * Thrown inside the Domain Action's transaction, so nothing is left
 * half-done. Staff-facing: the message says what to fix, never a National
 * ID, a login key or a mobile number.
 */
class CoordinatorException extends RuntimeException
{
    public const NO_FAMILY_ACCOUNT = 'NO_FAMILY_ACCOUNT';

    public const NOT_ELIGIBLE = 'NOT_ELIGIBLE';

    public const ALREADY_COORDINATOR = 'ALREADY_COORDINATOR';

    public const NOT_COORDINATOR = 'NOT_COORDINATOR';

    public const TARGET_INACTIVE = 'TARGET_INACTIVE';

    public const SCOPE_EXISTS = 'SCOPE_EXISTS';

    public const ALREADY_REVOKED = 'ALREADY_REVOKED';

    private const MESSAGES = [
        self::NO_FAMILY_ACCOUNT => 'لا يوجد حساب بوابة أسرة لهذا الشخص.',
        self::NOT_ELIGIBLE => 'لا يمكن تعيين منسق: صاحب الحساب ليس رب أسرة مؤهلًا لاستخدام بوابة الأسرة.',
        self::ALREADY_COORDINATOR => 'هذا الحساب يحمل دور المنسق مسبقًا.',
        self::NOT_COORDINATOR => 'هذا الحساب لا يحمل دور المنسق.',
        self::TARGET_INACTIVE => 'لا يمكن التعيين على عشيرة أو مجموعة أو فرع غير نشط.',
        self::SCOPE_EXISTS => 'هذا النطاق معيّن لهذا المنسق مسبقًا.',
        self::ALREADY_REVOKED => 'هذا التعيين ملغى مسبقًا.',
    ];

    private const CONFLICTS = [self::ALREADY_COORDINATOR, self::NOT_COORDINATOR, self::SCOPE_EXISTS, self::ALREADY_REVOKED];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason]);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage(), 'code' => $this->reason],
            in_array($this->reason, self::CONFLICTS, true) ? 409 : 422,
        );
    }

    /** Expected business outcome, not an error to report. */
    public function report(): bool
    {
        return true;
    }
}
