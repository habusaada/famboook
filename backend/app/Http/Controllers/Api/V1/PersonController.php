<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdatePersonRequest;
use App\Http\Resources\PersonResource;
use App\Models\Person;

class PersonController extends Controller
{
    public function show(Person $person): PersonResource
    {
        $person->load('activeMembership.family');

        return new PersonResource($person);
    }

    public function update(UpdatePersonRequest $request, Person $person): PersonResource
    {
        $person->fill($request->validated());
        $person->updated_by = $request->user()?->id;
        $person->save();

        return new PersonResource($person->fresh('activeMembership.family'));
    }
}
