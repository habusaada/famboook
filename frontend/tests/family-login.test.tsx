import { act, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ActivationFlow } from "@/components/family/activation/activation-flow";
import { FamilyLoginForm } from "@/components/family/auth/login-form";
import { ApiError, apiClient } from "@/lib/api/client";
import { FAMILY_AUTH_CAPABILITIES_QUERY_KEY, FAMILY_ME_QUERY_KEY } from "@/lib/api/family-auth";
import {
  CAPABILITIES_PATH,
  browserStorageDump,
  familyAuthCapabilities,
  familyUser,
  mockFamilyAuthCapabilities,
  renderWithClient,
} from "./helpers";

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
const PASSWORD = "synthetic passphrase";
const LOGIN = "/api/v1/family/auth/login";

const idField = () => screen.getByLabelText("رقم الهوية");
const passwordField = () => screen.getByLabelText("كلمة المرور");
const submit = () => screen.getByRole("button", { name: "تسجيل الدخول" });

async function signIn(user: ReturnType<typeof userEvent.setup>, id = NATIONAL_ID, password = PASSWORD) {
  if (id) await user.type(idField(), id);
  if (password) await user.type(passwordField(), password);
  await user.click(submit());
}

beforeEach(() => {
  router.replace.mockReset();
  window.localStorage.clear();
  window.sessionStorage.clear();
  // As in Production today: login open, password reset closed (FU-14).
  mockFamilyAuthCapabilities(familyAuthCapabilities({ password_reset: false }));
});

const forgotLink = () => screen.queryByRole("link", { name: "نسيت كلمة المرور؟" });

