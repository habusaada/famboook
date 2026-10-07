<?php

namespace App\Enums;

// Who a Change Request presentation is for (PWA-5b). A handler shapes and
// masks its values per audience; a family never receives Staff-only data.
enum ChangeRequestAudience: string
{
    case FAMILY = 'FAMILY';
    case STAFF = 'STAFF';
}
