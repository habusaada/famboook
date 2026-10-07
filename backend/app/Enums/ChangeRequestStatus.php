<?php

namespace App\Enums;

// docs/05 §54, docs/02 §55 — the Change Request lifecycle, plus CANCELLED
// (PWA-5a, WF-ADR-049, AE-3). Stored values are stable; family-facing wording
// is a presentation concern (docs/05 §96). Transitions: ChangeRequestTransitions.
enum ChangeRequestStatus: string
{
    // Valid for future compatibility; no V1 flow creates a DRAFT (AE-2).
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case RETURNED_FOR_CLARIFICATION = 'RETURNED_FOR_CLARIFICATION';
    case RESUBMITTED = 'RESUBMITTED';
    // Authorized for application — canonical data has NOT changed (APPROVED ≠ APPLIED).
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    // The Domain Action succeeded and canonical data changed.
    case APPLIED = 'APPLIED';
    case CANCELLED = 'CANCELLED';

    /**
     * Submitted and not yet finished — the requests that make a Family
     * Profile Review section PENDING (docs/11 §9, PWA-4). APPROVED is open:
     * nothing has changed until APPLIED. DRAFT is not open (never submitted).
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::SUBMITTED, self::UNDER_REVIEW, self::RETURNED_FOR_CLARIFICATION, self::RESUBMITTED, self::APPROVED];
    }

    /** @return list<self> no outgoing transition; the request is immutable */
    public static function terminal(): array
    {
        return [self::APPLIED, self::REJECTED, self::CANCELLED];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }
}
