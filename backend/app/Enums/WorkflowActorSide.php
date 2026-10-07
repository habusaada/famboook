<?php

namespace App\Enums;

// Which side performed a workflow event (PWA-5a). FAMILY and STAFF are the
// account sides of the actor; SYSTEM is a process with no acting user.
enum WorkflowActorSide: string
{
    case FAMILY = 'FAMILY';
    case STAFF = 'STAFF';
    case SYSTEM = 'SYSTEM';
}
