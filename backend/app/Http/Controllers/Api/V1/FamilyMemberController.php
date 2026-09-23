<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AddFamilyMemberAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddFamilyMemberRequest;
use App\Http\Resources\FamilyMemberResource;
use App\Models\Family;
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
}
