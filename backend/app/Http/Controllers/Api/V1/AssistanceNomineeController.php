<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\NominateFromNeedsAction;
use App\Actions\NominateFromTargetingAction;
use App\Actions\NominateManuallyAction;
use App\Actions\RemoveNomineeAction;
use App\Enums\AssistanceStatus;
use App\Enums\BeneficiaryStatus;
use App\Enums\NominationSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\NominateFromNeedsRequest;
use App\Http\Requests\Api\V1\NominateFromTargetingRequest;
use App\Http\Requests\Api\V1\NominateManuallyRequest;
use App\Http\Requests\Api\V1\TargetingPreviewRequest;
use App\Http\Resources\AssistanceNomineeResource;
use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\Family;
use App\Support\FamilyTargeting;
use App\Support\TargetingCriteria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Targeting preview and nominations for an Assistance (V1-A). The preview
 * is derived on every request and never stored; nominations always require
 * an explicit human selection.
 */
class AssistanceNomineeController extends Controller
{
    public function index(Request $request, Assistance $assistance): AnonymousResourceCollection
    {
        $includeRemoved = $request->boolean('include_removed');

        $nominees = $assistance->beneficiaries()
            ->with(AssistanceNomineeResource::RELATIONS)
            ->when(! $includeRemoved, fn ($q) => $q->where('status', '!=', BeneficiaryStatus::REMOVED))
            ->orderByRaw("CASE WHEN status = 'REMOVED' THEN 1 ELSE 0 END")
            ->latest('nominated_at')
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        $current = $assistance->currentNominees();
        $bySource = (clone $current)->selectRaw('nomination_source, count(*) as total')
            ->groupBy('nomination_source')
            ->pluck('total', 'nomination_source');
        $persons = (clone $current)->whereNotNull('person_id')->count();
        $total = (int) $bySource->sum();

        return AssistanceNomineeResource::collection($nominees)->additional([
            // Derived on read, never stored.
            'summary' => [
                'total' => $total,
                'family' => $total - $persons,
                'person' => $persons,
                'targeting' => (int) ($bySource[NominationSource::TARGETING->value] ?? 0),
                'need' => (int) ($bySource[NominationSource::NEED->value] ?? 0),
                'manual' => (int) ($bySource[NominationSource::MANUAL->value] ?? 0),
                'removed' => $assistance->beneficiaries()->where('status', BeneficiaryStatus::REMOVED)->count(),
            ],
        ]);
    }

    /**
     * Families matching the submitted criteria, with minimal indicators.
     * Writes nothing: no nominations, no criteria, no activity.
     */
    public function preview(TargetingPreviewRequest $request, Assistance $assistance): JsonResponse
    {
        abort_unless(
            in_array($assistance->status, [AssistanceStatus::DRAFT, AssistanceStatus::OPEN], true),
            409,
            'لا يمكن معاينة الاستهداف لهذه المساعدة.'
        );

        $criteria = TargetingCriteria::normalize($request->validated('criteria'), 'criteria');
        $today = Carbon::today();

        $page = FamilyTargeting::query($criteria, $today)
            ->with(['householdHeadMembership.person', 'currentResidence'])
            ->orderBy('family_code')
            ->paginate(min(max($request->integer('per_page', 20), 1), 50));

        $summary = FamilyTargeting::summarize($page->getCollection(), $criteria, $today, $assistance);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Family $family) => [
                'family_code' => $family->family_code,
                'household_head_name' => $family->householdHeadMembership?->person?->full_name,
                'displacement' => $family->currentResidence ? [
                    'status' => $family->currentResidence->displacement_status,
                    'location_text' => $family->currentResidence->displacement_location_text,
                ] : null,
                ...$summary[$family->id],
            ])->values(),
            'criteria' => (object) $criteria,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Safe family search for manual nomination: family code, person code
     * or name. Returns active members' codes and names only.
     */
    public function candidates(Request $request, Assistance $assistance): JsonResponse
    {
        $search = trim((string) $request->validate([
            'search' => ['required', 'string', 'min:2', 'max:100'],
        ])['search']);
        $like = '%'.mb_strtolower($search).'%';

        $families = Family::query()
            ->where(fn ($q) => $q
                ->whereRaw('LOWER(family_code) LIKE ?', [$like])
                ->orWhereHas('memberships', fn ($m) => $m->where('is_active', true)->whereHas('person', fn ($p) => $p
                    ->whereRaw('LOWER(full_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(person_code) LIKE ?', [$like]))))
            ->with([
                'householdHeadMembership.person',
                'memberships' => fn ($m) => $m->where('is_active', true)->with('person'),
            ])
            ->orderBy('family_code')
            ->limit(10)
            ->get();

        $current = $assistance->currentNominees()
            ->whereIn('family_id', $families->pluck('id'))
            ->get(['family_id', 'person_id']);

        return response()->json([
            'data' => $families->map(fn (Family $family) => [
                'family_code' => $family->family_code,
                'household_head_name' => $family->householdHeadMembership?->person?->full_name,
                'family_nominated' => $current->contains(fn ($n) => $n->family_id === $family->id && $n->person_id === null),
                'members' => $family->memberships->map(fn ($m) => [
                    'person_code' => $m->person->person_code,
                    'full_name' => $m->person->full_name,
                    'nominated' => $current->contains('person_id', $m->person_id),
                ])->values(),
            ])->values(),
        ]);
    }

    public function manual(NominateManuallyRequest $request, Assistance $assistance, NominateManuallyAction $action): JsonResponse
    {
        $nominee = $action->handle(
            $assistance,
            $request->validated('family_code'),
            $request->validated('person_code'),
            $request->user()?->id,
        );

        return (new AssistanceNomineeResource($nominee->load(AssistanceNomineeResource::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function fromNeeds(NominateFromNeedsRequest $request, Assistance $assistance, NominateFromNeedsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->handle($assistance, $request->validated('need_ids'), $request->user()?->id),
        ]);
    }

    public function fromTargeting(NominateFromTargetingRequest $request, Assistance $assistance, NominateFromTargetingAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->handle(
                $assistance,
                $request->validated('family_codes'),
                $request->validated('criteria'),
                $request->user()?->id,
            ),
        ]);
    }

    public function remove(Request $request, Assistance $assistance, AssistanceBeneficiary $nominee, RemoveNomineeAction $action): AssistanceNomineeResource
    {
        abort_unless($nominee->assistance_id === $assistance->id, 404);

        return new AssistanceNomineeResource(
            $action->handle($assistance, $nominee, $request->user()?->id)->load(AssistanceNomineeResource::RELATIONS)
        );
    }
}
