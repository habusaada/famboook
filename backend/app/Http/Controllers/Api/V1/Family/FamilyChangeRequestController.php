<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Actions\ChangeRequests\CancelChangeRequestAction;
use App\Actions\ChangeRequests\ResubmitChangeRequestAction;
use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\ChangeRequestType;
use App\Exceptions\ChangeRequestException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Requests\Api\V1\Family\FamilyChangeRequestIndexRequest;
use App\Http\Requests\Api\V1\Family\ResubmitFamilyChangeRequestRequest;
use App\Http\Requests\Api\V1\Family\SubmitFamilyChangeRequestRequest;
use App\Http\Resources\ChangeRequestOutcomeResource;
use App\Http\Resources\FamilyChangeRequestResource;
use App\Http\Resources\FamilyChangeRequestSummaryResource;
use App\Models\ChangeRequest;
use App\Support\ChangeRequests\ChangeRequestOutcome;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\FamilySubmissionPolicy;
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The household head's Change Requests (PWA-5e; docs/11 FP-ADR-073). The
 * Family is ONLY the family.context Family — never a route, query or body
 * value — and the history is Family-subject: every request of that Family,
 * whoever submitted it. A request is looked up inside that Family only, so
 * another Family's uuid is simply not found (404). Mutations are the PWA-5b
 * Domain Actions; nothing here changes a status or the registry. Every
 * response is no-store, private.
 */
class FamilyChangeRequestController extends Controller
{
    public function index(FamilyChangeRequestIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $requests = $this->familyRequests(EnsureFamilyContext::context($request))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return FamilyChangeRequestSummaryResource::collection($requests)
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * The request types this household head may submit NOW: registered,
     * family-submittable handlers, while the submission channel is open FOR
     * THIS FAMILY (FamilySubmissionPolicy: OFF / PILOT allowlist / GENERAL)
     * and the account holds change-request.submit. `meta.submission_enabled`
     * is this Family's channel state only — never the mode, the allowlist or
     * anything about another Family.
     */
    public function types(Request $request, ChangeRequestTypes $types): JsonResponse
    {
        $enabled = FamilySubmissionPolicy::allows(EnsureFamilyContext::context($request));
        $available = $enabled && $request->user()->can('change-request.submit') ? $types->familySubmittable() : [];

        return response()->json([
            'data' => array_map(fn (ChangeRequestType $type) => ['type' => $type->value], $available),
            'meta' => ['submission_enabled' => $enabled],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, string $changeRequest): JsonResponse
    {
        $found = $this->find(EnsureFamilyContext::context($request), $changeRequest);

        return (new FamilyChangeRequestResource($found->load('workflowEvents')))
            ->response()
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(SubmitFamilyChangeRequestRequest $request, SubmitChangeRequestAction $action): JsonResponse
    {
        $outcome = $action->handle(EnsureFamilyContext::context($request), new ChangeRequestSubmission(
            ChangeRequestType::from($request->validated('type')),
            $request->validated('data'),
            $request->validated('reason'),
            $request->validated('client_reference'),
        ));

        return $this->outcome($outcome, $outcome->replayed ? 200 : 201);
    }

    public function resubmit(ResubmitFamilyChangeRequestRequest $request, string $changeRequest, ResubmitChangeRequestAction $action): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);

        return $this->outcome($action->handle($context, $this->find($context, $changeRequest), $request->validated('response')));
    }

    public function cancel(Request $request, string $changeRequest, CancelChangeRequestAction $action): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);

        return $this->outcome($action->handle($context, $this->find($context, $changeRequest)));
    }

    /** The context Family's requests — the only query a family route ever reads. */
    private function familyRequests(FamilyAccessResult $context)
    {
        return ChangeRequest::query()->where('family_id', $context->family->getKey());
    }

    /** One of the context Family's requests by uuid; anything else is not found. */
    private function find(FamilyAccessResult $context, string $uuid): ChangeRequest
    {
        $found = Str::isUuid($uuid) ? $this->familyRequests($context)->where('uuid', $uuid)->first() : null;

        return $found ?? throw new ChangeRequestException(ChangeRequestException::NOT_FOUND);
    }

    private function outcome(ChangeRequestOutcome $outcome, int $status = 200): JsonResponse
    {
        return (new ChangeRequestOutcomeResource($outcome))
            ->response()
            ->setStatusCode($status)
            ->header('Cache-Control', 'no-store, private');
    }
}
