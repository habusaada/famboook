<?php

namespace App\Exceptions;

use App\Models\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The same file (SHA-256) is already staged for the same Clan in a batch
 * that has not FAILED (docs/03 §96a). Nothing new is staged; the response
 * points to the existing batch (409).
 */
class DuplicateImportBatchException extends RuntimeException
{
    public const MESSAGE = 'هذا الملف مرفوع مسبقًا لنفس العشيرة / العائلة.';

    public function __construct(public readonly ImportBatch $existing)
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => self::MESSAGE,
            'existing_batch' => ['id' => $this->existing->uuid, 'status' => $this->existing->status],
        ], 409);
    }

    /** Expected business outcome, not an error to report. */
    public function report(): bool
    {
        return true;
    }
}