describe("/family/login", () => {
  it("renders the approved form without asking the API who is signed in", () => {
    const get = mockFamilyAuthCapabilities(familyAuthCapabilities());
    renderWithClient(<FamilyLoginForm />);

    expect(screen.getByRole("heading", { level: 1, name: "تسجيل الدخول" })).toBeInTheDocument();
    expect(idField()).toHaveAttribute("type", "text");
    expect(idField()).toHaveAttribute("inputmode", "numeric");
    expect(idField()).toHaveAttribute("dir", "ltr");
    expect(passwordField()).toHaveAttribute("type", "password");
    expect(passwordField()).toHaveAttribute("autocomplete", "current-password");
    // Only the global capabilities are read — never /family/me.
    expect(get.mock.calls.map(([path]) => path)).toEqual([CAPABILITIES_PATH]);
  });

  it("shows the official logo above the portal name and the card", () => {
    const { container } = renderWithClient(<FamilyLoginForm />);

    expect(screen.getByRole("img", { name: "Famboook" })).toHaveAttribute("src", "/brand/famboook-logo.svg");
    expect(container.querySelector("[data-family-brand]")).toHaveTextContent("بوابة الأسرة");
    expect(container.querySelector("[data-family-brand] svg")).toBeNull();
  });

  it("links to the password reset and to activation", async () => {
    mockFamilyAuthCapabilities(familyAuthCapabilities());
    renderWithClient(<FamilyLoginForm />);

    expect(await screen.findByRole("link", { name: "نسيت كلمة المرور؟" })).toHaveAttribute("href", "/family/forgot-password");
    expect(screen.getByText(/ليس لديك حساب؟/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "تفعيل الحساب" })).toHaveAttribute("href", "/family/activate");
  });

  it("sends Arabic and Persian digits as nine ASCII digits", async () => {
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ user: familyUser() });
    const user = userEvent.setup();
    renderWithClient(<FamilyLoginForm />);

    await signIn(user, "١٢٣ ٤٥٦-۷۸۹");

    await waitFor(() => expect(post).toHaveBeenCalledWith(LOGIN, { national_id: NATIONAL_ID, password: PASSWORD }));
  });

  it.each([
    ["12345678", PASSWORD, "رقم الهوية يجب أن يتكون من 9 أرقام."],
    ["12345678a", PASSWORD, "رقم الهوية يجب أن يتكون من 9 أرقام."],
    ["", PASSWORD, "رقم الهوية مطلوب."],
    [NATIONAL_ID, "", "كلمة المرور مطلوبة."],
  ])("blocks the request for %j / %j", async (id, password, message) => {
    const post = vi.spyOn(apiClient, "post");
    const user = userEvent.setup();
    renderWithClient(<FamilyLoginForm />);

    await signIn(user, id, password);

    expect(await screen.findByRole("alert")).toHaveTextContent(message);
    expect(post).not.toHaveBeenCalled();
  });

  it("decorates the fields with icons that never take focus, clicks or text space", () => {
    const { container } = renderWithClient(<FamilyLoginForm />);
    const idIcon = container.querySelector('[data-field-icon="national-id"]')!;
    const lockIcon = container.querySelector('[data-field-icon="lock"]')!;

    for (const icon of [idIcon, lockIcon]) {
      expect(icon).toHaveAttribute("aria-hidden", "true");
      expect(icon).not.toHaveAttribute("tabindex");
      expect(icon).toHaveClass("pointer-events-none", "right-3.5");
    }
    // Physical padding: logical padding would flip inside an LTR / auto input.
    expect(idField()).toHaveAttribute("dir", "ltr");
    expect(idField()).toHaveClass("pr-11");
    expect(passwordField()).toHaveAttribute("dir", "auto");
    expect(passwordField()).toHaveClass("pr-11", "pl-12");
    // The show/hide control stays on the left and is the only extra control.
    expect(screen.getByRole("button", { name: "إظهار كلمة المرور" })).toHaveClass("left-1");
    expect(screen.getByRole("status")).toBeEmptyDOMElement();
  });

  it("keeps Arabic and mixed passwords exactly as typed", async () => {
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ user: familyUser() });
    const user = userEvent.setup();
    renderWithClient(<FamilyLoginForm />);

    await signIn(user, NATIONAL_ID, "كلمة سر Synthetic 12");

    await waitFor(() => expect(post).toHaveBeenCalledWith(LOGIN, { national_id: NATIONAL_ID, password: "كلمة سر Synthetic 12" }));
  });

  it("shows and hides the password", async () => {
    const user = userEvent.setup();
    renderWithClient(<FamilyLoginForm />);

    await user.click(screen.getByRole("button", { name: "إظهار كلمة المرور" }));
    expect(passwordField()).toHaveAttribute("type", "text");
    await user.click(screen.getByRole("button", { name: "إخفاء كلمة المرور" }));
    expect(passwordField()).toHaveAttribute("type", "password");
  });

  it("shows a loading state while signing in", async () => {
    let release: (value: unknown) => void = () => undefined;
    vi.spyOn(apiClient, "post").mockImplementation(() => new Promise((resolve) => (release = resolve)) as never);
    const user = userEvent.setup();
    renderWithClient(<FamilyLoginForm />);

    await signIn(user);

    const button = await screen.findByRole("button", { name: "جارٍ تسجيل الدخول..." });
    expect(button).toBeDisabled();
    expect(button).toHaveTextContent(/^جارٍ تسجيل الدخول\.\.\.$/);
    // The spinner is decorative; the wait is announced in a status region.
    expect(button.querySelector("svg")).toHaveAttribute("aria-hidden", "true");
    expect(screen.getByRole("status")).toHaveTextContent("جارٍ تسجيل الدخول...");
    // A second press sends nothing more.
    await user.click(button);
    expect(apiClient.post).toHaveBeenCalledTimes(1);
    await act(async () => release({ user: familyUser() }));
    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family"));
  });

  it("shows ONE generic sentence for a credential failure and clears the password", async () => {
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(401, { message: "…", code: "INVALID_CREDENTIALS" }));
    const user = userEvent.setup();
    renderWithClient(<FamilyLoginForm />);

    await signIn(user);

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("رقم الهوية أو كلمة المرور غير صحيحة.");
    // Nothing that would say which half was wrong, or that an account exists.
    expect(alert.textContent).not.toMatch(/غير موجود|غير مفعّل|موقوف|الحساب/);
    expect(idField()).not.toHaveAttribute("aria-invalid");
    expect(passwordField()).not.toHaveAttribute("aria-invalid");
    expect(passwordField()).toHaveValue("");
    expect(idField()).toHaveValue(NATIONAL_ID);
    expect(router.replace).not.toHaveBeenCalled();
  });

  it.each([
    [429, "TOO_MANY_REQUESTS", "محاولات كثيرة. حاول مجددًا بعد قليل."],
    [503, "FAMILY_AUTH_UNAVAILABLE", "تسجيل الدخول غير متاح حاليًا."],
  ])("maps %i %s to its message", async (status, code, message) => {
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(status, { message: "…", code }));
    const user = userEvent.setup();
    renderWithClient(<FamilyLoginForm />);

    await signIn(user);

    expect(await screen.findByRole("alert")).toHaveTextContent(message);
  });

  it("shows the server's field error under the field", async () => {
    vi.spyOn(apiClient, "post").mockRejectedValue(
      new ApiError(422, { message: "…", errors: { national_id: ["رقم الهوية يجب أن يتكون من 9 أرقام."] } })
    );
    const user = userEvent.setup();
    renderWithClient(<FamilyLoginForm />);

    await signIn(user);

    expect(await screen.findByRole("alert")).toHaveTextContent("رقم الهوية يجب أن يتكون من 9 أرقام.");
    expect(idField()).toHaveAttribute("aria-invalid", "true");
  });

  it("reports a connection failure without guessing", async () => {
    vi.spyOn(apiClient, "post").mockRejectedValue(new TypeError("Failed to fetch"));
    const user = userEvent.setup();
    renderWithClient(<FamilyLoginForm />);

    await signIn(user);

    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر الاتصال بالخادم.");
  });

  it("seeds the family session and goes to /family on success", async () => {
    const account = familyUser();
    vi.spyOn(apiClient, "post").mockResolvedValue({ user: account });
    const user = userEvent.setup();
    const { client } = renderWithClient(<FamilyLoginForm />);
    client.setQueryData(["auth", "me"], { name: "a previous staff session" });

    await signIn(user);

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family"));
    expect(client.getQueryData(FAMILY_ME_QUERY_KEY)).toEqual(account);
    // Nothing cached for a previous session survives.
    expect(client.getQueryData(["auth", "me"])).toBeUndefined();
  });

  it("writes nothing to browser storage or the URL", async () => {
    vi.spyOn(apiClient, "post").mockResolvedValue({ user: familyUser() });
    const writes = [vi.spyOn(window.localStorage, "setItem"), vi.spyOn(window.sessionStorage, "setItem")];
    const href = window.location.href;
    const user = userEvent.setup();
    renderWithClient(<FamilyLoginForm />);

    await signIn(user);
    await waitFor(() => expect(router.replace).toHaveBeenCalled());

    for (const write of writes) expect(write).not.toHaveBeenCalled();
    const stored = browserStorageDump();
    expect(stored).not.toContain(NATIONAL_ID);
    expect(stored).not.toContain(PASSWORD);
    expect(window.location.href).toBe(href);
  });
});

