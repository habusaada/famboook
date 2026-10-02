import { act, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ActivationFlow } from "@/components/family/activation/activation-flow";
import { ApiError, apiClient } from "@/lib/api/client";
import { FAMILY_ME_QUERY_KEY } from "@/lib/api/family-auth";
import { normalizeNationalId, normalizeOtp, passwordByteLength } from "@/lib/schemas/family-auth";
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
const PASSWORD = "synthetic passphrase";
const BASE = "/api/v1/family/auth/activation";
const START = { challenge: CHALLENGE, resend_after_seconds: 60, expires_in_seconds: 300, can_resend: true };

type Reply = unknown | (() => unknown);

/** Routes apiClient.post by path; an ApiError reply is thrown. */
function api(replies: Record<string, Reply | Reply[]>) {
  const queues = new Map(Object.entries(replies).map(([path, reply]) => [path, Array.isArray(reply) ? [...reply] : [reply]]));

  return vi.spyOn(apiClient, "post").mockImplementation(async (path: string) => {
    const queue = queues.get(path.replace(BASE, ""));
    if (!queue || queue.length === 0) throw new Error(`Unexpected request: ${path}`);
    const next = queue.length > 1 ? queue.shift() : queue[0];
    const reply = typeof next === "function" ? (next as () => unknown)() : next;
    if (reply instanceof ApiError) throw reply;
    return reply as never;
  });
}

const refusal = (status: number, code: string, extra: object = {}) => new ApiError(status, { message: "…", code, ...extra });

const idField = () => screen.getByLabelText("رقم الهوية");
const otpField = () => screen.getByLabelText(/رمز التحقق المكوّن من/);
const submitId = () => screen.getByRole("button", { name: "متابعة وتفعيل الحساب" });

async function toOtpStep(user: ReturnType<typeof userEvent.setup>) {
  await user.type(idField(), NATIONAL_ID);
  await user.click(submitId());
  await screen.findByRole("heading", { name: "أدخل رمز التحقق" });
}

async function toPasswordStep(user: ReturnType<typeof userEvent.setup>) {
  await toOtpStep(user);
  await user.type(otpField(), CODE);
  await user.click(screen.getByRole("button", { name: "تحقق" }));
  await screen.findByRole("heading", { name: "إنشاء كلمة المرور" });
}

async function fillPasswords(user: ReturnType<typeof userEvent.setup>, password = PASSWORD, confirmation = password) {
  await user.type(screen.getByLabelText("كلمة المرور"), password);
  await user.type(screen.getByLabelText("تأكيد كلمة المرور"), confirmation);
  await user.click(screen.getByRole("button", { name: "تفعيل الحساب" }));
}

