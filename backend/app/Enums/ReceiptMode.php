<?php

namespace App\Enums;

// INTERNAL delivery receipt: the original beneficiary in person, or an
// unmarried son/daughter of theirs with both National IDs present.
enum ReceiptMode: string
{
    case PERSONAL = 'PERSONAL';
    case DELEGATE = 'DELEGATE';
}
