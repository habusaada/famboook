<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\UpdatePersonAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\NationalIdCheckRequest;
use App\Http\Requests\Api\V1\UpdatePersonRequest;
use App\Http\Resources\PersonResource;
use App\Http\Resources\PersonSummaryResource;
use App\Models\Person;
use App\Support\NationalIdGuard;
use App\Support\RegistrySearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PersonController extends Controller
{
    private const MEMBERSHIP_RELATIONS = ['activeMembership.family', 'activeMembership.relationshipType'];

    public const MAX_PER_PAGE = 100;

    /**
     * People registry (docs/03 §93a): non-deleted Persons, searched by Person
     * code or name (plain "contains", case-insensitive), paginated.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:'.RegistrySearch::MAX_TERM],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);
        $term = trim((string) ($validated['search'] ?? ''));

        $people = Person::query()
            ->with(['activeMembership.family.branch:id,name', 'activeMembership.relationshipType'])
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => RegistrySearch::contains($w, 'persons.person_code', $term)
                ->orWhere(fn ($n) => RegistrySearch::contains($n, 'persons.full_name', $term))))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return PersonSummaryResource::collection($people);
    }

    /**
     * Exact National ID duplicate pre-check for data entry (AUTH-ADR-058).
     * Not a search: exact match only, POST so the value never appears in a
     * URL, rate limited, and the response never contains a National ID.
     */
    public function nationalIdCheck(NationalIdCheckRequest $request): JsonResponse
    {
        $matches = NationalIdGuard::matches($request->validated('national_id'));

        return response()->json(['data' => [
            'exists' => $matches->isNotEmpty(),
            'matches' => NationalIdGuard::describe($matches, $request),
        ]]);
    }

    public function show(Person $person): PersonResource
    {
        $person->load(self::MEMBERSHIP_RELATIONS);

        return new PersonResource($person);
    }

    public function update(UpdatePersonRequest $request, Person $person, UpdatePersonAction $action): PersonResource
    {
        $person = $action->handle($person, $request->validated(), $request->user()?->id);

        return new PersonResource($person->fresh(self::MEMBERSHIP_RELATIONS));
    }
}
