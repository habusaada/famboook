<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The uploaded workbook cannot be staged (docs/03 §96a): unreadable, no or
 * several import sheets, duplicate/invalid headers, too many rows. Rendered
 * as a 422 on `file`. The message names structure only — never cell values.
 * Nothing was staged.
 */
class InvalidImportWorkbookException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['file' => [$this->getMessage()]],
        ], 422);
    }

    /** Expected validation outcome, not an error to report. */
    public function report(): bool
    {
        return true;
    }
}
