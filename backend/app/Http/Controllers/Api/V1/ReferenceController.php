<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssessmentDomainResource;
use App\Http\Resources\DisabilityTypeResource;
use App\Http\Resources\NeedCategoryResource;
use App\Http\Resources\RelationshipTypeResource;
use App\Models\AssessmentDomain;
use App\Models\DisabilityType;
use App\Models\NeedCategory;
use App\Models\RelationshipType;
use Illuminate\Http\Request;
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

    public function disabilityTypes(): AnonymousResourceCollection
    {
        $types = DisabilityType::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return DisabilityTypeResource::collection($types);
    }

    /**
     * Active domains only — the values selectable for new results. Allowed
     * with reference-data.view or assessment.view (docs/06 §47): roles that
     * assess families (e.g. SOCIAL_WORKER) do not hold reference-data.view.
     */
    public function assessmentDomains(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->canAny(['reference-data.view', 'assessment.view']), 403);

        $domains = AssessmentDomain::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return AssessmentDomainResource::collection($domains);
    }

    /**
     * Active categories only — the values selectable for new Needs.
     * Allowed with reference-data.view or need.view (docs/06 §49).
     */
    public function needCategories(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->canAny(['reference-data.view', 'need.view']), 403);

        $categories = NeedCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return NeedCategoryResource::collection($categories);
    }
}
