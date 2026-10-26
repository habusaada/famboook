<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The metadata-only monitoring connection (FB-CIVIL-01A-UI, D-UI-9) is not
 * configured or cannot be opened safely. Answered as 503 with a fixed code;
 * never a credential, a database message or a fallback to the application role.
 */
class CivilImportMonitorUnavailableException extends RuntimeException
{
    public const NOT_CONFIGURED = 'MONITOR_NOT_CONFIGURED';

    public const UNAVAILABLE = 'MONITOR_UNAVAILABLE';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->reason === self::NOT_CONFIGURED
                ? 'Civil import monitoring is not configured on this server.'
                : 'Civil import monitoring is temporarily unavailable.',
            'code' => $this->reason,
        ], 503);
    }
}
