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

        $branches = fn ($b) => $b
            ->when(! $all, fn ($x) => $x->where('is_active', true))
            ->when($all, fn ($x) => $x->withCount('families'));

        $clans = Clan::query()
            ->when(! $all, fn ($q) => $q->where('is_active', true))
            ->with([
                'branchGroups' => fn ($q) => $q
                    ->when(! $all, fn ($g) => $g->where('is_active', true))
                    ->with(['branches' => $branches]),
                // Ungrouped branches are part of the structure, never hidden.
                'ungroupedBranches' => $branches,
            ])
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
            // Left join: ungrouped branches are listed too (after the groups).
            ->leftJoin('branch_groups', 'branch_groups.id', '=', 'branches.branch_group_id')
            ->when($group, fn ($q) => $q->where('branch_groups.uuid', $group))
            ->when(! $all, fn ($q) => $q->where('branches.is_active', true)->where(
                fn ($g) => $g->whereNull('branches.branch_group_id')->orWhere('branch_groups.is_active', true)
            ))
            ->orderByRaw('CASE WHEN branches.branch_group_id IS NULL THEN 1 ELSE 0 END')
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

    /** A Branch of the Clan; branch_group_id (a Group's public id) is optional. */
    public function storeBranch(Request $request, Clan $clan, ManageClanStructureAction $action): JsonResponse
    {
        $data = $request->validate([
            // Unique within the Clan: families select branches by code.
            'code' => [...self::CODE, Rule::unique('branches', 'code')->where('clan_id', $clan->id)],
            'name' => ['required', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'branch_group_id' => $this->groupRules($clan->id),
        ], $this->messages());

        $branch = $action->createBranch($clan, $this->group($data), $data);

        return (new BranchResource($branch->load('group')))->response()->setStatusCode(201);
    }

    /** branch_group_id: absent = unchanged, a Group of the same Clan = assign/move, null = ungroup. */
    public function updateBranch(Request $request, Branch $branch, ManageClanStructureAction $action): BranchResource
    {
        $data = $request->validate([
            'code' => ['prohibited'],
            'clan_id' => ['prohibited'],
            'branch_group_id' => $this->groupRules($branch->clan_id),
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ], $this->messages());

        if (array_key_exists('branch_group_id', $data)) {
            $data['branch_group'] = $this->group($data);
        }

        return new BranchResource($action->updateBranch($branch, $data)->load('group'));
    }

    /** @return list<mixed> */
    private function groupRules(int $clanId): array
    {
        // Only a Group of the same Clan exists for this rule: cross-Clan → 422.
        return ['sometimes', 'nullable', 'uuid', Rule::exists('branch_groups', 'uuid')->where('clan_id', $clanId)];
    }

    /** @param array<string, mixed> $data */
    private function group(array $data): ?BranchGroup
    {
        $uuid = $data['branch_group_id'] ?? null;

        return $uuid === null ? null : BranchGroup::where('uuid', $uuid)->firstOrFail();
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
            'clan_id.prohibited' => 'لا يمكن النقل إلى عشيرة / عائلة أخرى.',
            'branch_group_id.uuid' => 'مجموعة الفروع غير صالحة.',
            'branch_group_id.exists' => 'مجموعة الفروع لا تتبع العشيرة / العائلة نفسها.',
        ];
    }
}
