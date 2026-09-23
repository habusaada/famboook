<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RelationshipTypeResource;
use App\Models\RelationshipType;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReferenceController extends Controller
{
    public function relationshipTypes(): AnonymousResourceCollection
    {
        $types = RelationshipType::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return RelationshipTypeResource::collection($types);
    }
}
