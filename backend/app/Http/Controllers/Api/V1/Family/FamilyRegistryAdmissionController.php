<?php

namespace App\Http\Controllers\Api\V1\Family;

use App\Actions\FamilyPortal\AdmitRegistryChildAction;
use App\Exceptions\RegistryAdmissionReferenceException as RefException;
use App\Exceptions\RegistryAdmissionRefusedException;
use App\Exceptions\RegistryAdmissionUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Middleware\EnsureRegistryDiscoveryAvailable;
use App\Http\Requests\Api\V1\Family\FamilyRegistryAdmissionRequest;
use App\Support\FamilyPortal\RegistryAdmission\GatedRegistryChildAdmissibility;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildAdmissibility;
use App\Support\FamilyPortal\RegistryAdmission\RegistryChildSource;
use App\Support\FamilyPortal\RegistryAdmission\RegistryReleasePinVerifier;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/family/household/registry-children/admissions (FB-FAMILY-ADD-01
 * ADD-WP-07C; ADR §7 Admission, §10b): admits ONE suggested child of the signed-in
 * household head through AdmitRegistryChildAction. Behind family.context and
 * family.registry-admission (flag, permission, limits). Thin: the action decides.
 *
 * - The registry source and the release pin verifier are whatever the container holds.
 *   The application binds NEITHER: until an approved package binds them (with DBA-1 …
 *   DBA-4 applied), every allowed request is 503 REGISTRY_UNAVAILABLE and nothing is read.
 *   Tests bind synthetic ones.
 * - Eligibility is GatedRegistryChildAdmissibility unless the container holds another
 *   one, and the action refuses any other outside the test runner.
 * - 201 {data: {member_ref, replayed: false}} for an admission, 200 with replayed: true
 *   for a replay; otherwise one public code ({message, code}). Every answer is
 *   Cache-Control: no-store, private. Nothing is logged here.
 */
class FamilyRegistryAdmissionController extends Controller
{
    public const UNAVAILABLE = 'REGISTRY_UNAVAILABLE';

    private const UNAVAILABLE_MESSAGE = 'تعذّر إتمام الإضافة حاليًا، حاول لاحقًا.';

    private const REFERENCE_MESSAGES = [
        RefException::REFERENCE_INVALID => 'انتهت صلاحية الاختيار. أعد عرض الأبناء والبنات.',
        RefException::RELEASE_CHANGED => 'تغيّر السجل المدني. أعد عرض الأبناء والبنات.',
    ];

    public function store(FamilyRegistryAdmissionRequest $request): JsonResponse
    {
        $context = EnsureFamilyContext::context($request);
        if (! app()->bound(RegistryChildSource::class) || ! app()->bound(RegistryReleasePinVerifier::class)) {
            return EnsureRegistryDiscoveryAvailable::refuse(self::UNAVAILABLE, self::UNAVAILABLE_MESSAGE, 503);
        }
        $action = new AdmitRegistryChildAction(
            app(RegistryChildSource::class),
            app(RegistryReleasePinVerifier::class),
            app()->bound(RegistryChildAdmissibility::class) ? app(RegistryChildAdmissibility::class) : new GatedRegistryChildAdmissibility,
        );

        try {
            $outcome = $action->handle($context, $request->admission());
        } catch (RegistryAdmissionRefusedException|RegistryAdmissionUnavailableException $e) {
            return $e->render();
        } catch (RefException $e) {
            return EnsureRegistryDiscoveryAvailable::refuse($e->reason, self::REFERENCE_MESSAGES[$e->reason], $e->status());
        }

        return response()->json(['data' => $outcome->toArray()], $outcome->replayed ? 200 : 201)
            ->header('Cache-Control', 'no-store, private');
    }
}
