<?php

namespace App\Support\ChangeRequests;

/**
 * A Change Request handler whose type evolved its submitted_data schema
 * (FB-GEO-01: RESIDENCE_UPDATE v1 → v2). It still writes payloadVersion()
 * for new requests, and reads the earlier versions it lists, so requests
 * submitted before the change keep reviewing and applying unchanged — never
 * migrated.
 *
 * The base fingerprint of a request is recomputed for ITS OWN version: an
 * earlier request's base covers exactly the values it was taken over at
 * submission. Handlers without this interface accept only payloadVersion().
 */
interface ReadsEarlierPayloadVersions
{
    /** @return list<int> every version this handler can review and apply, payloadVersion() included */
    public function acceptedPayloadVersions(): array;

    /**
     * The base values of a request of the given version (see baseValues()).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function baseValuesForVersion(int $payloadVersion, ChangeRequestTarget $target, array $data): array;
}
