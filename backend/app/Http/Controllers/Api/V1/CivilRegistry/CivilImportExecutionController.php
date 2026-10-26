<?php

namespace App\Http\Controllers\Api\V1\CivilRegistry;

use App\Actions\CancelCivilImportRequestAction;
use App\Actions\QueueCivilImportRequestAction;
use App\Exceptions\CivilImportRequestException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CivilImportRequestResource;
use App\Http\Resources\CivilImportSourceResource;
use App\Models\CivilImportRequest;
use App\Models\CivilImportSource;
use App\Support\CivilImport\CivilImportReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Civil identity import execution requests (FB-CIVIL-01A-UI U2;
 * docs/adr/FB-CIVIL-01A-UI-API-AND-SCREENS.md §3). Every write only RECORDS a
 * request (202) through QueueCivilImportRequestAction; the dedicated worker
 * executes it (S-1/S-2). Inputs are opaque ids only — a source id, a run uuid
 * or a release id — never a path. Staging and validation have separate
 * permissions (route middleware, re-checked by the action).
 */
class CivilImportExecutionController extends Controller
{
    private const IDEMPOTENCY_KEY = '/^[A-Za-z0-9_-]{8,64}$/';

    public function __construct(private readonly QueueCivilImportRequestAction $queue) {}

    public function sources(): AnonymousResourceCollection
    {
        return CivilImportSourceResource::collection(CivilImportSource::query()->orderByDesc('available')->orderByDesc('last_seen_at')->get());
    }

    public function validateSource(Request $request, string $source): JsonResponse
    {
        return $this->queued($request, CivilImportRequest::VALIDATE_SOURCE, ['source_id' => $source]);
    }

    public function stage(Request $request): JsonResponse
    {
        $data = $request->validate(['source_id' => ['required', 'uuid']]);

        return $this->queued($request, CivilImportRequest::STAGE, ['source_id' => $data['source_id']]);
    }

    public function abort(Request $request, string $run): JsonResponse
    {
        $request->validate(['reason_code' => ['required', 'string', 'in:OPERATOR_DECISION,SOURCE_REPLACED,DATA_PROBLEM']]);

        return $this->queued($request, CivilImportRequest::ABORT, ['run_id' => $run]);
    }

    public function assess(Request $request, string $run): JsonResponse
    {
        return $this->queued($request, CivilImportRequest::ASSESS, ['run_id' => $run]);
    }

    public function prepare(Request $request, string $run): JsonResponse
    {
        return $this->queued($request, CivilImportRequest::PREPARE, ['run_id' => $run]);
    }

    public function verify(Request $request, int $release): JsonResponse
    {
        return $this->queued($request, CivilImportRequest::VERIFY, ['release_id' => $release]);
    }

    public function requests(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['run' => ['nullable', 'uuid'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = CivilImportRequest::query()->with(['requester', 'source'])->orderByDesc('queued_at')->orderByDesc('id');
        if (isset($data['run'])) {
            $query->where('run_id', $data['run']);
        }

        return CivilImportRequestResource::collection($query->paginate(25, ['*'], 'page', (int) ($data['page'] ?? 1)));
    }

    public function show(string $request): CivilImportRequestResource
    {
        $found = CivilImportRequest::query()->with(['requester', 'source'])->find($request)
            ?? throw new CivilImportRequestException(CivilImportReason::REQUEST_NOT_FOUND);

        return new CivilImportRequestResource($found);
    }

    public function cancel(Request $request, string $civilRequest, CancelCivilImportRequestAction $cancel): JsonResponse
    {
        $result = $cancel->handle($request->user(), $civilRequest);

        return (new CivilImportRequestResource($result['request']->load(['requester', 'source'])))
            ->response()->setStatusCode($result['immediate'] ? 200 : 202);
    }

    /** @param array<string, mixed> $target */
    private function queued(Request $request, string $type, array $target): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if ($key !== null && preg_match(self::IDEMPOTENCY_KEY, $key) !== 1) {
            abort(422, 'Invalid Idempotency-Key.');
        }
        $result = $this->queue->handle($request->user(), $type, $target, $key);

        return (new CivilImportRequestResource($result['request']->load(['requester', 'source'])))
            ->response()->setStatusCode($result['created'] ? 202 : 200);
    }
}
