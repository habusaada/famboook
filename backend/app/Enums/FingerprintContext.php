<?php

namespace App\Enums;

// docs/11 §30a — domain separation of the Family Portal keyed fingerprints.
// A value fingerprinted in one context can never equal the same value in
// another. The prefixes are part of stored data: never change one, add a
// new version instead.
enum FingerprintContext: string
{
    // The nine normalized National ID digits (family_auth_identities.login_key).
    case LOGIN_ID = 'famboook.family-auth.login-id.v1:';
    // The normalized mobile number (person_mobile_trusts.mobile_fingerprint).
    case MOBILE = 'famboook.family-auth.mobile.v1:';
    // An OTP code bound to its challenge (auth_otp_challenges.code_hash).
    case OTP_CODE = 'famboook.family-auth.otp.v1:';
}
