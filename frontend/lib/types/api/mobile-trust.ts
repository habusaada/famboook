// Staff mobile trust API (docs/06 §22b, PersonMobileTrustController).

/**
 * The derived state decided by CurrentTrustedMobile. UNAVAILABLE means the
 * Family Auth fingerprint key is unusable (fail closed).
 */
export type MobileTrustState = "TRUSTED" | "STALE" | "REVOKED" | "UNVERIFIED" | "NO_MOBILE" | "UNAVAILABLE";

/** Stored status of one history row; PENDING_VERIFICATION is never returned. */
export type MobileTrustRowStatus = "TRUSTED" | "STALE" | "REVOKED";

/** Every recorded method. SELF_OTP is first self-activation, never a Staff grant. */
export type MobileVerificationMethod = "IN_PERSON" | "STAFF_CALLBACK" | "AUTHORIZED_RECORD_REVIEW" | "SELF_OTP";

export type StaffMobileVerificationMethod = Exclude<MobileVerificationMethod, "SELF_OTP">;

export type MobileTrustRevokeReason = "REPORTED_LOST" | "NOT_OWNER" | "VERIFICATION_ERROR" | "ADMINISTRATIVE";

/** One MobileTrustResource row. The number is a mask of its last two digits. */
export interface MobileTrustRecord {
  id: string;
  status: MobileTrustRowStatus;
  mobile_masked: string;
  verification_method: MobileVerificationMethod | null;
  verified_at: string | null;
  verified_by: string | null;
  stale_at: string | null;
  revoked_at: string | null;
  revoked_by: string | null;
  revoke_reason: MobileTrustRevokeReason | null;
}

export interface PersonMobileTrust {
  person_code: string;
  state: MobileTrustState;
  mobile_masked: string | null;
  /** Newest first. */
  history: MobileTrustRecord[];
}

/** The mobile number is never an input: the Person's stored mobile is trusted. */
export interface GrantMobileTrustPayload {
  verification_method: StaffMobileVerificationMethod;
}

export interface RevokeMobileTrustPayload {
  reason: MobileTrustRevokeReason;
}