beforeEach(() => {
  router.replace.mockReset();
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("normalizers", () => {
  it("turns Arabic and Persian digits into ASCII and drops separators", () => {
    expect(normalizeNationalId("١٢٣٤٥٦٧٨٩")).toBe("123456789");
    expect(normalizeNationalId("۱۲۳۴۵۶۷۸۹")).toBe("123456789");
    expect(normalizeNationalId(" 123-456 789‏")).toBe("123456789");
    // Letters are kept, so the nine-digit check rejects them.
    expect(normalizeNationalId("12345678a")).toBe("12345678a");
  });

  it("keeps only the six digits of a code", () => {
    expect(normalizeOtp("٤٨٢ ٩١٥")).toBe("482915");
    expect(normalizeOtp("code: 482915 thanks")).toBe("482915");
    expect(normalizeOtp("4829157777")).toBe("482915");
  });

  it("measures a password in bytes of UTF-8, as bcrypt does", () => {
    expect(passwordByteLength("a".repeat(72))).toBe(72);
    expect(passwordByteLength("ك".repeat(36))).toBe(72);
    expect(passwordByteLength("ك".repeat(37))).toBe(74);
  });
});

describe("step 1 — National ID", () => {
  it("is an accessible, numeric-friendly text field with the approved copy and the login link", () => {
    renderWithClient(<ActivationFlow />);

    expect(screen.getByRole("heading", { level: 1, name: "مرحبًا بك في فامبوك" })).toBeInTheDocument();
    expect(screen.getByText("فعّل حساب أسرتك للوصول إلى بيانات الأسرة وخدماتها الرقمية.")).toBeInTheDocument();
    expect(idField()).toHaveAttribute("type", "text");
    expect(idField()).toHaveAttribute("inputmode", "numeric");
    expect(idField()).toHaveAttribute("dir", "ltr");
    // The only link: to the Family login, for someone already activated.
    expect(screen.getAllByRole("link")).toHaveLength(1);
    expect(screen.getByRole("link", { name: "تسجيل الدخول" })).toHaveAttribute("href", "/family/login");
  });

  it("sends Arabic digits as nine ASCII digits", async () => {
    const post = api({ "/start": START });
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);

    await user.type(idField(), "١٢٣ ٤٥٦-۷۸۹");
    await user.click(submitId());

    await screen.findByRole("heading", { name: "أدخل رمز التحقق" });
    expect(post).toHaveBeenCalledWith(`${BASE}/start`, { national_id: NATIONAL_ID });
  });

  it.each(["", "12345678", "1234567890", "12345678a"])("blocks the request for a malformed identifier %j", async (value) => {
    const post = api({});
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);

    if (value) await user.type(idField(), value);
    await user.click(submitId());

    expect(await screen.findByRole("alert")).toBeInTheDocument();
    expect(idField()).toHaveAttribute("aria-invalid", "true");
    expect(idField()).toHaveAccessibleDescription(/رقم الهوية/);
    expect(post).not.toHaveBeenCalled();
  });

  it("shows a loading state while the request is in flight", async () => {
    let release: (value: unknown) => void = () => undefined;
    api({ "/start": () => new Promise((resolve) => (release = resolve)) });
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);

    await user.type(idField(), NATIONAL_ID);
    await user.click(submitId());

    const busy = await screen.findByRole("button", { name: /جارٍ المتابعة/ });
    expect(busy).toBeDisabled();

    await act(async () => release(START));
    await screen.findByRole("heading", { name: "أدخل رمز التحقق" });
  });

  it("shows the server's field error and stays on the step", async () => {
    api({ "/start": new ApiError(422, { message: "…", errors: { national_id: ["رقم الهوية يجب أن يتكون من 9 أرقام."] } }) });
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);

    await user.type(idField(), NATIONAL_ID);
    await user.click(submitId());

    expect(await screen.findByRole("alert")).toHaveTextContent("رقم الهوية يجب أن يتكون من 9 أرقام.");
    expect(screen.queryByRole("heading", { name: "أدخل رمز التحقق" })).not.toBeInTheDocument();
  });

  it.each([
    [503, "ACTIVATION_UNAVAILABLE", "الخدمة غير متاحة حاليًا."],
    [429, "TOO_MANY_REQUESTS", "محاولات كثيرة. حاول مجددًا بعد قليل."],
  ])("maps %i %s to its message", async (status, code, message) => {
    api({ "/start": refusal(status, code) });
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);

    await user.type(idField(), NATIONAL_ID);
    await user.click(submitId());

    expect(await screen.findByRole("alert")).toHaveTextContent(message);
  });
});

