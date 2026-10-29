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
use App\Support\AccountSide;
use App\Support\CivilLookup\CivilLookupAccess;
use App\Support\CivilLookup\CivilLookupContext;
use App\Support\CivilLookup\CivilLookupGate;
use App\Support\CivilLookup\CivilLookupUsage;
use App\Support\StaffRoles;
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
 *
 * FB-CIVIL-02D (the access page): one account's current state, a Staff
 * account search, change-history filters and a stale-state check on the
 * per-user change. Nothing here changes who may look up.
 */
class CivilLookupAdminController extends Controller
{
    private const NO_STORE = 'no-store, private';

    private const ACCESS_LIST_LIMIT = 200;

    private const ACCESS_VALUES = [CivilLookupUserAccess::REVOKED, CivilLookupUserAccess::GRANTED, SetCivilLookupUserAccessAction::DEFAULT];

    /** A per-user target: a Staff email, or the person code of a family-side account (it has no email). */
    private const TARGET_RULES = [
        'email' => ['required_without:person_code', 'prohibits:person_code', 'nullable', 'string', 'email', 'max:255'],
        'person_code' => ['required_without:email', 'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
    ];

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

    /** FB-CIVIL-02D: optionally only role-policy (ROLE) or per-user (USER) changes, or one user's. Read-only. */
    public function changes(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'subject' => ['sometimes', 'string', Rule::in([CivilLookupPolicyChange::ROLE, CivilLookupPolicyChange::USER])],
            'user_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $page = CivilLookupPolicyChange::query()->with(['changedBy:id,name', 'user:id,name,email'])
            ->when(isset($filter['subject']), fn ($q) => $q->where('subject_type', $filter['subject']))
            ->when(isset($filter['user_id']), fn ($q) => $q->where('user_id', (int) $filter['user_id']))
            ->orderByDesc('id')
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

    /**
     * The latest 200 individual overrides — a recent list, never a directory:
     * a user missing from it may still have one (resolveUserAccess answers
     * for one account). FB-CIVIL-02D adds the account side and, for a
     * family-side account, the code of its currently linked Person.
     */
    public function userAccess(): JsonResponse
    {
        $rows = CivilLookupUserAccess::query()->with(['user', 'changedBy:id,name'])->orderByDesc('changed_at')->limit(self::ACCESS_LIST_LIMIT)->get();
        $personCodes = UserPersonLink::query()->whereIn('user_id', $rows->pluck('user_id'))->current()->with('person:id,person_code')->get()
            ->mapWithKeys(fn (UserPersonLink $link) => [$link->user_id => $link->person?->person_code]);

        return response()->json([
            'data' => $rows->map(fn (CivilLookupUserAccess $a) => [
                'user' => ['id' => $a->user?->id, 'name' => $a->user?->name, 'email' => $a->user?->email],
                'account_side' => $a->user === null ? null : AccountSide::of($a->user),
                'person_code' => $personCodes[$a->user_id] ?? null,
                'access' => $a->access,
                'sides' => CivilLookupUserAccess::sideList($a->sides),
                'changed_by' => $a->changedBy?->name,
                'changed_at' => $a->changed_at?->toIso8601String(),
            ])->all(),
            'meta' => ['limit' => self::ACCESS_LIST_LIMIT, 'total' => CivilLookupUserAccess::query()->count()],
        ])->header('Cache-Control', self::NO_STORE);
    }

    /**
     * FB-CIVIL-02D: the current access of ONE account (Staff by email, a
     * family-side account by the code of its linked Person), DEFAULT when it
     * has no override. POST so the identifier never appears in a URL. The
     * override is shown apart from the effective eligibility, which also
     * depends on the gates, the permissions and the role policies.
     */
    public function resolveUserAccess(Request $request): JsonResponse
    {
        $target = $this->target($request->validate(self::TARGET_RULES));

        return $this->json($this->accountState($target));
    }

    /** FB-CIVIL-02D: Staff-side accounts by name or email, 20 per page — the identity and roles only. */
    public function staffAccounts(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        // LIKE wildcards in the term are literal. The escape character is '!' — a
        // backslash literal confuses the PostgreSQL PDO placeholder parser.
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($data['q']))).'%';
        $page = User::query()->whereHas('roles', fn ($q) => $q->whereIn('roles.name', StaffRoles::ALL))
            ->where(fn ($q) => $q->whereRaw("lower(users.name) like ? escape '!'", [$term])->orWhereRaw("lower(users.email) like ? escape '!'", [$term]))
            ->with('roles:id,name')->orderBy('users.name')->orderBy('users.id')
            ->paginate(20, ['id', 'name', 'email', 'is_active'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_active' => (bool) $u->is_active,
                'account_side' => AccountSide::of($u), 'roles' => $u->roles->pluck('name')->values()->all(),
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ])->header('Cache-Control', self::NO_STORE);
    }

