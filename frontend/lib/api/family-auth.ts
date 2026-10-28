"use client";

import { useQuery } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";

// Family Portal session, login, activation and password reset (docs/11 §30a): the same Sanctum
// HttpOnly session cookie + XSRF token as the Staff application. No token is
// read or stored by JavaScript, and nothing of the activation — National ID,
// challenge reference, code, password — is written to browser storage: it
// lives in React memory only, so a refresh restarts the flow.

export type FamilyUser = {
  /** The linked Person's name; null when the identity is no longer valid. */
  display_name: string | null;
  roles: string[];
  coordinator: boolean;
  /**
   * Coordinator Space is open right now — the same check the server's
   * coordinator boundary runs. It only decides whether the entry is shown;
   * Coordinator Space still asks the server.
   */
  coordinator_space: boolean;
  /**
   * Pilot isolation (ADR FB-NOTIFY-01 §2h): show the household's notification
   * UI (bell, unread polling, inbox pages). Visibility only — the inbox API
   * still authorizes on its own. Absent or false = hidden and never polled.
   */
  notifications?: boolean;
  /** Show the coordinator's announcement and follow-up screens (pilot isolation). */
  coordinator_notifications?: boolean;
  /**
   * FB-CIVIL-02B: show the Civil Registry Lookup entry of each side — the
   * side's boundary and the same authorization the lookup API runs. Visibility
   * only; every lookup is authorized again. Absent or false = hidden.
   */
  civil_lookup?: { family: boolean; coordinator: boolean };
  /**
   * FB-FAMILY-DASHBOARD-01: the previous successful sign-in before THIS
   * session (UTC ISO 8601), resolved once by the server when the session
   * started; null when unknown or on a first sign-in.
   */
  previous_sign_in_at?: string | null;
  /** True only when the server is certain this session is the account's first sign-in (activation). */
  first_sign_in?: boolean;
  context: {
    /** False when no Family context exists right now; the reason is not sent. */
    available: boolean;
    family: { code: string; name: string | null } | null;
  };
};

export const FAMILY_ME_QUERY_KEY = ["family", "me"] as const;

/** The one /api/v1/family/me query: the user, or null when not signed in. */
export function useFamilyMeQuery() {
  return useQuery({
    queryKey: FAMILY_ME_QUERY_KEY,
    queryFn: async (): Promise<FamilyUser | null> => {
      try {
        return (await apiClient.get<{ user: FamilyUser }>("/api/v1/family/me")).user;
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) return null;
        throw error;
      }
    },
    staleTime: 60 * 1000,
    retry: false,
  });
}

/**
 * Which Family authentication surfaces the server has open right now
 * (FU-14, docs/11 FP-ADR-065): three global booleans, read at RUNTIME so a
 * flag change on the server needs no frontend build. Nothing user-specific.
 */
export type FamilyAuthCapabilities = {
  activation: boolean;
  login: boolean;
  password_reset: boolean;
  /** The signed-in password change (FU-16, FP-ADR-078). */
  password_change: boolean;
};

export const FAMILY_AUTH_CAPABILITIES_QUERY_KEY = ["family", "auth", "capabilities"] as const;

/**
 * The one capabilities query. Consumers FAIL CLOSED: a surface counts as
 * open only once the server has answered exactly `true` — never while
 * loading, never after an error. Not refetched on focus, so a flow already
 * on screen is not pulled away by a background request.
 */
export function useFamilyAuthCapabilitiesQuery() {
  return useQuery({
    queryKey: FAMILY_AUTH_CAPABILITIES_QUERY_KEY,
    queryFn: async (): Promise<FamilyAuthCapabilities> =>
      (await apiClient.get<{ data: FamilyAuthCapabilities }>("/api/v1/family/auth/capabilities")).data,
    staleTime: 60 * 1000,
    refetchOnWindowFocus: false,
    retry: false,
  });
}

/** Password reset is offered only on an explicit server `true`. */
export function usePasswordResetAvailability(): "loading" | "available" | "unavailable" {
  const capabilities = useFamilyAuthCapabilitiesQuery();
  if (capabilities.data?.password_reset === true) return "available";

  return capabilities.isPending ? "loading" : "unavailable";
}

/** The signed-in password change is offered only on an explicit server `true`. */
export function usePasswordChangeAvailability(): "loading" | "available" | "unavailable" {
  const capabilities = useFamilyAuthCapabilitiesQuery();
  if (capabilities.data?.password_change === true) return "available";

  return capabilities.isPending ? "loading" : "unavailable";
}

