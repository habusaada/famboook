<?php

namespace App\Enums;

/**
 * Review findings on the Gaza geographic reference (Stage 1A audit, Stage 1B
 * decision register Q2). A flag never changes the official source record.
 *
 * PARENT_PLACE_MISMATCH and UNUSUAL_LANDMARK_TYPE are judgements taken from
 * the audit of one source version (GazaGeographySource::$auditedFindings);
 * the other three are objective rules evaluated on every import.
 */
enum GazaLocationReviewIssue: string
{
    // Stage 1A W1: the name points to a different place than its parent.
    case PARENT_PLACE_MISMATCH = 'PARENT_PLACE_MISMATCH';
    // Stage 1A W2: a landmark type that is not a landmark category.
    case UNUSUAL_LANDMARK_TYPE = 'UNUSUAL_LANDMARK_TYPE';
    // Stage 1A W3: a sibling (not a landmark) whose name is identical once
    // all whitespace is ignored, under another source code.
    case SAME_PARENT_SIMILAR_NAME = 'SAME_PARENT_SIMILAR_NAME';
    // Stage 1A W9: a landmark with the same type and trimmed name as another
    // landmark of the same neighborhood, under another source code.
    case POSSIBLE_DUPLICATE_LANDMARK = 'POSSIBLE_DUPLICATE_LANDMARK';
    // Stage 1A W4: leading/trailing or doubled spaces in the source name.
    case NAME_WHITESPACE = 'NAME_WHITESPACE';

    public function severity(): GazaLocationReviewSeverity
    {
        return $this === self::NAME_WHITESPACE
            ? GazaLocationReviewSeverity::INFO
            : GazaLocationReviewSeverity::WARNING;
    }

    /** Where the finding is documented. */
    public function sourceReference(): string
    {
        return match ($this) {
            self::PARENT_PLACE_MISMATCH => 'FB-GEO-01 Stage 1A W1 (§7.1)',
            self::UNUSUAL_LANDMARK_TYPE => 'FB-GEO-01 Stage 1A W2 (§6.2)',
            self::SAME_PARENT_SIMILAR_NAME => 'FB-GEO-01 Stage 1A W3 (§7.2)',
            self::POSSIBLE_DUPLICATE_LANDMARK => 'FB-GEO-01 Stage 1A W9 (§6.4)',
            self::NAME_WHITESPACE => 'FB-GEO-01 Stage 1A W4 (§4)',
        };
    }
}
