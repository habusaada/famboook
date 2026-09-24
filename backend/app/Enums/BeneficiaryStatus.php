<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §36c. NOMINATED → APPROVED | REJECTED (both
// modes); INTERNAL only: APPROVED → NOT_DELIVERED. REMOVED is the V1-A
// history-preserving withdrawal of a NOMINATED row. REJECTED and
// NOT_DELIVERED are terminal. There is deliberately no DELIVERED status:
// delivery is a separate record (assistance_deliveries), and inclusion in
// an external list is derived from list entries.
enum BeneficiaryStatus: string
{
    case NOMINATED = 'NOMINATED';
    case REMOVED = 'REMOVED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case NOT_DELIVERED = 'NOT_DELIVERED';
}
