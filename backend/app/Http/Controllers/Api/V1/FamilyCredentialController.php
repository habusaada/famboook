<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\IssueFamilyCredentialAction;
use App\Actions\ReissueFamilyCredentialAction;
use App\Actions\RevokeFamilyCredentialAction;
use App\Enums\CredentialIssueChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RevokeFamilyCredentialRequest;
use App\Http\Resources\FamilyCredentialResource;
use App\Models\DigitalCredential;
use App\Models\Family;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff management of a Family's Digital Family Card (docs/06 §22b, docs/11
 * FP-ADR-070): view, issue, revoke, reissue — each behind its own
 * family-card.* permission and the staff.side boundary, each through a
 * Domain Action. Responses never contain a token or a QR: Staff reprint
 * arrives with the PDF (PWA-8.3).
 */
class FamilyCredentialController extends Controller
{
    public function show(Family $family): JsonResponse
    {
        return $this->state($family);
    }

    public function issue(Request $request, Family $family, IssueFamilyCredentialAction $action): JsonResponse
    {
        $action->handle($family, CredentialIssueChannel::STAFF, $request->user()->id);

        return $this->state($family, 201);
    }

    public function revoke(RevokeFamilyCredentialRequest $request, Family $family, RevokeFamilyCredentialAction $action): JsonResponse
    {
        $action->handle($family, $request->reason(), $request->user()->id);

        return $this->state($family);
    }

    public function reissue(Request $request, Family $family, ReissueFamilyCredentialAction $action): JsonResponse
    {
        $action->handle($family, $request->user()->id);

        return $this->state($family);
    }

    private function state(Family $family, int $status = 200): JsonResponse
    {
        $history = $family->digitalCredentials()
            ->with(['issuer:id,name', 'revoker:id,name'])
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get();

        $active = $history->first(fn (DigitalCredential $credential) => $credential->isActive());

        return response()->json(['data' => [
            'active' => $active === null ? null : new FamilyCredentialResource($active),
            'history' => FamilyCredentialResource::collection($history),
        ]], $status)->header('Cache-Control', 'no-store, private');
    }
}