    /**
     * FB-CIVIL-02D: `expected_access` / `expected_sides` (optional) are the
     * state the editor loaded; a different current state answers 409
     * ACCESS_CHANGED and changes nothing.
     */
    public function setUserAccess(Request $request, SetCivilLookupUserAccessAction $action): JsonResponse
    {
        $data = $request->validate(self::TARGET_RULES + [
            'access' => ['required', 'string', Rule::in(self::ACCESS_VALUES)],
            'sides' => ['sometimes', 'array', 'max:2'],
            'sides.*' => ['string', Rule::in(CivilLookupUserAccess::SIDES)],
            'expected_access' => ['sometimes', 'string', Rule::in(self::ACCESS_VALUES)],
            'expected_sides' => ['present_with:expected_access', 'array', 'max:2'],
            'expected_sides.*' => ['string', Rule::in(CivilLookupUserAccess::SIDES)],
        ]);
        $target = $this->target($data);
        $expected = isset($data['expected_access'])
            ? ['access' => $data['expected_access'], 'sides' => array_values($data['expected_sides'] ?? [])]
            : null;
        $row = $action->handle($request->user(), $target, $data['access'], array_values($data['sides'] ?? []), $expected);

        return $this->json(['user_id' => $target->id, 'access' => $row?->access ?? SetCivilLookupUserAccessAction::DEFAULT,
            'sides' => CivilLookupUserAccess::sideList($row?->sides)]);
    }

    /** @param  array{email?: ?string, person_code?: ?string}  $data */
    private function target(array $data): User
    {
        return isset($data['email'])
            ? (User::query()->where('email', $data['email'])->first()
                ?? throw ValidationException::withMessages(['email' => 'لا يوجد مستخدم بهذا البريد.']))
            : $this->linkedUser((string) $data['person_code']);
    }

    /**
     * What the access editor needs about one account: its identity, the
     * individual override (or DEFAULT) with who set it, the sides that may be
     * granted to it right now, its role policy and its effective eligibility
     * per context (CivilLookupAccess — read only, nothing is counted).
     *
     * @return array<string, mixed>
     */
    private function accountState(User $user): array
    {
        $side = AccountSide::of($user);
        $access = CivilLookupUserAccess::query()->with('changedBy:id,name')->find($user->id);
        $link = UserPersonLink::query()->where('user_id', $user->id)->current()->with('person:id,person_code,full_name')->first();
        $lastChange = CivilLookupPolicyChange::query()->with('changedBy:id,name')
            ->where('subject_type', CivilLookupPolicyChange::USER)->where('user_id', $user->id)->orderByDesc('id')->first();
        $policy = CivilLookupAccess::policyFor($user);
        $coordinator = $user->hasRole(AccountSide::COORDINATOR);
        $contexts = match ($side) {
            AccountSide::STAFF => [CivilLookupContext::STAFF],
            AccountSide::FAMILY => $coordinator ? [CivilLookupContext::FAMILY, CivilLookupContext::COORDINATOR] : [CivilLookupContext::FAMILY],
            default => [],
        };

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'is_active' => (bool) $user->is_active,
                'account_side' => $side, 'roles' => $user->getRoleNames()->values()->all()],
            'person' => $link?->person === null ? null
                : ['person_code' => $link->person->person_code, 'full_name' => $link->person->full_name, 'link_status' => $link->status->value],
            'access' => $access?->access ?? SetCivilLookupUserAccessAction::DEFAULT,
            'sides' => CivilLookupUserAccess::sideList($access?->sides),
            'changed_by' => $access?->changedBy?->name,
            'changed_at' => $access?->changed_at?->toIso8601String(),
            'last_change' => $lastChange === null ? null
                : ['changed_by' => $lastChange->changedBy?->name, 'changed_at' => $lastChange->changed_at?->toIso8601String()],
            'grantable_sides' => $side !== AccountSide::FAMILY ? [] : array_values(array_filter(CivilLookupUserAccess::SIDES,
                fn (string $s) => CivilLookupGate::sideSwitchOpen($s) && ($s !== AccountSide::COORDINATOR || $coordinator))),
            'role_policy' => $policy === null ? null : ['role' => $policy->role, 'daily_limit' => $policy->dailyLimit],
            'effective' => collect($contexts)->mapWithKeys(function (string $context) use ($user) {
                $result = CivilLookupAccess::evaluate($user, $context);

                return [$context => ['allowed' => $result['allowed'], 'reason' => $result['reason']]];
            })->all(),
        ];
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
