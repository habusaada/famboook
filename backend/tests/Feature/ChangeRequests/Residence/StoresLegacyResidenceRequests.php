<?php

namespace Tests\Feature\ChangeRequests\Residence;

use App\Enums\ChangeRequestType;
use App\Models\ChangeRequest;
use App\Support\ChangeRequests\ChangeRequestBase;
use App\Support\ChangeRequests\ChangeRequestTarget;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\ReadsEarlierPayloadVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RESIDENCE_UPDATE requests exactly as an EARLIER payload version stored them
 * (FB-GEO-02: new submissions are v3 only, but v1 / v2 requests already in
 * the database must still review, approve and apply). A real request is
 * created through the engine (so it has its code, workflow and activity
 * rows), then its payload_version, submitted_data and base fingerprint are
 * rewritten below the model — a submitted proposal is immutable through
 * Eloquent — to what that version's handler stored: the given data and the
 * base that version took (baseValuesForVersion).
 *
 * Needs $this->context (the household head's FamilyAccessResult).
 */
trait StoresLegacyResidenceRequests
{
    /** @param array<string, mixed> $data the stored proposal (only the changed fields, as v1 / v2 normalized it) */
    private function legacyResidenceRequest(int $version, array $data): ChangeRequest
    {
        $id = $this->actingAs($this->context->user->fresh())->postJson('/api/v1/family/change-requests', [
            'type' => 'RESIDENCE_UPDATE',
            'client_reference' => (string) Str::uuid(),
            'data' => ['address_text' => 'عنوان مؤقت '.Str::random(8)],
        ])->assertCreated()->json('data.id');
        $request = ChangeRequest::where('uuid', $id)->sole();

        $handler = app(ChangeRequestTypes::class)->handler(ChangeRequestType::RESIDENCE_UPDATE);
        assert($handler instanceof ReadsEarlierPayloadVersions);
        $base = ChangeRequestBase::of($handler->baseValuesForVersion($version, ChangeRequestTarget::family($this->context->family), $data));

        DB::table('change_requests')->where('id', $request->id)->update([
            'payload_version' => $version,
            'submitted_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'base_fingerprint' => $base['fingerprint'],
            'base_key_version' => $base['key_version'],
        ]);

        return $request->fresh();
    }
}
