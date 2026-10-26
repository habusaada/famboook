<?php

namespace App\Http\Controllers\Api\V1\CivilRegistry;

use App\Http\Controllers\Controller;
use App\Http\Resources\CivilImportAuditEventResource;
use App\Models\CivilImportAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Civil import audit history (FB-CIVIL-01A-UI U1; civil-import.audit.view).
 * Read-only; newest first; optionally one run.
 */
class CivilImportAuditController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'run' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $events = CivilImportAuditEvent::query()
            ->with('actor:id,name')
            ->when($data['run'] ?? null, fn ($q, string $run) => $q->where('run_id', $run))
            ->orderByDesc('id')
            ->paginate(25, ['*'], 'page', (int) ($data['page'] ?? 1));

        return CivilImportAuditEventResource::collection($events);
    }
}
