<?php

namespace App\Enums;

// The ONLY statuses a household head may see for a suggested child
// (FB-FAMILY-ADD-01, ADR §7). Every refusal is the one NOT_ELIGIBLE, so another
// household, a death or a deleted record is never told apart.
enum RegistryChildStatus: string
{
    case ELIGIBLE = 'ELIGIBLE';
    case ALREADY_MEMBER = 'ALREADY_MEMBER';
    case REQUEST_OPEN = 'REQUEST_OPEN';
    case NOT_ELIGIBLE = 'NOT_ELIGIBLE';
}
