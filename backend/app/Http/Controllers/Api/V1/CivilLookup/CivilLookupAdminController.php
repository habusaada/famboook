<?php

namespace App\Http\Controllers\Api\V1\CivilLookup;

use App\Actions\CivilLookup\SetCivilLookupUserAccessAction;
use App\Actions\CivilLookup\UpdateCivilLookupRolePolicyAction;
use App\Http\Controllers\Controller;
use App\Models\CivilLookupPolicyChange;
use App\Models\CivilLookupUserAccess;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\CivilLookup\CivilLookupContext;
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
 *
 * FB-CIVIL-02B (ADR 02B §B-4): a family-side account has no email, so the
 * target of a per-user change is an email (Staff) or a person code (the
 * account currently linked to that Person). GRANTED names its sides; the
 * usage view says which sides may be granted right now.
 */
class CivilLookupAdminController extends Controller
{
    private const NO_STORE = 'no-store, private';

    /** Policies with today's usage (civil-lookup.usage.view). */
    public function usage(): JsonResponse
    {
        return $this->json(CivilLookupUsage::today() + ['lookup_enabled' => CivilLookupGate::allows(),
            'reverse_links_enabled' => CivilLookupGate::reverseLinksEnabled(),
            'grantable_sides' => array_values(array_filter(CivilLookupUserAccess::SIDES, fn (string $side) => CivilLookupGate::sideSwitchOpen($side))),
            'side_lookup_enabled' => [
                CivilLookupContext::FAMILY => CivilLookupGate::allowsContext(CivilLookupContext::FAMILY),
                CivilLookupContext::COORDINATOR => CivilLookupGate::allowsContext(CivilLookupContext::COORDINATOR),
            ]]);
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
                'old_sides' => CivilLookupUserAccess::sideList($c->old_sides), 'new_sides' => CivilLookupUserAccess::sideList($c->new_sides),
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
                'sides' => CivilLookupUserAccess::sideList($a->sides),
                'changed_by' => $a->changedBy?->name,
                'changed_at' => $a->changed_at?->toIso8601String(),
            ])->all());
    }

    public function setUserAccess(Request $request, SetCivilLookupUserAccessAction $action): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required_without:person_code', 'prohibits:person_code', 'nullable', 'string', 'email', 'max:255'],
            'person_code' => ['required_without:email', 'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'access' => ['required', 'string', Rule::in([CivilLookupUserAccess::REVOKED, CivilLookupUserAccess::GRANTED, SetCivilLookupUserAccessAction::DEFAULT])],
            'sides' => ['sometimes', 'array', 'max:2'],
            'sides.*' => ['string', Rule::in(CivilLookupUserAccess::SIDES)],
        ]);
        $target = isset($data['email'])
            ? (User::query()->where('email', $data['email'])->first()
                ?? throw ValidationException::withMessages(['email' => 'لا يوجد مستخدم بهذا البريد.']))
            : $this->linkedUser((string) $data['person_code']);
        $row = $action->handle($request->user(), $target, $data['access'], array_values($data['sides'] ?? []));

        return $this->json(['user_id' => $target->id, 'access' => $row?->access ?? SetCivilLookupUserAccessAction::DEFAULT,
            'sides' => CivilLookupUserAccess::sideList($row?->sides)]);
    }

    /** The account currently linked to the Person with this code (a family-side account has no email). */
    private function linkedUser(string $personCode): User
    {
        $person = Person::query()->where('person_code', $personCode)->first();
        $link = $person === null ? null : UserPersonLink::query()->where('person_id', $person->id)->current()->first();

        return ($link === null ? null : User::query()->find($link->user_id))
            ?? throw ValidationException::withMessages(['person_code' => 'لا يوجد حساب مرتبط بهذا الشخص.']);
    }

    /** @param  array<mixed>  $data */
    private function json(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', self::NO_STORE);
    }
}
