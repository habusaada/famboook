<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordHouseholdDeclarationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecordHouseholdDeclarationRequest;
use App\Http\Resources\FamilyDetailResource;
use App\Models\Family;

class FamilyHouseholdDeclarationController extends Controller
{
    public function store(
        RecordHouseholdDeclarationRequest $request,
        Family $family,
        RecordHouseholdDeclarationAction $action
    ): FamilyDetailResource {
        $action->handle($family, $request->declaration(), $request->expectedCurrentId(), $request->user()?->id);

        return new FamilyDetailResource($family->fresh([
            'memberships.person',
            'memberships.relationshipType',
            'currentResidence',
            'currentHouseholdDeclaration',
            'clan',
            'branch.group.branches',
        ]));
    }
}
