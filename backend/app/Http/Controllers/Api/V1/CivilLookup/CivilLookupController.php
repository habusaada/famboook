<?php

namespace App\Http\Controllers\Api\V1\CivilLookup;

use App\Actions\CivilLookup\LookupCivilRegistryAction;
use App\Exceptions\CivilLookupException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CivilLookup\NameLookupRequest;
use App\Http\Requests\CivilLookup\NationalIdLookupRequest;
use App\Http\Requests\CivilLookup\PartsLookupRequest;
use App\Http\Requests\CivilLookup\ReferenceRequest;
use App\Support\CivilLookup\CivilLookupAccess;
use App\Support\CivilLookup\CivilLookupContext;
use App\Support\CivilLookup\CivilLookupGate;
use App\Support\CivilLookup\CivilLookupIdentifierDisclosure;
use App\Support\CivilLookup\CivilLookupQuota;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Civil Registry Lookup (FB-CIVIL-02A, docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md).
 * Thin: validation in the requests, every decision in LookupCivilRegistryAction.
 * All criteria and references travel in POST bodies; every response is
 * no-store, private (§L-12).
 *
 * FB-CIVIL-02B (ADR 02B §B-1): the same controller serves the Staff, Family
 * Portal and Coordinator Space mounts; the context comes from the route
 * (civil-lookup.context), never from the request.
 */
class CivilLookupController extends Controller
{
    private const NO_STORE = 'no-store, private';

    public function __construct(private readonly LookupCivilRegistryAction $lookup) {}

    /** The caller's lookup status and quota (works while the lookup is closed: it then says so). */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $context = CivilLookupContext::of($request);

        return $this->json(['context' => $context, 'quota' => CivilLookupQuota::status($user, $context), 'capabilities' => [
            'search' => CivilLookupAccess::evaluate($user, $context, CivilLookupAccess::SEARCH)['allowed'],
            'view' => CivilLookupAccess::evaluate($user, $context, CivilLookupAccess::VIEW)['allowed'],
            'view_full' => $context === CivilLookupContext::STAFF && $user->can(CivilLookupAccess::VIEW_FULL),
            'reverse_links' => CivilLookupGate::reverseLinksEnabled(),
            // FB-CIVIL-02G: whether the eye-icon reveal is offered (the reveal itself is authorized again).
            'reveal_ids' => CivilLookupIdentifierDisclosure::revealOpen($context)
                && CivilLookupAccess::evaluate($user, $context, CivilLookupAccess::VIEW)['allowed'],
        ]]);
    }

    public function byNationalId(NationalIdLookupRequest $request): JsonResponse
    {
        return $this->json($this->lookup->byNationalId($request->user(), CivilLookupContext::of($request), $request->nationalId(), $request->ip()));
    }

    public function byName(NameLookupRequest $request): JsonResponse
    {
        return $this->json($this->lookup->byName($request->user(), CivilLookupContext::of($request), $request->tokens(), $request->ip()));
    }

    public function byParts(PartsLookupRequest $request): JsonResponse
    {
        return $this->json($this->lookup->byParts($request->user(), CivilLookupContext::of($request), $request->parts(), $request->ip()));
    }

    public function page(ReferenceRequest $request): JsonResponse
    {
        return $this->guarded($request, fn () => $this->lookup->page($request->user(), CivilLookupContext::of($request), $request->input('ref'), (int) $request->input('page', 1), $request->ip()));
    }

    public function openRecord(ReferenceRequest $request): JsonResponse
    {
        return $this->guarded($request, fn () => $this->lookup->openRecord($request->user(), CivilLookupContext::of($request), $request->input('ref'), $request->ip()));
    }

    public function openLink(ReferenceRequest $request): JsonResponse
    {
        return $this->guarded($request, fn () => $this->lookup->openLink($request->user(), CivilLookupContext::of($request), $request->input('ref'), $request->ip()));
    }

    /** FB-CIVIL-02G: one complete National ID, on an explicit request (the reference in the body, never a URL). */
    public function revealIdentifier(ReferenceRequest $request): JsonResponse
    {
        return $this->guarded($request, fn () => $this->lookup->revealIdentifier($request->user(), CivilLookupContext::of($request), $request->input('ref'), $request->ip()));
    }

    public function links(ReferenceRequest $request): JsonResponse
    {
        $request->validate(['relation' => ['required']]);

        return $this->guarded($request, fn () => $this->lookup->links($request->user(), CivilLookupContext::of($request), $request->input('ref'), (string) $request->input('relation'), $request->ip()));
    }

    /** A tampered, foreign or expired reference also counts against the invalid-request limiter (§L-11). */
    private function guarded(Request $request, Closure $fn): JsonResponse
    {
        try {
            return $this->json($fn());
        } catch (CivilLookupException $e) {
            if ($e->reason === CivilLookupException::REFERENCE_INVALID) {
                RateLimiter::hit('civil-lookup-invalid|user|'.$request->user()?->getAuthIdentifier(), 3600);
            }
            throw $e;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function json(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', self::NO_STORE);
    }
}
