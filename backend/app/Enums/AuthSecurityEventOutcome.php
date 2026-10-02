<?php

namespace App\Enums;

// docs/11 §30a — the result recorded with a security event.
enum AuthSecurityEventOutcome: string
{
    case SUCCESS = 'SUCCESS';
    case FAILURE = 'FAILURE';
    case DENIED = 'DENIED';
}
