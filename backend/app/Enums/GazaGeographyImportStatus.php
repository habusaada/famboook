<?php

namespace App\Enums;

/**
 * Outcome of one Gaza geographic import run (FB-GEO-01 Stage 1C.1,
 * ADR-FB-GEO-01 §4.4).
 *
 * - RUNNING: started, not finished (at most one at a time).
 * - SUCCEEDED: the import transaction committed (possibly creating nothing).
 * - REJECTED: refused by a governance or source check (checksum, structure,
 *   invalid or contradictory records, a revision of imported records, a
 *   concurrent import); nothing was written.
 * - FAILED: an unexpected error; the import transaction was rolled back.
 */
enum GazaGeographyImportStatus: string
{
    case RUNNING = 'RUNNING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
    case REJECTED = 'REJECTED';
}