describe("step 2 — the code", () => {
  it("moves to the code step with generic copy and no destination", async () => {
    api({ "/start": START });
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);

    await toOtpStep(user);

    expect(
      screen.getByText("إذا كانت البيانات مطابقة لسجلاتنا، أرسلنا رمز تحقق إلى رقم الجوال الموثّق المسجّل لدينا.")
    ).toBeInTheDocument();
    // No part of any mobile number, masked or not, and not the identifier.
    expect(document.body.textContent).not.toMatch(/05\d|\*{2,}|\d{2}\*+/);
    expect(document.body.textContent).not.toContain(NATIONAL_ID);
    expect(screen.getByText("الخطوة 2 من 3")).toBeInTheDocument();
  });

  it("uses one accessible input behind six slots", async () => {
    api({ "/start": START });
    const user = userEvent.setup();
    const { container } = renderWithClient(<ActivationFlow />);

    await toOtpStep(user);

    expect(container.querySelectorAll("input")).toHaveLength(1);
    expect(container.querySelectorAll("[data-otp-slot]")).toHaveLength(6);
    expect(otpField()).toHaveAttribute("inputmode", "numeric");
    expect(otpField()).toHaveAttribute("autocomplete", "one-time-code");
    expect(otpField()).toHaveAttribute("dir", "ltr");
    expect(otpField()).not.toHaveAttribute("maxlength");
    expect(screen.getByRole("button", { name: "تحقق" })).toBeDisabled();
  });

  it("accepts a pasted code with spaces and Arabic digits", async () => {
    const post = api({ "/start": START, "/verify": { verified: true, grant_expires_in_seconds: 600 } });
    const user = userEvent.setup();
    const { container } = renderWithClient(<ActivationFlow />);
    await toOtpStep(user);

    await user.click(otpField());
    await user.paste("٤٨٢ ٩١٥");

    expect(otpField()).toHaveValue(CODE);
    expect(Array.from(container.querySelectorAll("[data-otp-slot]"), (slot) => slot.textContent).join("")).toBe(CODE);

    await user.click(screen.getByRole("button", { name: "تحقق" }));
    await screen.findByRole("heading", { name: "إنشاء كلمة المرور" });
    expect(post).toHaveBeenCalledWith(`${BASE}/verify`, { challenge: CHALLENGE, code: CODE });
  });

  it.each([
    [422, "OTP_INVALID", "رمز التحقق غير صحيح."],
    [410, "OTP_EXPIRED", "انتهت صلاحية رمز التحقق. اطلب رمزًا جديدًا أو ابدأ من جديد."],
    [429, "TOO_MANY_REQUESTS", "محاولات كثيرة. حاول مجددًا بعد قليل."],
  ])("maps %i %s, clears the code and lets the user try again", async (status, code, message) => {
    api({ "/start": START, "/verify": refusal(status, code) });
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);
    await toOtpStep(user);

    await user.type(otpField(), CODE);
    await user.click(screen.getByRole("button", { name: "تحقق" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(message);
    expect(otpField()).toHaveValue("");
    expect(otpField()).toBeEnabled();
  });

  it("locks the step after OTP_LOCKED and offers only a restart", async () => {
    api({ "/start": START, "/verify": refusal(423, "OTP_LOCKED") });
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);
    await toOtpStep(user);

    await user.type(otpField(), CODE);
    await user.click(screen.getByRole("button", { name: "تحقق" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("تم إيقاف هذا الرمز. ابدأ من جديد.");
    expect(otpField()).toBeDisabled();
    expect(screen.getByRole("button", { name: "تحقق" })).toBeDisabled();
    expect(screen.queryByRole("button", { name: "إعادة إرسال الرمز" })).not.toBeInTheDocument();
    expect(screen.queryByText(/إعادة الإرسال بعد/)).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "البدء من جديد" }));
    expect(screen.getByRole("heading", { name: "مرحبًا بك في فامبوك" })).toBeInTheDocument();
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

    it("counts down from the server's value before offering a resend", async () => {
      api({ "/start": { ...START, resend_after_seconds: 45 } });
      const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
      renderWithClient(<ActivationFlow />);
      await toOtpStep(user);

      expect(screen.getByText(/إعادة الإرسال بعد/)).toHaveTextContent("إعادة الإرسال بعد 45 ثانية");
      expect(screen.queryByRole("button", { name: "إعادة إرسال الرمز" })).not.toBeInTheDocument();

      await tick(15);
      expect(screen.getByText(/إعادة الإرسال بعد/)).toHaveTextContent("إعادة الإرسال بعد 30 ثانية");

      await tick(30);
      expect(screen.queryByText(/إعادة الإرسال بعد/)).not.toBeInTheDocument();
      expect(screen.getByRole("button", { name: "إعادة إرسال الرمز" })).toBeEnabled();
    });

    it("requests a new code, restarts the countdown and hides resend when none is left", async () => {
      const post = api({
        "/start": START,
        "/resend": { resend_after_seconds: 60, expires_in_seconds: 300, can_resend: false },
      });
      const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
      renderWithClient(<ActivationFlow />);
      await toOtpStep(user);
      await tick(60);

      await user.click(screen.getByRole("button", { name: "إعادة إرسال الرمز" }));

      expect(await screen.findByRole("status")).toHaveTextContent("تم طلب رمز جديد");
      expect(post).toHaveBeenCalledWith(`${BASE}/resend`, { challenge: CHALLENGE });
      expect(screen.getByText("لا يمكن طلب رمز آخر لهذه المحاولة.")).toBeInTheDocument();
      await tick(120);
      expect(screen.queryByRole("button", { name: "إعادة إرسال الرمز" })).not.toBeInTheDocument();
    });

    it("follows the server's cooldown when a resend comes too early", async () => {
      api({ "/start": START, "/resend": refusal(429, "OTP_COOLDOWN", { retry_after_seconds: 25 }) });
      const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
      renderWithClient(<ActivationFlow />);
      await toOtpStep(user);
      await tick(60);

      await user.click(screen.getByRole("button", { name: "إعادة إرسال الرمز" }));

      expect(await screen.findByRole("alert")).toHaveTextContent("يمكن طلب رمز جديد بعد قليل.");
      expect(screen.getByText(/إعادة الإرسال بعد/)).toHaveTextContent("إعادة الإرسال بعد 25 ثانية");
    });

    it("stops offering a resend after OTP_SEND_LIMIT", async () => {
      api({ "/start": START, "/resend": refusal(429, "OTP_SEND_LIMIT") });
      const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
      renderWithClient(<ActivationFlow />);
      await toOtpStep(user);
      await tick(60);

      await user.click(screen.getByRole("button", { name: "إعادة إرسال الرمز" }));

      expect(await screen.findByRole("alert")).toHaveTextContent("لا يمكن إرسال رمز آخر الآن.");
      expect(screen.queryByRole("button", { name: "إعادة إرسال الرمز" })).not.toBeInTheDocument();
    });
  });
});

