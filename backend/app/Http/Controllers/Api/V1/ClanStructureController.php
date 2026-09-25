<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ManageClanStructureAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\BranchGroupResource;
use App\Http\Resources\BranchResource;
use App\Http\Resources\ClanResource;
use App\Models\Branch;
use App\Models\BranchGroup;
use App\Models\Clan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Clan / Branch Group / Branch reference data (docs/06 §56a).
 *
 * Reading (clan.view): active structure only — what can be selected for a
 * Family. Managers (clan.manage) may pass include_inactive=1 to see the
 * full tree. Writing (clan.manage): create/update/activate/deactivate and
 * order; no delete endpoint.
 */
class ClanStructureController extends Controller
{
    private const CODE = ['required', 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_]*$/'];

    private const CODE_MESSAGE = 'الرمز يجب أن يبدأ بحرف إنجليزي كبير ويحتوي على أحرف كبيرة وأرقام و _ فقط.';

    public function index(Request $request): AnonymousResourceCollection
    {
        $all = $this->includeInactive($request);

        $clans = Clan::query()
            ->when(! $all, fn ($q) => $q->where('is_active', true))
            ->with(['branchGroups' => fn ($q) => $q
                ->when(! $all, fn ($g) => $g->where('is_active', true))
                ->with(['branches' => fn ($b) => $b
                    ->when(! $all, fn ($x) => $x->where('is_active', true))
                    ->when($all, fn ($x) => $x->withCount('families'))])])
            ->when($all, fn ($q) => $q->withCount('families'))
            ->orderBy('name')
            ->get();

        return ClanResource::collection($clans);
    }

    public function groups(Request $request, Clan $clan): AnonymousResourceCollection
    {
        $all = $this->includeInactive($request);
        abort_if(! $all && ! $clan->is_active, 404);

        return BranchGroupResource::collection(
            $clan->branchGroups()
                ->when(! $all, fn ($q) => $q->where('is_active', true))
                ->with(['branches' => fn ($b) => $b->when(! $all, fn ($x) => $x->where('is_active', true))])
                ->get()
        );
    }

    public function branches(Request $request, Clan $clan): AnonymousResourceCollection
    {
        $all = $this->includeInactive($request);
        abort_if(! $all && ! $clan->is_active, 404);
        $group = $request->validate(['group' => ['sometimes', 'uuid']])['group'] ?? null;

        $branches = Branch::query()
            ->where('branches.clan_id', $clan->id)
            ->join('branch_groups', 'branch_groups.id', '=', 'branches.branch_group_id')
            ->when($group, fn ($q) => $q->where('branch_groups.uuid', $group))
            ->when(! $all, fn ($q) => $q->where('branches.is_active', true)->where('branch_groups.is_active', true))
            ->orderBy('branch_groups.sort_order')
            ->orderBy('branches.sort_order')
            ->orderBy('branches.id')
            ->select('branches.*')
            ->with('group')
            ->get();

        return BranchResource::collection($branches);
    }

    public function storeClan(Request $request, ManageClanStructureAction $action): JsonResponse
    {
        $data = $request->validate([
            'code' => [...self::CODE, Rule::unique('clans', 'code')],
            'name' => ['required', 'string', 'max:150'],
        ], $this->messages());

        return (new ClanResource($action->createClan($data)))->response()->setStatusCode(201);
    }

    public function updateClan(Request $request, Clan $clan, ManageClanStructureAction $action): ClanResource
    {
        $data = $request->validate([
            'code' => ['prohibited'],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ], $this->messages());

        return new ClanResource($action->updateClan($clan, $data));
    }

    public function storeGroup(Request $request, Clan $clan, ManageClanStructureAction $action): JsonResponse
    {
        $data = $request->validate([
            'code' => [...self::CODE, Rule::unique('branch_groups', 'code')->where('clan_id', $clan->id)],
            // Optional: unnamed administrative containers are valid.
            'name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ], $this->messages());

        return (new BranchGroupResource($action->createGroup($clan, $data)->load('branches')))->response()->setStatusCode(201);
    }

    public function updateGroup(Request $request, BranchGroup $branchGroup, ManageClanStructureAction $action): BranchGroupResource
    {
        $data = $request->validate([
            'code' => ['prohibited'],
            'clan_id' => ['prohibited'],
            'name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ], $this->messages());

        return new BranchGroupResource($action->updateGroup($branchGroup, $data)->load('branches'));
    }

    public function storeBranch(Request $request, BranchGroup $branchGroup, ManageClanStructureAction $action): JsonResponse
    {
        $data = $request->validate([
            // Unique within the Clan: families select branches by code.
            'code' => [...self::CODE, Rule::unique('branches', 'code')->where('clan_id', $branchGroup->clan_id)],
            'name' => ['required', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ], $this->messages());

        return (new BranchResource($action->createBranch($branchGroup, $data)))->response()->setStatusCode(201);
    }

    public function updateBranch(Request $request, Branch $branch, ManageClanStructureAction $action): BranchResource
    {
        $data = $request->validate([
            'code' => ['prohibited'],
            'branch_group_id' => ['prohibited'],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ], $this->messages());

        return new BranchResource($action->updateBranch($branch, $data));
    }

    private function includeInactive(Request $request): bool
    {
        if (! $request->boolean('include_inactive')) {
            return false;
        }
        abort_unless($request->user()->can('clan.manage'), 403);

        return true;
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'code.required' => 'الرمز مطلوب.',
            'code.regex' => self::CODE_MESSAGE,
            'code.unique' => 'هذا الرمز مستخدم مسبقًا.',
            'code.prohibited' => 'لا يمكن تغيير الرمز بعد الإنشاء.',
            'name.required' => 'الاسم مطلوب.',
            'name.max' => 'الاسم طويل جدًا.',
            'sort_order.integer' => 'الترتيب يجب أن يكون رقمًا صحيحًا.',
            'clan_id.prohibited' => 'لا يمكن نقل المجموعة إلى عشيرة / عائلة أخرى.',
            'branch_group_id.prohibited' => 'لا يمكن نقل الفرع إلى مجموعة أخرى.',
        ];
    }
}
