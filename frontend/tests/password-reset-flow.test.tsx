import { act, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { PasswordResetFlow } from "@/components/family/auth/password-reset-flow";
import { ApiError, apiClient } from "@/lib/api/client";
import { FAMILY_ME_QUERY_KEY } from "@/lib/api/family-auth";
import { browserStorageDump, familyUser, renderWithClient } from "./helpers";

const router = { replace: vi.fn(), push: vi.fn() };
vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

// Synthetic values only.
const NATIONAL_ID = "123456789";
const CHALLENGE = "3f0e2a6c-5b1d-4c7e-9a8b-1d2e3f4a5b6c";
const CODE = "482915";
const PASSWORD = "a new synthetic passphrase";
const BASE = "/api/v1/family/auth/password/reset";
const START = { challenge: CHALLENGE, resend_after_seconds: 60, expires_in_seconds: 300, can_resend: true };
const VERIFIED = { verified: true, grant_expires_in_seconds: 600 };

type Reply = unknown | (() => unknown);

/** Routes apiClient.post by path; an ApiError reply is thrown. */
function api(replies: Record<string, Reply>) {
  return vi.spyOn(apiClient, "post").mockImplementation(async (path: string) => {
    const key = path.replace(BASE, "");
    if (!(key in replies)) throw new Error(`Unexpected request: ${path}`);
    const next = replies[key];
    const reply = typeof next === "function" ? (next as () => unknown)() : next;
    if (reply instanceof ApiError) throw reply;
    return reply as never;
  });
}

const refusal = (status: number, code: string, extra: object = {}) => new ApiError(status, { message: "…", code, ...extra });

const idField = () => screen.getByLabelText("رقم الهوية");
const otpField = () => screen.getByLabelText(/رمز التحقق المكوّن من/);

async function toOtpStep(user: ReturnType<typeof userEvent.setup>) {
  await user.type(idField(), NATIONAL_ID);
  await user.click(screen.getByRole("button", { name: "متابعة" }));
  await screen.findByRole("heading", { name: "أدخل رمز التحقق" });
}

async function toPasswordStep(user: ReturnType<typeof userEvent.setup>) {
  await toOtpStep(user);
  await user.type(otpField(), CODE);
  await user.click(screen.getByRole("button", { name: "تحقق" }));
  await screen.findByRole("heading", { name: "كلمة مرور جديدة" });
}

async function fillPasswords(user: ReturnType<typeof userEvent.setup>, password = PASSWORD, confirmation = password) {
  await user.type(screen.getByLabelText("كلمة المرور الجديدة"), password);
  await user.type(screen.getByLabelText("تأكيد كلمة المرور"), confirmation);
  await user.click(screen.getByRole("button", { name: "حفظ كلمة المرور" }));
}

beforeEach(() => {
  router.replace.mockReset();
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("step 1 — the identifier", () => {
  it("renders the approved copy and a way back to the login", () => {
    renderWithClient(<PasswordResetFlow />);

    expect(screen.getByRole("heading", { level: 1, name: "استعادة كلمة المرور" })).toBeInTheDocument();
    expect(idField()).toHaveAttribute("inputmode", "numeric");
    expect(idField()).toHaveAttribute("dir", "ltr");
    expect(screen.getByRole("link", { name: "تسجيل الدخول" })).toHaveAttribute("href", "/family/login");
    expect(screen.getByText("الخطوة 1 من 3")).toBeInTheDocument();
  });

  it("sends the normalized identifier to the reset API, not the activation one", async () => {
    const post = api({ "/start": START });
    const user = userEvent.setup();
    renderWithClient(<PasswordResetFlow />);

    await user.type(idField(), "١٢٣٤٥٦٧٨٩");
    await user.click(screen.getByRole("button", { name: "متابعة" }));

    await screen.findByRole("heading", { name: "أدخل رمز التحقق" });
    expect(post).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith(`${BASE}/start`, { national_id: NATIONAL_ID });
  });

  it("blocks the request for a malformed identifier", async () => {
    const post = api({});
    const user = userEvent.setup();
    renderWithClient(<PasswordResetFlow />);

    await user.type(idField(), "12345");
    await user.click(screen.getByRole("button", { name: "متابعة" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("رقم الهوية يجب أن يتكون من 9 أرقام.");
    expect(post).not.toHaveBeenCalled();
  });

  it.each([
    [503, "PASSWORD_RESET_UNAVAILABLE", "الخدمة غير متاحة حاليًا."],
    [429, "TOO_MANY_REQUESTS", "محاولات كثيرة. حاول مجددًا بعد قليل."],
  ])("maps %i %s to its message", async (status, code, message) => {
    api({ "/start": refusal(status, code) });
    const user = userEvent.setup();
    renderWithClient(<PasswordResetFlow />);

    await user.type(idField(), NATIONAL_ID);
    await user.click(screen.getByRole("button", { name: "متابعة" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(message);
  });
});

describe("step 2 — the code", () => {
  it("moves on with generic copy and shows no destination", async () => {
    api({ "/start": START });
    const user = userEvent.setup();
    const { container } = renderWithClient(<PasswordResetFlow />);

    await toOtpStep(user);

    expect(
      screen.getByText("إذا كانت البيانات مطابقة لسجلاتنا، أرسلنا رمز تحقق إلى رقم الجوال الموثّق المسجّل لدينا.")
    ).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/05\d|\*{2,}|\d{2}\*+/);
    expect(document.body.textContent).not.toContain(NATIONAL_ID);
    // The same OTP control as activation: one input, six slots.
    expect(container.querySelectorAll("input")).toHaveLength(1);
    expect(container.querySelectorAll("[data-otp-slot]")).toHaveLength(6);
    expect(otpField()).toHaveAttribute("autocomplete", "one-time-code");
    expect(otpField()).toHaveAttribute("inputmode", "numeric");
  });

  it("accepts a pasted code and verifies it against the reset API", async () => {
    const post = api({ "/start": START, "/verify": VERIFIED });
    const user = userEvent.setup();
    renderWithClient(<PasswordResetFlow />);
    await toOtpStep(user);

    await user.click(otpField());
    await user.paste("٤٨٢ ٩١٥");
    expect(otpField()).toHaveValue(CODE);

    await user.click(screen.getByRole("button", { name: "تحقق" }));
    await screen.findByRole("heading", { name: "كلمة مرور جديدة" });
    expect(post).toHaveBeenCalledWith(`${BASE}/verify`, { challenge: CHALLENGE, code: CODE });
  });

  it.each([
    [422, "OTP_INVALID", "رمز التحقق غير صحيح."],
    [410, "OTP_EXPIRED", "انتهت صلاحية رمز التحقق. اطلب رمزًا جديدًا أو ابدأ من جديد."],
  ])("maps %i %s and lets the user try again", async (status, code, message) => {
    api({ "/start": START, "/verify": refusal(status, code) });
    const user = userEvent.setup();
    renderWithClient(<PasswordResetFlow />);
    await toOtpStep(user);

    await user.type(otpField(), CODE);
    await user.click(screen.getByRole("button", { name: "تحقق" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(message);
    expect(otpField()).toHaveValue("");
    expect(otpField()).toBeEnabled();
  });

  it("locks after OTP_LOCKED and restarts cleanly", async () => {
    api({ "/start": START, "/verify": refusal(423, "OTP_LOCKED") });
    const user = userEvent.setup();
    renderWithClient(<PasswordResetFlow />);
    await toOtpStep(user);

    await user.type(otpField(), CODE);
    await user.click(screen.getByRole("button", { name: "تحقق" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("تم إيقاف هذا الرمز. ابدأ من جديد.");
    expect(otpField()).toBeDisabled();

    await user.click(screen.getByRole("button", { name: "البدء من جديد" }));
    expect(screen.getByRole("heading", { name: "استعادة كلمة المرور" })).toBeInTheDocument();
    expect(idField()).toHaveValue("");
  });

  describe("resend", () => {
    beforeEach(() => {
      vi.useFakeTimers({ shouldAdvanceTime: true });
    });
    afterEach(() => {
      vi.useRealTimers();
    });

    const tick = (seconds: number) => act(async () => void vi.advanceTimersByTime(seconds * 1000));

    it("counts down, then requests a new code from the reset API", async () => {
      const post = api({ "/start": START, "/resend": { resend_after_seconds: 60, expires_in_seconds: 300, can_resend: true } });
      const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
      renderWithClient(<PasswordResetFlow />);
      await toOtpStep(user);

      expect(screen.getByText(/إعادة الإرسال بعد/)).toHaveTextContent("إعادة الإرسال بعد 60 ثانية");
      await tick(60);
      await user.click(await screen.findByRole("button", { name: "إعادة إرسال الرمز" }));

      expect(await screen.findByRole("status")).toHaveTextContent("تم طلب رمز جديد");
      expect(post).toHaveBeenCalledWith(`${BASE}/resend`, { challenge: CHALLENGE });
      expect(screen.getByText(/إعادة الإرسال بعد/)).toHaveTextContent("إعادة الإرسال بعد 60 ثانية");
    });

    it("follows the server's cooldown and stops at the send limit", async () => {
      let reply: ApiError = refusal(429, "OTP_COOLDOWN", { retry_after_seconds: 25 });
      api({ "/start": START, "/resend": () => reply });
      const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
      renderWithClient(<PasswordResetFlow />);
      await toOtpStep(user);
      await tick(60);

      await user.click(await screen.findByRole("button", { name: "إعادة إرسال الرمز" }));
      expect(await screen.findByRole("alert")).toHaveTextContent("يمكن طلب رمز جديد بعد قليل.");
      expect(screen.getByText(/إعادة الإرسال بعد/)).toHaveTextContent("إعادة الإرسال بعد 25 ثانية");

      reply = refusal(429, "OTP_SEND_LIMIT");
      await tick(26);
      await user.click(await screen.findByRole("button", { name: "إعادة إرسال الرمز" }));
      expect(await screen.findByRole("alert")).toHaveTextContent("لا يمكن إرسال رمز آخر الآن.");
      expect(screen.queryByRole("button", { name: "إعادة إرسال الرمز" })).not.toBeInTheDocument();
    });
  });
});

describe("step 3 — the new password", () => {
  it("uses new-password fields and only the rules the server enforces", async () => {
    api({ "/start": START, "/verify": VERIFIED });
    const user = userEvent.setup();
    renderWithClient(<PasswordResetFlow />);
    await toPasswordStep(user);

    expect(screen.getByLabelText("كلمة المرور الجديدة")).toHaveAttribute("autocomplete", "new-password");
    expect(screen.getByLabelText("تأكيد كلمة المرور")).toHaveAttribute("autocomplete", "new-password");
    expect(screen.getByText(/لا تقل عن 8 أحرف/)).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/حرف كبير|رمز خاص|أرقام وحروف/);
    // The show/hide control is on the right, and the text keeps clear of it.
    expect(screen.getByRole("button", { name: "إظهار كلمة المرور" })).toHaveClass("right-1");
    expect(screen.getByLabelText("كلمة المرور الجديدة")).toHaveClass("pr-12");
    expect(document.querySelector("[data-field-icon]")).toBeNull();

    await user.click(screen.getByRole("button", { name: "إظهار كلمة المرور" }));
    expect(screen.getByLabelText("كلمة المرور الجديدة")).toHaveAttribute("type", "text");
    expect(screen.getByLabelText("تأكيد كلمة المرور")).toHaveAttribute("type", "text");
  });

  it.each([
    ["short12", "short12", "كلمة المرور يجب ألا تقل عن 8 أحرف."],
    [PASSWORD, "another passphrase", "كلمتا المرور غير متطابقتين."],
    ["ك".repeat(37), "ك".repeat(37), "كلمة المرور طويلة جدًا. يرجى استخدام كلمة مرور أقصر."],
    ["a".repeat(73), "a".repeat(73), "كلمة المرور طويلة جدًا. يرجى استخدام كلمة مرور أقصر."],
  ])("blocks an invalid new password (%#)", async (password, confirmation, message) => {
    const post = api({ "/start": START, "/verify": VERIFIED });
    const user = userEvent.setup();
    renderWithClient(<PasswordResetFlow />);
    await toPasswordStep(user);

    await fillPasswords(user, password, confirmation);

    expect(await screen.findByRole("alert")).toHaveTextContent(message);
    expect(post).not.toHaveBeenCalledWith(`${BASE}/complete`, expect.anything());
  });

  it("accepts eight Arabic characters", async () => {
    const post = api({ "/start": START, "/verify": VERIFIED, "/complete": { user: familyUser() } });
    const user = userEvent.setup();
    renderWithClient(<PasswordResetFlow />);
    await toPasswordStep(user);

    await fillPasswords(user, "كلمةسرية");

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family"));
    expect(post).toHaveBeenCalledWith(`${BASE}/complete`, { challenge: CHALLENGE, password: "كلمةسرية", password_confirmation: "كلمةسرية" });
  });

  it.each([
    [410, "GRANT_EXPIRED", "انتهت مهلة إنشاء كلمة المرور. ابدأ من جديد."],
    [409, "RESET_FAILED", "تعذّر تغيير كلمة المرور. ابدأ من جديد أو راجع الإدارة."],
    [423, "OTP_LOCKED", "تم إيقاف هذا الرمز. ابدأ من جديد."],
  ])("returns to the start on %i %s with the reason", async (status, code, message) => {
    api({ "/start": START, "/verify": VERIFIED, "/complete": refusal(status, code) });
    const user = userEvent.setup();
    renderWithClient(<PasswordResetFlow />);
    await toPasswordStep(user);

    await fillPasswords(user);

    expect(await screen.findByRole("heading", { name: "استعادة كلمة المرور" })).toBeInTheDocument();
    expect(screen.getByRole("status")).toHaveTextContent(message);
    expect(idField()).toHaveValue("");
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("shows the server's password error and stays on the step", async () => {
    api({
      "/start": START,
      "/verify": VERIFIED,
      "/complete": new ApiError(422, { message: "…", errors: { password: ["كلمة المرور طويلة جدًا. يرجى استخدام كلمة مرور أقصر."] } }),
    });
    const user = userEvent.setup();
    renderWithClient(<PasswordResetFlow />);
    await toPasswordStep(user);

    await fillPasswords(user);

    expect(await screen.findByRole("alert")).toHaveTextContent("كلمة المرور طويلة جدًا. يرجى استخدام كلمة مرور أقصر.");
    expect(screen.getByRole("heading", { name: "كلمة مرور جديدة" })).toBeInTheDocument();
  });

  it("signs in and goes to /family with no extra login step", async () => {
    const account = familyUser();
    const post = api({ "/start": START, "/verify": VERIFIED, "/complete": { user: account } });
    const user = userEvent.setup();
    const { client } = renderWithClient(<PasswordResetFlow />);
    await toPasswordStep(user);

    await fillPasswords(user);

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family"));
    expect(router.replace).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith(`${BASE}/complete`, { challenge: CHALLENGE, password: PASSWORD, password_confirmation: PASSWORD });
    expect(client.getQueryData(FAMILY_ME_QUERY_KEY)).toEqual(account);
    expect(screen.getByRole("status")).toHaveTextContent("تم تغيير كلمة المرور");
    // Never the login endpoint, and the identifier only once.
    expect(post.mock.calls.map(([path]) => path)).toEqual([`${BASE}/start`, `${BASE}/verify`, `${BASE}/complete`]);
  });
});

describe("browser state", () => {
  it("keeps the whole reset in memory: nothing in storage, cookies or the URL", async () => {
    api({ "/start": START, "/verify": VERIFIED, "/complete": { user: familyUser() } });
    const writes = [vi.spyOn(window.localStorage, "setItem"), vi.spyOn(window.sessionStorage, "setItem")];
    const href = window.location.href;
    const user = userEvent.setup();
    renderWithClient(<PasswordResetFlow />);

    await toPasswordStep(user);
    await fillPasswords(user);
    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family"));

    for (const write of writes) expect(write).not.toHaveBeenCalled();
    const stored = browserStorageDump();
    for (const secret of [NATIONAL_ID, CHALLENGE, CODE, PASSWORD]) {
      expect(stored).not.toContain(secret);
    }
    expect(window.location.href).toBe(href);
    expect(router.push).not.toHaveBeenCalled();
  });

  it("starts over after a remount, as a refresh would", async () => {
    api({ "/start": START });
    const user = userEvent.setup();
    const first = renderWithClient(<PasswordResetFlow />);
    await toOtpStep(user);

    first.unmount();
    renderWithClient(<PasswordResetFlow />);

    expect(screen.getByRole("heading", { name: "استعادة كلمة المرور" })).toBeInTheDocument();
    expect(idField()).toHaveValue("");
  });
});
