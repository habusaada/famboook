<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Exceptions\CivilLookupException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Middleware\EnsureRegistryDiscoveryAvailable;
use App\Http\Requests\Api\V1\Family\FamilyRegistryChildrenRequest;
use App\Http\Resources\FamilyRegistryChildResource;
use App\Support\FamilyPortal\RegistryAdmission\FamilyRegistryAdmissionAudit;
use App\Support\FamilyPortal\RegistryAdmission\GatedRegistryChildAdmissibility;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionDeclarations;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildAdmissibility;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildSource;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildSourceIntegrityException;
use App\Support\FamilyPortal\RegistryAdmission\RegistryReleasePinVerifier;
use App\Support\FamilyPortal\RegistryAdmission\SuggestedChildrenDiscovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GET /api/v1/family/household/registry-children (FB-FAMILY-ADD-01 ADD-WP-06B-3;
 * ADR §7): the signed-in head's suggested children, read-only. Behind
 * family.context and family.registry-discovery (flag, permission, limits).
 *
 * - The source is whatever RegistryChildSource the container holds. NONE is bound
 *   by the application: until an approved adapter exists, every allowed request is
 *   503 REGISTRY_UNAVAILABLE without reading anything. Tests bind synthetic sources.
 * - RR-02: `data.declaration` is the approved declaration or NULL, and an ELIGIBLE child
 *   carries a `ref` only when a declaration is approved and a pin verifier is bound.
 * - The page is recorded (DISCOVERY_LISTED) BEFORE it is returned; if the record
 *   cannot be written, nothing is returned.
 * - More matches than the cap are 409 RESULT_SET_TOO_BROAD (ADD-WP-06B-3R): no
 *   children, no count, no reason. Recorded first, like a listing.
 * - A source that answers inconsistently (release or counts) is 503 and shows nothing.
 */
class FamilyRegistryChildrenController extends Controller
{
    public const UNAVAILABLE = 'REGISTRY_UNAVAILABLE';

    public const TOO_BROAD = 'RESULT_SET_TOO_BROAD';

    private const TOO_BROAD_MESSAGE = 'تعذّر عرض الأبناء المقترحين لهذه الأسرة. يرجى مراجعة المكتب.';

    private const UNAVAILABLE_MESSAGE = 'تعذّر عرض الأبناء المقترحين الآن، حاول لاحقًا.';

    public function index(FamilyRegistryChildrenRequest $request): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);
        if (! app()->bound(RegistryChildSource::class)) {
            return $this->unavailable();
        }

        try {
            // RR-02: references only when an admission could succeed at all.
            $declaration = RegistryAdmissionDeclarations::active();
            $issueRefs = $declaration !== null && app()->bound(RegistryReleasePinVerifier::class);
            $admissibility = app()->bound(RegistryChildAdmissibility::class) ? app(RegistryChildAdmissibility::class) : new GatedRegistryChildAdmissibility;
            $page = (new SuggestedChildrenDiscovery(app(RegistryChildSource::class), $admissibility))
                ->discover($context, $request->page(), issueRefs: $issueRefs);
        } catch (CivilLookupException) {
            return $this->unavailable();
        } catch (RegistryChildSourceIntegrityException $e) {
            Log::warning('Registry discovery source answered inconsistently.', ['exception' => $e::class]);

            return $this->unavailable();
        }

        try {
            FamilyRegistryAdmissionAudit::discoveryListed($context->user, $context->family, $page->releaseId, count($page->children), $page->tooBroad, $request->ip());
        } catch (Throwable $e) {
            Log::error('Registry discovery audit failed.', ['exception' => $e::class]);

            return $this->unavailable();
        }
        if ($page->tooBroad) {
            return EnsureRegistryDiscoveryAvailable::refuse(self::TOO_BROAD, self::TOO_BROAD_MESSAGE, 409);
        }

        return response()->json([
            'data' => [
                'children' => FamilyRegistryChildResource::collection($page->children)->resolve($request),
                'meta' => ['page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total],
                // RR-02: the APPROVED declaration to show, {version, text}, or NULL (none
                // approved: the client keeps its submission disabled).
                'declaration' => $declaration?->toArray(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    private function unavailable(): JsonResponse
    {
        return EnsureRegistryDiscoveryAvailable::refuse(self::UNAVAILABLE, self::UNAVAILABLE_MESSAGE, 503);
    }
}
