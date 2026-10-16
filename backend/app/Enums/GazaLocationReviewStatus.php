<?php

namespace App\Enums;

/**
 * Review status of a geographic review flag. The import only ever creates
 * OPEN flags and never changes an existing flag's status; no workflow sets
 * the other values yet. A correction of the source itself needs explicit
 * authorization (Stage 1B G10) and is never applied through a flag.
 */
enum GazaLocationReviewStatus: string
{
    case OPEN = 'OPEN';
    // Reviewed: the source record is correct as supplied.
    case CONFIRMED = 'CONFIRMED';
    // Reviewed: needs an authorized source correction.
    case CORRECTION_REQUIRED = 'CORRECTION_REQUIRED';
    case DISMISSED = 'DISMISSED';
}
