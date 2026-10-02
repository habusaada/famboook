import { act, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ActivationFlow } from "@/components/family/activation/activation-flow";
import { FamilyLoginForm } from "@/components/family/auth/login-form";
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
});

describe("/family/login", () => {
  it("renders the approved form without asking the API who is signed in", () => {
    const get = vi.spyOn(apiClient, "get");
    renderWithClient(<FamilyLoginForm />);

    expect(screen.getByRole("heading", { level: 1, name: "تسجيل الدخول" })).toBeInTheDocument();
    expect(idField()).toHaveAttribute("type", "text");
    expect(idField()).toHaveAttribute("inputmode", "numeric");
    expect(idField()).toHaveAttribute("dir", "ltr");
    expect(passwordField()).toHaveAttribute("type", "password");
    expect(passwordField()).toHaveAttribute("autocomplete", "current-password");
    expect(get).not.toHaveBeenCalled();
  });

  it("links to the password reset and to activation", () => {
    renderWithClient(<FamilyLoginForm />);

    expect(screen.getByRole("link", { name: "نسيت كلمة المرور؟" })).toHaveAttribute("href", "/family/forgot-password");
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

    expect(await screen.findByRole("button", { name: /جارٍ تسجيل الدخول/ })).toBeDisabled();
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

describe("/family/activate", () => {
  it("now offers the login to someone who already has an account", () => {
    renderWithClient(<ActivationFlow />);

    expect(screen.getByText(/لديك حساب بالفعل؟/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "تسجيل الدخول" })).toHaveAttribute("href", "/family/login");
  });
});