describe("/family/login — the forgot-password link fails closed (FU-14)", () => {
  async function settled(client: ReturnType<typeof renderWithClient>["client"], status: "success" | "error") {
    await waitFor(() => expect(client.getQueryState(FAMILY_AUTH_CAPABILITIES_QUERY_KEY)?.status).toBe(status));
  }

  it("is hidden while the capabilities load", () => {
    mockFamilyAuthCapabilities("pending");
    renderWithClient(<FamilyLoginForm />);

    expect(forgotLink()).not.toBeInTheDocument();
    expect(idField()).toBeEnabled();
    expect(submit()).toBeEnabled();
  });

  it.each([
    ["a server error", new ApiError(500, null)],
    ["no connection", new TypeError("Failed to fetch")],
  ])("is hidden after %s", async (_, error) => {
    mockFamilyAuthCapabilities(error);
    const { client } = renderWithClient(<FamilyLoginForm />);

    await settled(client, "error");
    expect(forgotLink()).not.toBeInTheDocument();
  });

  it("is hidden when password_reset=false", async () => {
    mockFamilyAuthCapabilities(familyAuthCapabilities({ password_reset: false }));
    const { client } = renderWithClient(<FamilyLoginForm />);

    await settled(client, "success");
    expect(forgotLink()).not.toBeInTheDocument();
  });

  it("is shown when password_reset=true", async () => {
    mockFamilyAuthCapabilities(familyAuthCapabilities({ password_reset: true }));
    renderWithClient(<FamilyLoginForm />);

    expect(await screen.findByRole("link", { name: "نسيت كلمة المرور؟" })).toHaveAttribute("href", "/family/forgot-password");
  });

  it("keeps the activation link whatever the capabilities say", async () => {
    mockFamilyAuthCapabilities(new ApiError(500, null));
    const { client } = renderWithClient(<FamilyLoginForm />);

    await settled(client, "error");
    expect(screen.getByRole("link", { name: "تفعيل الحساب" })).toHaveAttribute("href", "/family/activate");
  });

  it("still signs in when the capabilities request fails", async () => {
    mockFamilyAuthCapabilities(new ApiError(500, null));
    const account = familyUser();
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ user: account });
    const user = userEvent.setup();
    const { client } = renderWithClient(<FamilyLoginForm />);
    await settled(client, "error");

    await signIn(user);

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family"));
    expect(post).toHaveBeenCalledWith(LOGIN, { national_id: NATIONAL_ID, password: PASSWORD });
    expect(client.getQueryData(FAMILY_ME_QUERY_KEY)).toEqual(account);
  });
});

describe("/family/activate", () => {
  it("now offers the login to someone who already has an account", () => {
    renderWithClient(<ActivationFlow />);

    expect(screen.getByText(/لديك حساب بالفعل؟/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "تسجيل الدخول" })).toHaveAttribute("href", "/family/login");
  });
});
