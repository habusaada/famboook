<?php

namespace App\Enums;

// Who a Change Request presentation is for (PWA-5b). A handler shapes and
// masks its values per audience; a family never receives Staff-only data.
enum ChangeRequestAudience: string
{
    case FAMILY = 'FAMILY';
    case STAFF = 'STAFF';
    // CO-02.4: a Coordinator following their OWN request (masked identifiers,
    // nothing Staff-only, never another registry Person).
    case COORDINATOR = 'COORDINATOR';
}
