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

const BASE = "/api/v1/family/auth/activation";

export function startActivation(nationalId: string): Promise<ActivationStart> {
  return apiClient.post<ActivationStart>(`${BASE}/start`, { national_id: nationalId });
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
  | "INVALID_CREDENTIALS"
  | "FAMILY_AUTH_UNAVAILABLE"
  | "RESET_FAILED"
  | "PASSWORD_RESET_UNAVAILABLE";

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
  // One message for every credential, account and context failure.
  INVALID_CREDENTIALS: "رقم الهوية أو كلمة المرور غير صحيحة.",
  FAMILY_AUTH_UNAVAILABLE: "تسجيل الدخول غير متاح حاليًا.",
  RESET_FAILED: "تعذّر تغيير كلمة المرور. ابدأ من جديد أو راجع الإدارة.",
  PASSWORD_RESET_UNAVAILABLE: "الخدمة غير متاحة حاليًا.",
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
