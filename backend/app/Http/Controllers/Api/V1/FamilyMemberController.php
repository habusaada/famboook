<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AddFamilyMemberAction;
use App\Actions\CorrectMembershipRelationshipAction;
use App\Actions\EndFamilyMembershipAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddFamilyMemberRequest;
use App\Http\Requests\Api\V1\CorrectMembershipRelationshipRequest;
use App\Http\Requests\Api\V1\EndFamilyMembershipRequest;
use App\Http\Resources\FamilyMemberResource;
use App\Models\Family;
use App\Models\Person;
use Illuminate\Http\JsonResponse;

class FamilyMemberController extends Controller
{
    public function store(
        AddFamilyMemberRequest $request,
        Family $family,
        AddFamilyMemberAction $action
    ): JsonResponse {
        $membership = $action->handle($family, $request->validated(), $request->user()?->id);

        return (new FamilyMemberResource($membership))
            ->additional(['message' => 'تمت إضافة الفرد بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    public function updateRelationship(
        CorrectMembershipRelationshipRequest $request,
        Family $family,
        Person $person,
        CorrectMembershipRelationshipAction $action
    ): JsonResponse {
        $membership = $action->handle($family, $person, (int) $request->validated('relationship_type_id'), $request->user()?->id);

        return (new FamilyMemberResource($membership))
            ->additional(['message' => 'تم تصحيح صلة القرابة'])
            ->response();
    }

    public function end(
        EndFamilyMembershipRequest $request,
        Family $family,
        Person $person,
        EndFamilyMembershipAction $action
    ): JsonResponse {
        $action->handle($family, $person, $request->validated('reason'), $request->user()?->id);

        // The Person stays in the registry; only the membership ended.
        return response()->json([
            'data' => ['person_code' => $person->person_code, 'family_code' => $family->family_code],
            'message' => 'تم إنهاء العضوية. الشخص ما زال مسجلًا في النظام.',
        ]);
    }
}
