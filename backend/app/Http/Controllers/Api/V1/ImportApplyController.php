<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RunImportApplyChunkAction;
use App\Actions\StartImportApplyAction;
use App\Exceptions\ImportApplyExecutionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StartImportApplyRequest;
use App\Models\ImportBatch;
use App\Support\Import\Apply\ImportApplyProgress;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Import Apply API (docs/03 §96b, docs/06 §61) — thin wrappers around the
 * runner actions, which re-check import.apply themselves:
 *   POST apply/start   import.apply   body: {plan_fingerprint} only
 *   POST apply/run     import.apply   one chunk (server-side budget)
 *   POST apply/resume  import.apply   PARTIALLY_APPLIED → one chunk
 *   GET  apply         import.review  progress, read only
 * Responses carry counts, statuses and stable codes only. A row failure
 * inside a chunk is a normal 200 with outcome FAILED. Completion is internal
 * to the runner; there is no completion endpoint.
 */
class ImportApplyController extends Controller
{
    public function start(StartImportApplyRequest $request, ImportBatch $importBatch, StartImportApplyAction $action): JsonResponse
    {
        return $this->progress($importBatch, $this->safely(
            fn () => $action->handle($importBatch, $request->user(), $request->validated('plan_fingerprint')),
        ));
    }

    public function run(Request $request, ImportBatch $importBatch, RunImportApplyChunkAction $action): JsonResponse
    {
        return $this->progress($importBatch, $this->safely(fn () => $action->handle($importBatch, $request->user())));
    }

    public function resume(Request $request, ImportBatch $importBatch, RunImportApplyChunkAction $action): JsonResponse
    {
        return $this->progress($importBatch, $this->safely(fn () => $action->resume($importBatch, $request->user())));
    }

    /**
     * Read-only progress for reviewers. Deliberately omits the failing row
     * number (least exposure) and does not compute pending links.
     */
    public function show(ImportBatch $importBatch): JsonResponse
    {
        $progress = ImportApplyProgress::of($importBatch, 'STATUS')->toArray();
        unset($progress['error_row_number'], $progress['pending_links'], $progress['executed_in_chunk'], $progress['skipped_already_applied']);

        return response()->json(['data' => ['batch_id' => $importBatch->uuid, ...$progress]]);
    }

    private function progress(ImportBatch $batch, ImportApplyProgress $progress): JsonResponse
    {
        return response()->json(['data' => ['batch_id' => $batch->uuid, ...$progress->toArray()]]);
    }

    /**
     * Wraps the action call only (authentication, validation and route-model
     * binding run before it). Framework outcomes keep their normal handling;
     * any other cause becomes UNEXPECTED_ERROR, so its message (which may
     * contain identity data, e.g. SQL bindings) is never rendered or logged.
     *
     * @param  callable(): ImportApplyProgress  $call
     */
    private function safely(callable $call): ImportApplyProgress
    {
        try {
            return $call();
        } catch (ImportApplyExecutionException|AuthorizationException|AuthenticationException|ValidationException|ModelNotFoundException|HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ImportApplyExecutionException('UNEXPECTED_ERROR', null, $e);
        }
    }
}
