<?php

namespace App\Http\Controllers\Api\V1\CivilRegistry;

use App\Http\Controllers\Controller;
use App\Http\Resources\CivilImportReleaseResource;
use App\Http\Resources\CivilImportRunDetailResource;
use App\Http\Resources\CivilImportRunResource;
use App\Support\CivilImport\CivilImportAudit;
use App\Support\IdentityRef\IdentityImportMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Read-only, metadata-only civil identity import monitoring
 * (FB-CIVIL-01A-UI U1; docs/adr/FB-CIVIL-01A-UI-API-AND-SCREENS.md §2).
 * Routes: civil-import.view, and civil-import.locations.view for file names
 * and line numbers (each such read is audited). No endpoint here executes,
 * changes or publishes anything (S-1), and none returns an identity value.
 */
class CivilImportMonitorController extends Controller
{
    private const RUN_STATUSES = ['RUNNING', 'SUCCEEDED', 'FAILED', 'REJECTED', 'NOTHING_TO_DO'];

    private const ISSUE_CODE = 'regex:/^[A-Z][A-Z0-9_]{0,63}$/';

    public function __construct(private readonly IdentityImportMonitor $monitor) {}

    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->monitor->overview()]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(self::RUN_STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return CivilImportRunResource::collection($this->monitor->runs($data['status'] ?? null, (int) ($data['page'] ?? 1)));
    }

    public function show(string $run): CivilImportRunDetailResource
    {
        return new CivilImportRunDetailResource($this->monitor->run($run) ?? abort(404));
    }

    public function issues(string $run): JsonResponse
    {
        return response()->json(['data' => $this->monitor->issueCounts($run) ?? abort(404)]);
    }

    public function fileLocations(Request $request, string $run): JsonResponse
    {
        $locations = $this->monitor->fileLocations($run) ?? abort(404);
        CivilImportAudit::record(CivilImportAudit::LOCATIONS_VIEWED, $request->user(), $run, null, null, ['scope' => 'FILES']);

        return response()->json(['data' => $locations]);
    }

    public function issueLocations(Request $request, string $run): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', self::ISSUE_CODE],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = $this->monitor->issueLocations($run, $data['code'] ?? null, (int) ($data['page'] ?? 1)) ?? abort(404);
        CivilImportAudit::record(CivilImportAudit::LOCATIONS_VIEWED, $request->user(), $run, null, null,
            array_filter(['scope' => 'ISSUES', 'code' => $data['code'] ?? null, 'page' => $page->currentPage()], fn ($v) => $v !== null));

        return response()->json([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function releases(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return CivilImportReleaseResource::collection($this->monitor->releases((int) ($data['page'] ?? 1)));
    }

    public function release(int $release): CivilImportReleaseResource
    {
        return new CivilImportReleaseResource($this->monitor->release($release) ?? abort(404));
    }
}
