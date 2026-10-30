import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { LoginView } from "@/components/auth/login-view";
import { ApiError, apiClient } from "@/lib/api/client";
import { renderWithClient } from "./helpers";

// FB-UI-02: the Staff login uses the Family Portal's authentication field
// patterns (auth-parts, PasswordField) with its own heading, description and
// ShieldCheck. The login logic itself is unchanged. Synthetic data only.

const router = { replace: vi.fn(), push: vi.fn() };
const search = new URLSearchParams();
vi.mock("next/navigation", () => ({ useRouter: () => router, useSearchParams: () => search }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const STAFF = { name: "موظف تجريبي", email: "staff@example.test", role: "DATA_ENTRY", role_label: "مدخل بيانات", permissions: [] };

beforeEach(() => {
  vi.restoreAllMocks();
  router.replace.mockReset();
  // Not signed in.
  vi.spyOn(apiClient, "get").mockRejectedValue(new ApiError(401, { message: "Unauthenticated." }));
});

const email = () => screen.getByLabelText("البريد الإلكتروني");
const password = () => screen.getByLabelText("كلمة المرور");
const submit = () => screen.getByRole("button", { name: "تسجيل الدخول" });

describe("Staff login (FB-UI-02)", () => {
  it("shows the logo alone, «دخول الطاقم» with a ShieldCheck and the administrative description", () => {
    renderWithClient(<LoginView />);

    expect(document.querySelector("[data-brand-lockup]")?.textContent).toBe("");
    expect(screen.queryByText("بوابة الطاقم")).not.toBeInTheDocument();
    const heading = screen.getByRole("heading", { level: 1, name: "دخول الطاقم" });
    expect(heading.querySelector("[data-staff-heading-icon]")).toHaveAttribute("aria-hidden", "true");
    expect(screen.getByText("الوصول الإداري إلى نظام فامبوك")).toBeInTheDocument();
    // Distinguishable from the Family login.
    expect(screen.queryByText(/رقم الهوية|بوابة الأسرة/)).not.toBeInTheDocument();
    expect(document.querySelector("[data-staff-note]")).toHaveTextContent("الدخول مخصص للطاقم المصرّح لهم فقط.");
  });

  it("uses the Family field patterns: LTR email with a Mail icon, password with a lock icon, 48px fields", () => {
    renderWithClient(<LoginView />);

    expect(email()).toHaveAttribute("type", "email");
    expect(email()).toHaveAttribute("dir", "ltr");
    expect(email()).toHaveAttribute("autocomplete", "username");
    expect(email()).toHaveFocus();
    expect(email().parentElement?.querySelector('[data-field-icon="email"]')).toHaveAttribute("aria-hidden", "true");
    expect(password()).toHaveAttribute("type", "password");
    expect(password()).toHaveAttribute("dir", "ltr");
    expect(password()).toHaveAttribute("autocomplete", "current-password");
    expect(password().parentElement?.querySelector('[data-field-icon="lock"]')).not.toBeNull();
    // Only the portal's own show/hide control: the browser-native reveal is hidden.
    expect(password().className).toEqual(expect.stringContaining("[&::-ms-reveal]:hidden"));
    for (const field of [email(), password()]) {
      expect(field.className).toEqual(expect.stringContaining("h-12"));
      expect(field.className).toEqual(expect.stringContaining("rounded-xl"));
    }
    // The Family authentication geometry is applied to the form (its --radius), not to the Staff card.
    expect(email().closest("form")).toHaveAttribute("data-auth-geometry", "family");
    expect(email().closest("form")?.className).toEqual(expect.stringContaining("[--radius:0.75rem]"));
  });

  it("reveals and hides the typed password with an accessible toggle", async () => {
    const user = userEvent.setup();
    renderWithClient(<LoginView />);
    await user.type(password(), "secret-Pass1");

    const toggle = screen.getByRole("button", { name: "إظهار كلمة المرور" });
    expect(toggle).toHaveAttribute("aria-pressed", "false");
    await user.click(toggle);
    expect(password()).toHaveAttribute("type", "text");
    expect(screen.getByRole("button", { name: "إخفاء كلمة المرور" })).toHaveAttribute("aria-pressed", "true");
    expect(password()).toHaveValue("secret-Pass1");
    await user.click(screen.getByRole("button", { name: "إخفاء كلمة المرور" }));
    expect(password()).toHaveAttribute("type", "password");
  });

  it("marks empty and invalid fields, without calling the server", async () => {
    const user = userEvent.setup();
    const post = vi.spyOn(apiClient, "post");
    renderWithClient(<LoginView />);

    await user.click(submit());
    expect(await screen.findByText("البريد الإلكتروني مطلوب")).toBeInTheDocument();
    expect(screen.getByText("كلمة المرور مطلوبة")).toBeInTheDocument();
    expect(email()).toHaveAttribute("aria-invalid", "true");
    expect(email()).toHaveAttribute("aria-describedby", "login-email-error");
    expect(password()).toHaveAttribute("aria-invalid", "true");

    await user.type(email(), "not-an-email");
    await user.click(submit());
    expect(await screen.findByText("صيغة البريد الإلكتروني غير صحيحة")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it("shows the server's generic failure in the shared alert", async () => {
    const user = userEvent.setup();
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(422, { message: "x", errors: { email: ["بيانات الدخول غير صحيحة."] } }));
    renderWithClient(<LoginView />);

    await user.type(email(), "staff@example.test");
    await user.type(password(), "wrong-pass");
    await user.click(submit());

    expect(await screen.findByRole("alert")).toHaveTextContent("بيانات الدخول غير صحيحة.");
    expect(document.querySelector('[data-auth-alert="error"]')).not.toBeNull();
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("signs in with the same request as before and goes to the portal", async () => {
    const user = userEvent.setup();
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ user: STAFF } as never);
    renderWithClient(<LoginView />);

    await user.type(email(), "staff@example.test");
    await user.type(password(), "secret-Pass1");
    await user.click(submit());

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/dashboard"));
    expect(post).toHaveBeenCalledWith("/api/v1/auth/login", { email: "staff@example.test", password: "secret-Pass1" });
  });
});
