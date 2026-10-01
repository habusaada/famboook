<?php

namespace App\Exceptions;

use App\Models\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * A structured Apply execution failure (docs/03 §96b): a stable code and the
 * source row number — never exception text or row data. The original cause is
 * kept only as `previous` for the runner; it is never rendered or logged
 * (its message may contain identity data), only its class name.
 *
 * Over HTTP (only the import.apply POST endpoints can raise it) it becomes a
 * fixed message per code + the code + the row number.
 */
class ImportApplyExecutionException extends RuntimeException
{
    /** HTTP status per code reaching the API; any other code → 409. */
    public const STATUS = [
        'APPLY_IN_PROGRESS' => 409,
        'APPLY_STATE_INVALID' => 409,
        'APPLY_PLAN_CHANGED' => 409,
        'APPLY_INTEGRITY_INVALID' => 409,
        'APPLY_PRECONDITIONS_FAILED' => 422,
        'APPLY_PLAN_BLOCKED' => 422,
        'UNEXPECTED_ERROR' => 500,
    ];

    /** Fixed, data-free messages. */
    public const MESSAGES = [
        'APPLY_IN_PROGRESS' => 'عملية اعتماد أخرى قيد التنفيذ لهذه الدفعة. حاول لاحقًا.',
        'APPLY_STATE_INVALID' => 'حالة الدفعة لا تسمح بهذه العملية.',
        'APPLY_PLAN_CHANGED' => 'تغيّرت خطة الاعتماد منذ المعاينة. أعد المعاينة قبل البدء.',
        'APPLY_INTEGRITY_INVALID' => 'تعذّر التحقق من سلامة ما اعتُمد سابقًا لهذه الدفعة. لا يمكن المتابعة.',
        'APPLY_PRECONDITIONS_FAILED' => 'شروط الاعتماد غير مستوفاة لهذه الدفعة.',
        'APPLY_PLAN_BLOCKED' => 'خطة الاعتماد تحتوي صفوفًا محجوبة.',
        'UNEXPECTED_ERROR' => 'حدث خطأ غير متوقع أثناء الاعتماد.',
    ];

    public const DEFAULT_MESSAGE = 'تعذّر تنفيذ عملية الاعتماد.';

    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $rowNumber = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 0, $previous);
    }

    public function status(): int
    {
        return self::STATUS[$this->errorCode] ?? 409;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => self::MESSAGES[$this->errorCode] ?? self::DEFAULT_MESSAGE,
            'code' => $this->errorCode,
            'row_number' => $this->rowNumber,
        ], $this->status());
    }

    /**
     * Replaces Laravel's default reporting, which would log the message,
     * trace and the whole `previous` chain. Safe metadata only.
     */
    public function report(): bool
    {
        $batch = request()->route('importBatch');
        $context = [
            'code' => $this->errorCode,
            'row_number' => $this->rowNumber,
            'batch' => $batch instanceof ImportBatch ? $batch->uuid : null,
            'cause' => $this->getPrevious() !== null ? $this->getPrevious()::class : null,
        ];
        $this->status() >= 500
            ? Log::error('Import apply request failed', $context)
            : Log::warning('Import apply request refused', $context);

        return true;
    }
}
