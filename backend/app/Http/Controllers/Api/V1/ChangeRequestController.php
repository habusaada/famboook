<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ChangeRequests\ApplyChangeRequestAction;
use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\RejectChangeRequestAction;
use App\Actions\ChangeRequests\ReturnChangeRequestForClarificationAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Enums\WorkflowEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ApproveChangeRequestRequest;
use App\Http\Requests\Api\V1\ChangeRequestIndexRequest;
use App\Http\Requests\Api\V1\RejectChangeRequestRequest;
use App\Http\Requests\Api\V1\ReturnChangeRequestRequest;
use App\Http\Resources\ChangeRequestOutcomeResource;
use App\Http\Resources\ChangeRequestResource;
use App\Http\Resources\ChangeRequestSummaryResource;
use App\Models\ChangeRequest;
use App\Models\WorkflowEvent;
use App\Support\ChangeRequests\ChangeRequestOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Staff Change Request API (PWA-5c; docs/06 AUTH-ADR-089). Thin: every
 * workflow step is its PWA-5b Domain Action, which re-checks the transition,
 * the actor's side and permission, fresh canonical state and the base
 * fingerprint under the request lock. Refusals are ChangeRequestException,
 * rendered centrally ({message, code}, no-store). Requests are addressed by
 * uuid only; responses are no-store, private.
 *
 * apply() calls ApplyChangeRequestAction DIRECTLY — never inside a
 * DB::transaction or transactional middleware: the action owns its main
 * transaction AND the separate post-rollback transaction that records a
 * failed attempt, which an enclosing transaction would erase.
 */
class ChangeRequestController extends Controller
{
    /** The Staff queue: newest submissions first, paginated, filters allow-listed. */
    public function index(ChangeRequestIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $requests = ChangeRequest::query()
            ->with(ChangeRequestSummaryResource::RELATIONS)
            // The latest APPLY_FAILED code, for the reject hint, in the same query.
            ->addSelect(['latest_apply_failure' => WorkflowEvent::query()
                ->select('reason_code')
                ->where('workflowable_type', (new ChangeRequest)->getMorphClass())
                ->whereColumn('workflowable_id', 'change_requests.id')
                ->where('event_type', WorkflowEventType::APPLY_FAILED)
                ->orderByDesc('id')
                ->limit(1)])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['family'] ?? null, fn ($q, $code) => $q->whereHas('family', fn ($f) => $f->where('family_code', $code)))
            ->when($filters['request_code'] ?? null, fn ($q, $code) => $q->where('request_code', $code))
            ->when($filters['submitted_from'] ?? null, fn ($q, $date) => $q->where('submitted_at', '>=', $date.' 00:00:00'))
            ->when($filters['submitted_to'] ?? null, fn ($q, $date) => $q->where('submitted_at', '<=', $date.' 23:59:59'))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return ChangeRequestSummaryResource::collection($requests)
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }

    /** The full Staff review view, with the timeline. */
    public function show(ChangeRequest $changeRequest): JsonResponse
    {
        return (new ChangeRequestResource($changeRequest->load(ChangeRequestResource::RELATIONS)))
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }

    public function startReview(Request $request, ChangeRequest $changeRequest, StartChangeRequestReviewAction $action): JsonResponse
    {
        return $this->outcome($action->handle($changeRequest, $request->user()));
    }

    public function return(ReturnChangeRequestRequest $request, ChangeRequest $changeRequest, ReturnChangeRequestForClarificationAction $action): JsonResponse
    {
        return $this->outcome($action->handle(
            $changeRequest, $request->user(), $request->validated('public_message'), $request->validated('internal_note'),
        ));
    }

    public function approve(ApproveChangeRequestRequest $request, ChangeRequest $changeRequest, ApproveChangeRequestAction $action): JsonResponse
    {
        return $this->outcome($action->handle($changeRequest, $request->user(), $request->evidence()));
    }

    public function reject(RejectChangeRequestRequest $request, ChangeRequest $changeRequest, RejectChangeRequestAction $action): JsonResponse
    {
        return $this->outcome($action->handle(
            $changeRequest, $request->user(), $request->reason(), $request->validated('public_message'), $request->validated('internal_note'),
        ));
    }

    /** No transaction here — see the class comment. */
    public function apply(Request $request, ChangeRequest $changeRequest, ApplyChangeRequestAction $action): JsonResponse
    {
        return $this->outcome($action->handle($changeRequest, $request->user()));
    }

    private function outcome(ChangeRequestOutcome $outcome): JsonResponse
    {
        return (new ChangeRequestOutcomeResource($outcome))
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }
}
