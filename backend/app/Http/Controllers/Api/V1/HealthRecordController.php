<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CloseHealthRecordAction;
use App\Actions\CreateHealthRecordAction;
use App\Actions\UpdateHealthRecordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CloseHealthRecordRequest;
use App\Http\Requests\Api\V1\StoreHealthRecordRequest;
use App\Http\Requests\Api\V1\UpdateHealthRecordRequest;
use App\Http\Resources\HealthRecordResource;
use App\Models\Family;
use App\Models\PersonHealthRecord;
use App\Support\FamilyHealthSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Person-based health records, reachable only through health-record.*
 * permissions (docs/06-PERMISSIONS.md §40). Nothing here is included in
 * the generic family or person resources.
 */
class HealthRecordController extends Controller
{
    public function index(Request $request, Family $family): AnonymousResourceCollection
    {
        $records = PersonHealthRecord::query()
            ->whereHas('person.activeMembership', fn ($q) => $q->where('family_id', $family->id))
            ->with(['person', 'disabilityType'])
            ->orderByRaw('CASE WHEN ended_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('type')
            ->orderBy('id')
            ->get();

        $today = Carbon::today();
        $user = $request->user();

        return HealthRecordResource::collection($records)->additional([
            'summary' => FamilyHealthSummary::for($family, $today),
            'reference_date' => $today->toDateString(),
            // UX hints only; every write is re-authorized by its request.
            'abilities' => [
                'create' => $user->can('health-record.create'),
                'update' => $user->can('health-record.update'),
                'close' => $user->can('health-record.close'),
            ],
        ]);
    }

    public function store(StoreHealthRecordRequest $request, Family $family, CreateHealthRecordAction $action): JsonResponse
    {
        $record = $action->handle($family, $request->validated(), $request->user()?->id);

        return (new HealthRecordResource($record))->response()->setStatusCode(201);
    }

    public function update(UpdateHealthRecordRequest $request, PersonHealthRecord $healthRecord, UpdateHealthRecordAction $action): HealthRecordResource
    {
        return new HealthRecordResource(
            $action->handle($healthRecord, $request->validated(), $request->user()?->id)
        );
    }

    public function close(CloseHealthRecordRequest $request, PersonHealthRecord $healthRecord, CloseHealthRecordAction $action): HealthRecordResource
    {
        return new HealthRecordResource(
            $action->handle($healthRecord, $request->validated('ended_at'), $request->user()?->id)
        );
    }
}
