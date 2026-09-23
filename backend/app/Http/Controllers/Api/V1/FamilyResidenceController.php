<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\UpdateFamilyResidenceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateFamilyResidenceRequest;
use App\Http\Resources\FamilyDetailResource;
use App\Models\Family;

class FamilyResidenceController extends Controller
{
    public function update(
        UpdateFamilyResidenceRequest $request,
        Family $family,
        UpdateFamilyResidenceAction $action
    ): FamilyDetailResource {
        $family = $action->handle($family, $request->validated(), $request->user()?->id);

        return new FamilyDetailResource($family);
    }
}
