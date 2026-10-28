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
    // The Family Portal's opaque household-member reference (FU-13): a
    // membership of a family. Never stored; recomputed on every request.
    case MEMBER_REF = 'famboook.family-portal.member-ref.v1:';
    // The canonical values a Change Request is based on (PWA-5a, AE-7):
    // change_requests.base_fingerprint, compared again at approve and apply
    // so a stale request never overwrites newer data. Never returned to a
    // client.
    case CHANGE_REQUEST_BASE = 'famboook.change-request.base.v1:';
    // The Coordinator Space opaque member reference (CO-01C, AUTH-ADR-092):
    // a membership of a Family in the Coordinator's scope. Its own domain, so
    // it never equals the household member_ref. Never stored.
    case COORDINATOR_MEMBER_REF = 'famboook.coordinator.member-ref.v1:';
    // Civil Registry Lookup audit (FB-CIVIL-02A, ADR §L-10): the normalized
    // lookup criteria (a National ID or name tokens), so repeated lookups of
    // the same value can be correlated without storing it. Never returned.
    case CIVIL_LOOKUP_QUERY = 'famboook.civil-lookup.query.v1:';
    // The client IP of a Civil Registry Lookup event (same audit).
    case CIVIL_LOOKUP_IP = 'famboook.civil-lookup.ip.v1:';
}
