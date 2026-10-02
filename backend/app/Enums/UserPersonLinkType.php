<?php

namespace App\Enums;

// docs/02 §43, §45b — how a User relates to a Person. V1: SELF only.
enum UserPersonLinkType: string
{
    case SELF = 'SELF';
}
