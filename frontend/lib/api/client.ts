// Thin fetch wrapper for the Laravel /api/v1 API, using Sanctum's
// stateful SPA cookie authentication (docs/00-PROJECT-CONTEXT.md §56:
// secure cookie/session authentication — never localStorage tokens).
//
// Base URL is environment-driven (NEXT_PUBLIC_API_URL) — never hardcoded,
// no production domain configured here.

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export class ApiError extends Error {
  constructor(
    public status: number,
    public payload: unknown
  ) {
    super(`API request failed with status ${status}`);
    this.name = "ApiError";
  }

  get validationErrors(): Record<string, string[]> | undefined {
    if (
      this.payload &&
      typeof this.payload === "object" &&
      "errors" in this.payload
    ) {
      return (this.payload as { errors: Record<string, string[]> }).errors;
    }
    return undefined;
  }

  get message422(): string | undefined {
    if (
      this.payload &&
      typeof this.payload === "object" &&
      "message" in this.payload
    ) {
      return (this.payload as { message: string }).message;
    }
    return undefined;
  }
}

function getCookie(name: string): string | undefined {
  if (typeof document === "undefined") return undefined;
  const match = document.cookie.match(
    new RegExp("(?:^|; )" + name + "=([^;]*)")
  );
  return match ? decodeURIComponent(match[1]) : undefined;
}

async function ensureCsrfCookie(): Promise<void> {
  await fetch(`${API_URL}/sanctum/csrf-cookie`, { credentials: "include" });
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase();

  if (method !== "GET" && method !== "HEAD") {
    await ensureCsrfCookie();
  }

  const xsrfToken = getCookie("XSRF-TOKEN");

  const response = await fetch(`${API_URL}${path}`, {
    ...options,
    credentials: "include",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(xsrfToken ? { "X-XSRF-TOKEN": xsrfToken } : {}),
      ...options.headers,
    },
  });

  const contentType = response.headers.get("content-type") ?? "";
  const payload = contentType.includes("application/json")
    ? await response.json()
    : null;

  if (!response.ok) {
    throw new ApiError(response.status, payload);
  }

  return payload as T;
}

export const apiClient = {
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, body: unknown) =>
    request<T>(path, { method: "POST", body: JSON.stringify(body) }),
  patch: <T>(path: string, body: unknown) =>
    request<T>(path, { method: "PATCH", body: JSON.stringify(body) }),
};
