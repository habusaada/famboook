<?php

namespace App\Enums;

// Who submitted a Change Request (CO-02.3a; docs/04 DB-ADR-062). FAMILY: the
// household, through the Family Portal — every request before CO-02.
// COORDINATOR: a Coordinator, for a Family or a placement within their scope
// (docs/adr/CO-02-COORDINATOR-REQUESTS.md). Part of the immutable proposal.
// STAFF: a Staff user, for HOUSEHOLD_RECONCILIATION only (FB-FAMILY-RECON-01).
enum SubmissionChannel: string
{
    case FAMILY = 'FAMILY';
    case COORDINATOR = 'COORDINATOR';
    case STAFF = 'STAFF';
}
