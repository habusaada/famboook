<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A Family Portal identity operation was refused (docs/11 §30a). Thrown
 * inside the Domain Action's transaction, so nothing is left half-done.
 * The reason is an internal code; the rendered message is fixed text and
 * never carries a National ID, a login key or a mobile number.
 */
class FamilyIdentityException extends RuntimeException
{
    public const LOGIN_KEY_TAKEN = 'LOGIN_KEY_TAKEN';

    public const NATIONAL_ID_INVALID = 'NATIONAL_ID_INVALID';

    public const NOT_FAMILY_SIDE = 'NOT_FAMILY_SIDE';

    public const USER_INACTIVE = 'USER_INACTIVE';

    public const NOT_ELIGIBLE = 'NOT_ELIGIBLE';

    public const LINK_EXISTS = 'LINK_EXISTS';

    public const INVALID_TRANSITION = 'INVALID_TRANSITION';

    public const IDENTITY_EXISTS = 'IDENTITY_EXISTS';

    private const MESSAGES = [
        self::LOGIN_KEY_TAKEN => 'لا يمكن تصحيح رقم الهوية: الرقم مرتبط بحساب آخر في بوابة الأسرة.',
        self::INVALID_TRANSITION => 'لا يمكن تنفيذ هذا الإجراء على حالة الربط الحالية.',
    ];

    private const DEFAULT_MESSAGE = 'تعذّر تنفيذ عملية هوية بوابة الأسرة.';

    public function __construct(public readonly string $reason, public readonly ?string $detail = null)
    {
        parent::__construct(self::MESSAGES[$reason] ?? self::DEFAULT_MESSAGE);
    }

    public function render(Request $request): JsonResponse
    {
        $body = ['message' => $this->getMessage()];
        if ($this->reason === self::LOGIN_KEY_TAKEN) {
            $body['errors'] = ['national_id' => [$this->getMessage()]];
        }

        return response()->json($body, $this->reason === self::INVALID_TRANSITION ? 409 : 422);
    }

    /** Expected business outcome, not an error to report. */
    public function report(): bool
    {
        return true;
    }
}
