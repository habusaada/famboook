<?php

namespace App\Enums;

// docs/02 §45b — family_auth_identities.status.
enum AuthIdentityStatus: string
{
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case SUPERSEDED = 'SUPERSEDED';
}
