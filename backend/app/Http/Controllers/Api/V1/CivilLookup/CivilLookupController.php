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
use App\Support\CivilLookup\CivilLookupGate;
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
 */
class CivilLookupController extends Controller
{
    private const NO_STORE = 'no-store, private';

    public function __construct(private readonly LookupCivilRegistryAction $lookup) {}

    /** The caller's lookup status and quota (works while the lookup is closed: it then says so). */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->json(['quota' => CivilLookupQuota::status($user), 'capabilities' => [
            'search' => CivilLookupAccess::evaluate($user, CivilLookupAccess::SEARCH)['allowed'],
            'view' => CivilLookupAccess::evaluate($user, CivilLookupAccess::VIEW)['allowed'],
            'view_full' => $user->can(CivilLookupAccess::VIEW_FULL),
            'reverse_links' => CivilLookupGate::reverseLinksEnabled(),
        ]]);
    }

    public function byNationalId(NationalIdLookupRequest $request): JsonResponse
    {
        return $this->json($this->lookup->byNationalId($request->user(), $request->nationalId(), $request->ip()));
    }

    public function byName(NameLookupRequest $request): JsonResponse
    {
        return $this->json($this->lookup->byName($request->user(), $request->tokens(), $request->ip()));
    }

    public function byParts(PartsLookupRequest $request): JsonResponse
    {
        return $this->json($this->lookup->byParts($request->user(), $request->parts(), $request->ip()));
    }

    public function page(ReferenceRequest $request): JsonResponse
    {
        return $this->guarded($request, fn () => $this->lookup->page($request->user(), $request->input('ref'), (int) $request->input('page', 1), $request->ip()));
    }

    public function openRecord(ReferenceRequest $request): JsonResponse
    {
        return $this->guarded($request, fn () => $this->lookup->openRecord($request->user(), $request->input('ref'), $request->ip()));
    }

    public function openLink(ReferenceRequest $request): JsonResponse
    {
        return $this->guarded($request, fn () => $this->lookup->openLink($request->user(), $request->input('ref'), $request->ip()));
    }

    public function links(ReferenceRequest $request): JsonResponse
    {
        $request->validate(['relation' => ['required']]);

        return $this->guarded($request, fn () => $this->lookup->links($request->user(), $request->input('ref'), (string) $request->input('relation'), $request->ip()));
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
