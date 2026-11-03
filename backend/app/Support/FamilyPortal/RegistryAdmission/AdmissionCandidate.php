<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Enums\RegistryChildReason;
use App\Support\CivilLookup\CivilRecord;

/**
 * One suggested child as discovery would classify it, for an admission (FB-FAMILY-ADD-01
 * ADD-WP-07B-2c): its record in the page's release and the cross-record reasons only
 * discovery can see (duplicate rows, contradictory parent links). INTERNAL; the record
 * carries the National ID, so it is never logged, serialized or returned.
 */
final class AdmissionCandidate
{
    /** @param  list<RegistryChildReason>  $sourceReasons */
    public function __construct(
        public readonly CivilRecord $record,
        public readonly array $sourceReasons,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['releaseId' => $this->record->releaseId, 'record' => '[redacted]', 'sourceReasons' => $this->sourceReasons];
    }
}