describe("step 3 — the password", () => {
  const VERIFIED = { verified: true, grant_expires_in_seconds: 600 };

  it("shows only the rules the server enforces", async () => {
    api({ "/start": START, "/verify": VERIFIED });
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);
    await toPasswordStep(user);

    expect(screen.getByText(/لا تقل عن 8 أحرف/)).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/حرف كبير|رمز خاص|أرقام وحروف/);
    expect(screen.getByLabelText("كلمة المرور")).toHaveAttribute("autocomplete", "new-password");
  });

  it("requires eight characters and blocks the request otherwise", async () => {
    const post = api({ "/start": START, "/verify": VERIFIED });
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);
    await toPasswordStep(user);

    await fillPasswords(user, "short12");

    expect(await screen.findByRole("alert")).toHaveTextContent("كلمة المرور يجب ألا تقل عن 8 أحرف.");
    expect(screen.getByLabelText("كلمة المرور")).toHaveAttribute("aria-invalid", "true");
    expect(post).not.toHaveBeenCalledWith(`${BASE}/complete`, expect.anything());
  });

  it("refuses a password beyond the bcrypt input limit with a clear message and no byte arithmetic", async () => {
    const post = api({ "/start": START, "/verify": VERIFIED, "/complete": { user: familyUser() } });
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);
    await toPasswordStep(user);

    // 37 Arabic letters: only 37 characters, but 74 bytes of UTF-8.
    const tooLong = "ك".repeat(37);
    await fillPasswords(user, tooLong);

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("كلمة المرور طويلة جدًا. يرجى استخدام كلمة مرور أقصر.");
    expect(alert.textContent).not.toMatch(/72|بايت|byte/i);
    expect(post).not.toHaveBeenCalledWith(`${BASE}/complete`, expect.anything());

    // 36 Arabic letters are exactly 72 bytes: accepted and sent whole.
    const longest = "ك".repeat(36);
    await user.clear(screen.getByLabelText("كلمة المرور"));
    await user.clear(screen.getByLabelText("تأكيد كلمة المرور"));
    await fillPasswords(user, longest);

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family"));
    expect(post).toHaveBeenCalledWith(`${BASE}/complete`, { challenge: CHALLENGE, password: longest, password_confirmation: longest });
  });

  it("requires the confirmation to match", async () => {
    const post = api({ "/start": START, "/verify": VERIFIED });
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);
    await toPasswordStep(user);

    await fillPasswords(user, PASSWORD, "another passphrase");

    expect(await screen.findByRole("alert")).toHaveTextContent("كلمتا المرور غير متطابقتين.");
    expect(post).not.toHaveBeenCalledWith(`${BASE}/complete`, expect.anything());
  });

  it("shows and hides both passwords", async () => {
    api({ "/start": START, "/verify": VERIFIED });
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);
    await toPasswordStep(user);

    const password = screen.getByLabelText("كلمة المرور");
    const confirmation = screen.getByLabelText("تأكيد كلمة المرور");
    expect(password).toHaveAttribute("type", "password");

    await user.click(screen.getByRole("button", { name: "إظهار كلمة المرور" }));
    expect(password).toHaveAttribute("type", "text");
    expect(confirmation).toHaveAttribute("type", "text");

    await user.click(screen.getByRole("button", { name: "إخفاء كلمة المرور" }));
    expect(password).toHaveAttribute("type", "password");
    expect(confirmation).toHaveAttribute("type", "password");
  });

  it.each([
    [410, "GRANT_EXPIRED", "انتهت مهلة إنشاء كلمة المرور. ابدأ من جديد."],
    [409, "ACTIVATION_FAILED", "تعذّر إكمال التفعيل. ابدأ من جديد أو راجع الإدارة."],
    [423, "OTP_LOCKED", "تم إيقاف هذا الرمز. ابدأ من جديد."],
  ])("returns to the start on %i %s with the reason", async (status, code, message) => {
    api({ "/start": START, "/verify": VERIFIED, "/complete": refusal(status, code) });
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);
    await toPasswordStep(user);

    await fillPasswords(user);

    expect(await screen.findByRole("heading", { name: "مرحبًا بك في فامبوك" })).toBeInTheDocument();
    expect(screen.getByRole("status")).toHaveTextContent(message);
    expect(idField()).toHaveValue("");
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("shows the server's password error and stays on the step", async () => {
    api({
      "/start": START,
      "/verify": VERIFIED,
      "/complete": new ApiError(422, { message: "…", errors: { password: ["كلمة المرور طويلة جدًا."] } }),
    });
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);
    await toPasswordStep(user);

    await fillPasswords(user);

    expect(await screen.findByRole("alert")).toHaveTextContent("كلمة المرور طويلة جدًا.");
    expect(screen.getByRole("heading", { name: "إنشاء كلمة المرور" })).toBeInTheDocument();
  });

  it("completes, seeds the family session and goes to /family", async () => {
    const account = familyUser();
    const post = api({ "/start": START, "/verify": VERIFIED, "/complete": { user: account } });
    const user = userEvent.setup();
    const { client } = renderWithClient(<ActivationFlow />);
    await toPasswordStep(user);

    await fillPasswords(user);

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family"));
    expect(post).toHaveBeenCalledWith(`${BASE}/complete`, {
      challenge: CHALLENGE,
      password: PASSWORD,
      password_confirmation: PASSWORD,
    });
    expect(client.getQueryData(FAMILY_ME_QUERY_KEY)).toEqual(account);
    expect(screen.getByRole("status")).toHaveTextContent("تم تفعيل الحساب");
    // The National ID was sent once, at the start, and never again.
    const later = post.mock.calls.filter(([path]) => path !== `${BASE}/start`);
    expect(JSON.stringify(later)).not.toContain(NATIONAL_ID);
  });
});

describe("browser state", () => {
  it("keeps the whole activation in memory: nothing in storage, cookies or the URL", async () => {
    api({ "/start": START, "/verify": { verified: true, grant_expires_in_seconds: 600 }, "/complete": { user: familyUser() } });
    const writes = [vi.spyOn(window.localStorage, "setItem"), vi.spyOn(window.sessionStorage, "setItem")];
    const href = window.location.href;
    const user = userEvent.setup();
    renderWithClient(<ActivationFlow />);

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
    expect(router.replace).toHaveBeenCalledTimes(1);
  });

  it("starts over after a remount, as a refresh would", async () => {
    api({ "/start": START });
    const user = userEvent.setup();
    const first = renderWithClient(<ActivationFlow />);
    await toOtpStep(user);

    first.unmount();
    renderWithClient(<ActivationFlow />);

    expect(screen.getByRole("heading", { name: "مرحبًا بك في فامبوك" })).toBeInTheDocument();
    expect(idField()).toHaveValue("");
  });
});