/**
 * «تغيير كلمة المرور» (FU-16, FP-ADR-078): the signed-in head's own
 * password. The server ends every session and signs this browser in again;
 * the answer is the fresh /me representation. The passwords live only in
 * the form's memory and this request body.
 */
export async function changeFamilyPassword(currentPassword: string, password: string, passwordConfirmation: string): Promise<FamilyUser> {
  return (
    await apiClient.post<{ user: FamilyUser }>("/api/v1/family/account/password", {
      current_password: currentPassword,
      password,
      password_confirmation: passwordConfirmation,
    })
  ).user;
}

export async function familyLogout(): Promise<void> {
  await apiClient.post("/api/v1/family/auth/logout", {});
}

export async function familyLogin(nationalId: string, password: string): Promise<FamilyUser> {
  return (await apiClient.post<{ user: FamilyUser }>("/api/v1/family/auth/login", { national_id: nationalId, password })).user;
}

// ------------------------------------------- activation and password reset

/** The safe timers of a challenge; nothing about the person or the mobile. */
export type ChallengeTimers = {
  resend_after_seconds: number;
  expires_in_seconds: number;
  can_resend: boolean;
};

export type ActivationStart = ChallengeTimers & { challenge: string };

/**
 * First self-activation (FP-ADR-053): an opaque confirmation reference and
 * the MASKED registered mobile — only ever `05*****123`, the last three
 * digits. The full number is never sent to the browser, and no number is
 * ever sent back: the code always goes to the number the server holds.
 */
export type ActivationConfirmation = { confirmation: string; masked_mobile: string };

const BASE = "/api/v1/family/auth/activation";

export function startActivation(nationalId: string): Promise<ActivationConfirmation> {
  return apiClient.post<ActivationConfirmation>(`${BASE}/start`, { national_id: nationalId });
}

/** "Yes, send the code": confirming creates no trust — only the correct code does. */
export function sendActivationCode(confirmation: string): Promise<ActivationStart> {
  return apiClient.post<ActivationStart>(`${BASE}/send`, { confirmation });
}

export function verifyActivation(challenge: string, code: string): Promise<{ verified: true; grant_expires_in_seconds: number }> {
  return apiClient.post(`${BASE}/verify`, { challenge, code });
}

export function resendActivation(challenge: string): Promise<ChallengeTimers> {
  return apiClient.post<ChallengeTimers>(`${BASE}/resend`, { challenge });
}

export async function completeActivation(challenge: string, password: string, passwordConfirmation: string): Promise<FamilyUser> {
  return (
    await apiClient.post<{ user: FamilyUser }>(`${BASE}/complete`, {
      challenge,
      password,
      password_confirmation: passwordConfirmation,
    })
  ).user;
}

const RESET = "/api/v1/family/auth/password/reset";

export function startPasswordReset(nationalId: string): Promise<ActivationStart> {
  return apiClient.post<ActivationStart>(`${RESET}/start`, { national_id: nationalId });
}

export function verifyPasswordReset(challenge: string, code: string): Promise<{ verified: true; grant_expires_in_seconds: number }> {
  return apiClient.post(`${RESET}/verify`, { challenge, code });
}

export function resendPasswordReset(challenge: string): Promise<ChallengeTimers> {
  return apiClient.post<ChallengeTimers>(`${RESET}/resend`, { challenge });
}

export async function completePasswordReset(challenge: string, password: string, passwordConfirmation: string): Promise<FamilyUser> {
  return (
    await apiClient.post<{ user: FamilyUser }>(`${RESET}/complete`, {
      challenge,
      password,
      password_confirmation: passwordConfirmation,
    })
  ).user;
}

/** The stable public error codes of the Family authentication API. */
export type FamilyAuthErrorCode =
  | "OTP_INVALID"
  | "OTP_EXPIRED"
  | "OTP_LOCKED"
  | "OTP_COOLDOWN"
  | "OTP_SEND_LIMIT"
  | "GRANT_EXPIRED"
  | "ACTIVATION_FAILED"
  | "TOO_MANY_REQUESTS"
  | "ACTIVATION_UNAVAILABLE"
  | "ACTIVATION_REFUSED"
  | "INVALID_CREDENTIALS"
  | "FAMILY_AUTH_UNAVAILABLE"
  | "RESET_FAILED"
  | "PASSWORD_RESET_UNAVAILABLE"
  | "PASSWORD_CHANGE_FAILED"
  | "PASSWORD_CHANGE_UNAVAILABLE"
  | "CONTACT_UPDATE_FAILED"
  | "CONTACT_UPDATE_EXPIRED"
  | "CONTACT_UPDATE_UNAVAILABLE";

