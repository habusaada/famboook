<?php

namespace App\Exceptions;

use App\Support\CivilImport\CivilImportReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A refused civil import execution request (FB-CIVIL-01A-UI U2): fixed
 * Arabic text and a stable code (CivilImportReason) — never a path, a file
 * name, an identity value or a database message.
 */
class CivilImportRequestException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct((string) CivilImportReason::message($reason));
    }

    public function render(Request $request): JsonResponse
    {
        $status = match ($this->reason) {
            CivilImportReason::REQUEST_NOT_FOUND => 404,
            CivilImportReason::NOT_ALLOWED, CivilImportReason::CONFIRMATION_BY_REQUESTER_ONLY => 403,
            CivilImportReason::CONFIRMATION_MISMATCH, CivilImportReason::REAUTH_FAILED => 422,
            CivilImportReason::REAUTH_LOCKED => 429,
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
