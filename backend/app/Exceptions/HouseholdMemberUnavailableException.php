<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A household member cannot be reached through the Family Portal (docs/11
 * FP-ADR-064): ONE answer for every reason — a malformed, random or foreign
 * member reference, an ended membership, an unavailable or soft-deleted
 * Person, or the signed-in head's own membership (self values are revealed
 * only through the self path). The reason is never sent: 404 with a fixed
 * code and text, no-store.
 */
class HouseholdMemberUnavailableException extends RuntimeException
{
    public const CODE = 'HOUSEHOLD_MEMBER_UNAVAILABLE';

    public const MESSAGE = 'بيانات هذا الفرد غير متاحة.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(Request $request): JsonResponse
    {
        return response()
            ->json(['message' => self::MESSAGE, 'code' => self::CODE], 404)
            ->header('Cache-Control', 'no-store, private');
    }

    /** Expected outcome, not an error to report (and never with a reason). */
    public function report(): bool
    {
        return true;
    }
}
