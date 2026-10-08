<?php

namespace App\Http\Resources;

use App\Support\ChangeRequests\ChangeRequestOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The result of a Staff workflow action (PWA-5c): the request's public
 * identity and new status, and whether the call was a replay of an
 * operation already done (nothing new written). The full review view is
 * fetched separately — a mutation never returns the proposal.
 *
 * @property-read ChangeRequestOutcome $resource
 */
class ChangeRequestOutcomeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->request->uuid,
            'request_code' => $this->resource->request->request_code,
            'status' => $this->resource->request->status,
            'replayed' => $this->resource->replayed,
        ];
    }
}
