<?php

namespace App\Enums;

// workflow_events.event_type for Change Requests (PWA-5a, docs/04 §33).
// Stored values are stable. Each status transition has exactly one event
// (ChangeRequestTransitions); APPLY_FAILED alone records no state change — a
// refused or failed apply leaves the request APPROVED (docs/04 §43).
enum WorkflowEventType: string
{
    case SUBMITTED = 'SUBMITTED';
    case REVIEW_STARTED = 'REVIEW_STARTED';
    case RETURNED = 'RETURNED';
    case RESUBMITTED = 'RESUBMITTED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case APPLIED = 'APPLIED';
    case APPLY_FAILED = 'APPLY_FAILED';
    case CANCELLED = 'CANCELLED';
}
