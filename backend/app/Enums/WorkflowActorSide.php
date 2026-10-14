<?php

namespace App\Enums;

// Which side performed a workflow event (PWA-5a). FAMILY and STAFF are the
// account sides of the actor; SYSTEM is a process with no acting user.
// COORDINATOR (CO-02, docs/04 DB-ADR-062): a Coordinator acting on their own
// request (submit, resubmit, cancel) — a family-side account, but a distinct
// actor from the household.
enum WorkflowActorSide: string
{
    case FAMILY = 'FAMILY';
    case STAFF = 'STAFF';
    case SYSTEM = 'SYSTEM';
    case COORDINATOR = 'COORDINATOR';
}
