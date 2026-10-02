<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\GrantPersonMobileTrustAction;
use App\Actions\RevokePersonMobileTrustAction;
use App\Enums\MobileTrustStatus;
use App\Exceptions\MobileTrustException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GrantMobileTrustRequest;
use App\Http\Requests\Api\V1\RevokeMobileTrustRequest;
use App\Http\Resources\MobileTrustResource;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Support\FamilyAuth\CurrentTrustedMobile;
use App\Support\FamilyAuth\FamilyMobile;
use Illuminate\Http\JsonResponse;

/**
 * Staff API for a Person's mobile trust (docs/06 §22b): view the state,
 * grant the current mobile, revoke the trusted one. Staff-side only (the
 * `staff.side` boundary) and permission-gated per route; the Domain Actions
 * re-check both. No family-side or coordinator endpoint, and no UI.
 *
 * The trust state itself is never decided here: it comes from
 * CurrentTrustedMobile.
 */
class PersonMobileTrustController extends Controller
{
    public function __construct(private readonly CurrentTrustedMobile $trusted) {}

    public function show(Person $person): JsonResponse
    {
        return $this->state($person);
    }

    public function grant(GrantMobileTrustRequest $request, Person $person, GrantPersonMobileTrustAction $action): JsonResponse
    {
        $action->handle($request->user(), $person, $request->verificationMethod());

        return $this->state($person->fresh(), 201);
    }

    public function revoke(RevokeMobileTrustRequest $request, Person $person, RevokePersonMobileTrustAction $action): JsonResponse
    {
        $trust = PersonMobileTrust::query()
            ->where('person_id', $person->id)
            ->where('status', MobileTrustStatus::TRUSTED->value)
            ->first();
        if ($trust === null) {
            throw new MobileTrustException(MobileTrustException::NOT_TRUSTED);
        }

        $action->handle($request->user(), $trust, $request->reason());

        return $this->state($person->fresh());
    }

    private function state(Person $person, int $status = 200): JsonResponse
    {
        $mobile = FamilyMobile::normalize($person->mobile);
        $history = $person->mobileTrusts()
            ->where('status', '!=', MobileTrustStatus::PENDING_VERIFICATION->value)
            ->with(['verifier:id,name', 'revoker:id,name'])
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => [
            'person_code' => $person->person_code,
            // NO_MOBILE · UNVERIFIED · TRUSTED · STALE · REVOKED
            'state' => $this->trusted->for($person)->state(),
            'mobile_masked' => $mobile === null ? null : MobileTrustResource::MASK.substr($mobile, -2),
            'history' => MobileTrustResource::collection($history),
        ]], $status);
    }
}
