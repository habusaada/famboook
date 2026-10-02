<?php

namespace App\Enums;

// docs/02 §45b — organizational scope levels of a coordinator assignment.
enum CoordinatorScopeType: string
{
    case CLAN = 'CLAN';
    case BRANCH_GROUP = 'BRANCH_GROUP';
    case BRANCH = 'BRANCH';
}
