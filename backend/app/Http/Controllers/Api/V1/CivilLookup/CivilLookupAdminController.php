<?php

namespace App\Http\Controllers\Api\V1\CivilLookup;

use App\Actions\CivilLookup\SetCivilLookupUserAccessAction;
use App\Actions\CivilLookup\UpdateCivilLookupRolePolicyAction;
use App\Http\Controllers\Controller;
use App\Models\CivilLookupPolicyChange;
use App\Models\CivilLookupUserAccess;
use App\Models\User;
use App\Support\CivilLookup\CivilLookupGate;
use App\Support\CivilLookup\CivilLookupUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Civil Registry Lookup administration (FB-CIVIL-02A, ADR §L-3, §L-6): role
 * policies, today's usage, per-user access and the change history. Thin;
 * changes go through the actions, which re-check civil-lookup.manage.
 */
class CivilLookupAdminController extends Controller
{
    private const NO_STORE = 'no-store, private';

    /** Policies with today's usage (civil-lookup.usage.view). */
    public function usage(): JsonResponse
    {
        return $this->json(CivilLookupUsage::today() + ['lookup_enabled' => CivilLookupGate::allows(),
            'reverse_links_enabled' => CivilLookupGate::reverseLinksEnabled()]);
    }

    public function updatePolicy(Request $request, string $role, UpdateCivilLookupRolePolicyAction $action): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'daily_limit' => ['present', 'nullable', 'integer', 'min:1', 'max:'.UpdateCivilLookupRolePolicyAction::MAX_LIMIT],
        ]);
        $policy = $action->handle($request->user(), $role, (bool) $data['enabled'], $data['daily_limit'] === null ? null : (int) $data['daily_limit']);

        return $this->json(['role' => $policy->role_name, 'enabled' => $policy->enabled, 'daily_limit' => $policy->daily_limit]);
    }

    public function changes(Request $request): JsonResponse
    {
        $page = CivilLookupPolicyChange::query()->with(['changedBy:id,name', 'user:id,name,email'])->orderByDesc('id')
            ->paginate(25, ['*'], 'page', max(1, (int) $request->query('page', 1)));

        return response()->json([
            'data' => $page->getCollection()->map(fn (CivilLookupPolicyChange $c) => [
                'id' => $c->id,
                'subject_type' => $c->subject_type,
                'role' => $c->role_name,
                'user' => $c->user === null ? null : ['id' => $c->user->id, 'name' => $c->user->name],
                'old_enabled' => $c->old_enabled, 'new_enabled' => $c->new_enabled,
                'old_daily_limit' => $c->old_daily_limit, 'new_daily_limit' => $c->new_daily_limit,
                'old_access' => $c->old_access, 'new_access' => $c->new_access,
                'changed_by' => $c->changedBy?->name,
                'changed_at' => $c->changed_at?->toIso8601String(),
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ])->header('Cache-Control', self::NO_STORE);
    }

    public function userAccess(): JsonResponse
    {
        return $this->json(CivilLookupUserAccess::query()->with(['user:id,name,email', 'changedBy:id,name'])->orderByDesc('changed_at')->limit(200)->get()
            ->map(fn (CivilLookupUserAccess $a) => [
                'user' => ['id' => $a->user?->id, 'name' => $a->user?->name, 'email' => $a->user?->email],
                'access' => $a->access,
                'changed_by' => $a->changedBy?->name,
                'changed_at' => $a->changed_at?->toIso8601String(),
            ])->all());
    }

    public function setUserAccess(Request $request, SetCivilLookupUserAccessAction $action): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'access' => ['required', 'string', Rule::in([CivilLookupUserAccess::REVOKED, CivilLookupUserAccess::GRANTED, SetCivilLookupUserAccessAction::DEFAULT])],
        ]);
        $target = User::query()->where('email', $data['email'])->first()
            ?? throw ValidationException::withMessages(['email' => 'لا يوجد مستخدم بهذا البريد.']);
        $row = $action->handle($request->user(), $target, $data['access']);

        return $this->json(['user_id' => $target->id, 'access' => $row?->access ?? SetCivilLookupUserAccessAction::DEFAULT]);
    }

    /** @param  array<mixed>  $data */
    private function json(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', self::NO_STORE);
    }
}
