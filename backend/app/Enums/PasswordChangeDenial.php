<?php

namespace App\Enums;

// docs/11 FU-16 — why an authenticated Family password change was refused.
// Recorded as the PASSWORD_CHANGED security-event reason code; the browser
// gets a field error for the signed-in user's own input, never more.
enum PasswordChangeDenial: string
{
    // The current password did not match.
    case WRONG_CURRENT_PASSWORD = 'WRONG_CURRENT_PASSWORD';

    // The new password is the current one.
    case PASSWORD_UNCHANGED = 'PASSWORD_UNCHANGED';
}
