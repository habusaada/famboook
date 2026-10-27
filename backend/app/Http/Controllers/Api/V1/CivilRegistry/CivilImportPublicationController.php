<?php

namespace App\Http\Controllers\Api\V1\CivilRegistry;

use App\Actions\DecideCivilImportPublicationAction;
use App\Actions\RequestCivilImportPublicationAction;
use App\Exceptions\CivilImportRequestException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CivilImportPublicationRequestResource;
use App\Http\Resources\CivilImportRequestResource;
use App\Models\CivilImportPublicationRequest;
use App\Support\CivilImport\CivilImportPublications;
use App\Support\CivilImport\CivilImportReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Single-operator publication (FB-CIVIL-01A-UI U3, D-UI-12a;
 * docs/adr/FB-CIVIL-01A-UI-API-AND-SCREENS.md §4). Nothing here publishes:
 * the requester's confirmation (password re-entered) only queues the worker's
 * PUBLISH request, which re-checks every condition (S-3). Confirming needs
 * its own gated permission; reads expire stale requests first.
 */
class CivilImportPublicationController extends Controller
{
    public function __construct(private readonly CivilImportPublications $publications) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([...CivilImportPublicationRequest::OPEN, ...CivilImportPublicationRequest::CLOSED])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $this->publications->expireStale();
        $query = CivilImportPublicationRequest::query()->with(['requester', 'decider', 'publishRequest'])
            ->orderByRaw("CASE WHEN status = 'PENDING_APPROVAL' THEN 0 WHEN status = 'APPROVED' THEN 1 ELSE 2 END")
            ->orderByDesc('requested_at');
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        return CivilImportPublicationRequestResource::collection($query->paginate(25, ['*'], 'page', (int) ($data['page'] ?? 1)));
    }

    public function show(string $publication): CivilImportPublicationRequestResource
    {
        $this->publications->expireStale();

        return new CivilImportPublicationRequestResource($this->find($publication));
    }

    public function store(Request $request, int $release, RequestCivilImportPublicationAction $action): JsonResponse
    {
        $data = $request->validate(['justification_code' => ['required', 'string', Rule::in(CivilImportPublicationRequest::JUSTIFICATIONS)]]);
        $publication = $action->handle($request->user(), $release, $data['justification_code']);

        return (new CivilImportPublicationRequestResource($publication->load(['requester', 'decider'])))->response()->setStatusCode(201);
    }

    public function confirm(Request $request, string $publication, DecideCivilImportPublicationAction $decide): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'confirm_release_id' => ['required', 'integer', 'min:1'],
            'confirm_expected_presence' => ['required', 'integer', 'min:0'],
        ]);
        $result = $decide->confirm($request->user(), $publication, $data['password'], (int) $data['confirm_release_id'],
            (int) $data['confirm_expected_presence']);

        return response()->json(['data' => [
            'publication_request' => (new CivilImportPublicationRequestResource($result['publication']->load(['requester', 'decider'])))->toArray($request),
            'publish_request' => (new CivilImportRequestResource($result['request']->load(['requester', 'source'])))->toArray($request),
        ]], 202);
    }

    public function withdraw(Request $request, string $publication, DecideCivilImportPublicationAction $decide): CivilImportPublicationRequestResource
    {
        return new CivilImportPublicationRequestResource($decide->withdraw($request->user(), $publication)->load(['requester', 'decider']));
    }

    private function find(string $id): CivilImportPublicationRequest
    {
        return CivilImportPublicationRequest::query()->with(['requester', 'decider', 'publishRequest'])->find($id)
            ?? throw new CivilImportRequestException(CivilImportReason::REQUEST_NOT_FOUND);
    }
}
