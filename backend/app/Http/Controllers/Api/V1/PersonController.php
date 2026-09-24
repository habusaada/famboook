<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\UpdatePersonAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdatePersonRequest;
use App\Http\Resources\PersonResource;
use App\Models\Person;

class PersonController extends Controller
{
    private const MEMBERSHIP_RELATIONS = ['activeMembership.family', 'activeMembership.relationshipType'];

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
