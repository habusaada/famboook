<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CompleteAssessmentAction;
use App\Actions\CreateAssessmentAction;
use App\Actions\UpdateAssessmentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CompleteAssessmentRequest;
use App\Http\Requests\Api\V1\StoreAssessmentRequest;
use App\Http\Requests\Api\V1\UpdateAssessmentRequest;
use App\Http\Resources\AssessmentResource;
use App\Http\Resources\AssessmentSummaryResource;
use App\Models\Assessment;
use App\Models\Family;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Family-level assessments V1 (docs/06-PERMISSIONS.md §47, assessment.*
 * permissions only). There is deliberately no delete endpoint, and no
 * assessment content is included in the generic family or person resources.
 */
class AssessmentController extends Controller
{
    public function index(Request $request, Family $family): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 50);

        $assessments = $family->assessments()
            ->with(['creator:id,name', 'results.domain'])
            // Newest business date first; same date → most recently entered.
            ->orderByDesc('assessment_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $user = $request->user();

        return AssessmentSummaryResource::collection($assessments)->additional([
            // UX hint only; every write is re-authorized by its request.
            'abilities' => ['create' => $user->can('assessment.create')],
        ]);
    }

    public function store(StoreAssessmentRequest $request, Family $family, CreateAssessmentAction $action): JsonResponse
    {
        $assessment = $action->handle($family, $request->validated(), $request->user()?->id);

        return $this->detail($request, $assessment)->response()->setStatusCode(201);
    }

    public function show(Request $request, Assessment $assessment): AssessmentResource
    {
        return $this->detail($request, $assessment);
    }

    public function update(UpdateAssessmentRequest $request, Assessment $assessment, UpdateAssessmentAction $action): AssessmentResource
    {
        return $this->detail(
            $request,
            $action->handle($assessment, $request->validated(), $request->user()?->id)
        );
    }

    public function complete(CompleteAssessmentRequest $request, Assessment $assessment, CompleteAssessmentAction $action): AssessmentResource
    {
        return $this->detail(
            $request,
            $action->handle($assessment, $request->validated(), $request->user()?->id)
        );
    }

    private function detail(Request $request, Assessment $assessment): AssessmentResource
    {
        $assessment->load(['family', 'creator:id,name', 'completer:id,name', 'results.domain']);
        $user = $request->user();
        $draft = $assessment->isDraft();

        return (new AssessmentResource($assessment))->additional([
            // UX hints only; a completed assessment offers no actions.
            'abilities' => [
                'update' => $draft && $user->can('assessment.update'),
                'complete' => $draft && $user->can('assessment.complete'),
            ],
        ]);
    }
}
