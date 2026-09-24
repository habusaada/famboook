<?php

namespace App\Enums;

// docs/03-BUSINESS-RULES.md §47d. INTERNAL: Famboook verifies identity and
// records delivery. EXTERNAL: Famboook issues beneficiary lists to another
// organization; physical delivery happens outside and is never inferred.
enum ExecutionMode: string
{
    case INTERNAL = 'INTERNAL';
    case EXTERNAL = 'EXTERNAL';
}
