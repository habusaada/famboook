<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §36c. V1-A: NOMINATED, or REMOVED (a
// history-preserving withdrawal of the nomination). V1-B will add
// APPROVED / REJECTED / NOT_DELIVERED. Delivery is a separate concept and
// is never a beneficiary status.
enum BeneficiaryStatus: string
{
    case NOMINATED = 'NOMINATED';
    case REMOVED = 'REMOVED';
}