const MESSAGES: Record<FamilyAuthErrorCode, string> = {
  OTP_INVALID: "رمز التحقق غير صحيح.",
  OTP_EXPIRED: "انتهت صلاحية رمز التحقق. اطلب رمزًا جديدًا أو ابدأ من جديد.",
  OTP_LOCKED: "تم إيقاف هذا الرمز. ابدأ من جديد.",
  OTP_COOLDOWN: "يمكن طلب رمز جديد بعد قليل.",
  OTP_SEND_LIMIT: "لا يمكن إرسال رمز آخر الآن. ابدأ من جديد لاحقًا.",
  GRANT_EXPIRED: "انتهت مهلة إنشاء كلمة المرور. ابدأ من جديد.",
  ACTIVATION_FAILED: "تعذّر إكمال التفعيل. ابدأ من جديد أو راجع الإدارة.",
  TOO_MANY_REQUESTS: "محاولات كثيرة. حاول مجددًا بعد قليل.",
  ACTIVATION_UNAVAILABLE: "الخدمة غير متاحة حاليًا.",
  // One refusal for every reason an identifier cannot start activation
  // (FP-ADR-054); the reason is never sent to the browser.
  ACTIVATION_REFUSED: "تعذّر متابعة التفعيل بهذه البيانات. تأكد من إدخال رقم هوية رب الأسرة المسجل في فامبوك، ثم حاول مرة أخرى.",
  // One message for every credential, account and context failure.
  INVALID_CREDENTIALS: "رقم الهوية أو كلمة المرور غير صحيحة.",
  FAMILY_AUTH_UNAVAILABLE: "تسجيل الدخول غير متاح حاليًا.",
  RESET_FAILED: "تعذّر تغيير كلمة المرور. ابدأ من جديد أو راجع الإدارة.",
  PASSWORD_RESET_UNAVAILABLE: "الخدمة غير متاحة حاليًا.",
  PASSWORD_CHANGE_FAILED: "تعذّر تغيير كلمة المرور. حدّث الصفحة وحاول مرة أخرى.",
  PASSWORD_CHANGE_UNAVAILABLE: "تغيير كلمة المرور غير متاح حاليًا.",
  CONTACT_UPDATE_FAILED: "تعذّر إكمال تحديث رقم الجوال. أُغلق الطلب؛ ابدأ طلبًا جديدًا.",
  CONTACT_UPDATE_EXPIRED: "انتهت مهلة تأكيد الطلب. ابدأ طلبًا جديدًا.",
  CONTACT_UPDATE_UNAVAILABLE: "تحديث رقم الجوال غير متاح حاليًا.",
};

export const CONNECTION_ERROR = "تعذّر الاتصال بالخادم. الرجاء المحاولة مرة أخرى.";

export type FamilyAuthFailure = {
  code: FamilyAuthErrorCode | null;
  message: string;
  /** Laravel field errors of a 422 without a code. */
  fields: Record<string, string[]>;
  retryAfterSeconds: number | null;
};

/** One reading of any Family authentication failure: the stable code, never free text. */
export function readFamilyAuthError(error: unknown): FamilyAuthFailure {
  if (!(error instanceof ApiError)) {
    return { code: null, message: CONNECTION_ERROR, fields: {}, retryAfterSeconds: null };
  }
  const payload = (error.payload ?? {}) as { code?: string; retry_after_seconds?: number };
  const code = payload.code && payload.code in MESSAGES ? (payload.code as FamilyAuthErrorCode) : null;
  const fields = error.validationErrors ?? {};
  if (code === null && error.status === 429) {
    return { code: "TOO_MANY_REQUESTS", message: MESSAGES.TOO_MANY_REQUESTS, fields, retryAfterSeconds: null };
  }

  return {
    code,
    message: code ? MESSAGES[code] : Object.keys(fields).length > 0 ? "" : CONNECTION_ERROR,
    fields,
    retryAfterSeconds: typeof payload.retry_after_seconds === "number" ? payload.retry_after_seconds : null,
  };
}
