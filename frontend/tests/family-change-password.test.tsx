import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ChangePassword } from "@/components/family/account/change-password";
import { ApiError, apiClient } from "@/lib/api/client";
import { browserStorageDump, familyAuthCapabilities, familyUser, mockFamilyAuthCapabilities, renderWithClient } from "./helpers";

// FU-16 (docs/11 FP-ADR-078): «تغيير كلمة المرور» on
// POST /api/v1/family/account/password. Offered only on an explicit server
// `true`; the server is the authority. Synthetic data only.

vi.mock("next/navigation", () => ({ useRouter: () => ({ replace: vi.fn(), push: vi.fn() }), usePathname: () => "/family/account/password" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const CURRENT = "old synthetic phrase";
const NEW = "a new synthetic passphrase";

beforeEach(() => {
  vi.restoreAllMocks();
  window.localStorage.clear();
  window.sessionStorage.clear();
});

async function renderOpen() {
  mockFamilyAuthCapabilities(familyAuthCapabilities({ password_change: true }));
  const user = userEvent.setup();
  renderWithClient(<ChangePassword />);
  await screen.findByRole("button", { name: "تغيير كلمة المرور" });
  return user;
}

async function fill(user: ReturnType<typeof userEvent.setup>, current: string, next: string, confirmation = next) {
  if (current) await user.type(screen.getByLabelText("كلمة المرور الحالية"), current);
  if (next) await user.type(screen.getByLabelText("كلمة المرور الجديدة"), next);
  if (confirmation) await user.type(screen.getByLabelText("تأكيد كلمة المرور الجديدة"), confirmation);
  await user.click(screen.getByRole("button", { name: "تغيير كلمة المرور" }));
}

describe("availability", () => {
  it("shows no form while the server reports the surface closed or cannot answer", async () => {
    mockFamilyAuthCapabilities(familyAuthCapabilities({ password_change: false }));
    renderWithClient(<ChangePassword />);
    expect(await screen.findByRole("heading", { name: "تغيير كلمة المرور غير متاح حاليًا" })).toBeInTheDocument();
    expect(screen.queryByLabelText("كلمة المرور الحالية")).not.toBeInTheDocument();
  });

  it("fails closed on a capabilities error", async () => {
    mockFamilyAuthCapabilities(new ApiError(500, { message: "x" }));
    renderWithClient(<ChangePassword />);
    expect(await screen.findByRole("heading", { name: "تغيير كلمة المرور غير متاح حاليًا" })).toBeInTheDocument();
  });
});

describe("the form", () => {
  it("requires the current password, a new password under the policy and a matching confirmation", async () => {
    const user = await renderOpen();
    const post = vi.spyOn(apiClient, "post");

    await user.click(screen.getByRole("button", { name: "تغيير كلمة المرور" }));
    expect(await screen.findByText("كلمة المرور الحالية مطلوبة.")).toBeInTheDocument();
    expect(screen.getByText("كلمة المرور الجديدة مطلوبة.")).toBeInTheDocument();

    await fill(user, CURRENT, "short");
    expect(await screen.findByText("كلمة المرور يجب ألا تقل عن 8 أحرف.")).toBeInTheDocument();

    await user.clear(screen.getByLabelText("كلمة المرور الجديدة"));
    await user.clear(screen.getByLabelText("تأكيد كلمة المرور الجديدة"));
    await fill(user, "", NEW, "a different synthetic phrase");
    expect(await screen.findByText("كلمتا المرور غير متطابقتين.")).toBeInTheDocument();

    // 37 Arabic letters = 74 bytes: refused, never truncated.
    await user.clear(screen.getByLabelText("كلمة المرور الجديدة"));
    await user.clear(screen.getByLabelText("تأكيد كلمة المرور الجديدة"));
    await fill(user, "", "ب".repeat(37));
    expect(await screen.findByText("كلمة المرور طويلة جدًا. يرجى استخدام كلمة مرور أقصر.")).toBeInTheDocument();

    expect(post).not.toHaveBeenCalled();
  });

  it("refuses a new password identical to the current one without calling the server", async () => {
    const user = await renderOpen();
    const post = vi.spyOn(apiClient, "post");

    await fill(user, NEW, NEW);
    expect(await screen.findByText("كلمة المرور الجديدة مطابقة لكلمة المرور الحالية. اختر كلمة مرور مختلفة.")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it("sends the three values once, confirms success and clears the fields", async () => {
    const user = await renderOpen();
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ user: familyUser() });

    await fill(user, CURRENT, NEW);

    expect(await screen.findByText(/تم تغيير كلمة المرور/)).toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(1);
    expect(post.mock.calls[0]).toEqual(["/api/v1/family/account/password", { current_password: CURRENT, password: NEW, password_confirmation: NEW }]);
    expect(screen.getByLabelText("كلمة المرور الحالية")).toHaveValue("");
    expect(screen.getByLabelText("كلمة المرور الجديدة")).toHaveValue("");
    expect(screen.getByLabelText("تأكيد كلمة المرور الجديدة")).toHaveValue("");
    expect(browserStorageDump()).not.toMatch(/synthetic/);
  });

  it("shows the server's field error for a wrong current password", async () => {
    const user = await renderOpen();
    vi.spyOn(apiClient, "post").mockRejectedValue(
      new ApiError(422, { message: "x", errors: { current_password: ["كلمة المرور الحالية غير صحيحة."] } })
    );

    await fill(user, "a wrong synthetic phrase", NEW);

    expect(await screen.findByText("كلمة المرور الحالية غير صحيحة.")).toBeInTheDocument();
    expect(screen.getByLabelText("كلمة المرور الحالية")).toHaveAttribute("aria-invalid", "true");
    expect(screen.queryByText(/تم تغيير كلمة المرور/)).not.toBeInTheDocument();
  });

  it("explains the rate limit and an unavailable surface", async () => {
    const user = await renderOpen();
    const post = vi
      .spyOn(apiClient, "post")
      .mockRejectedValueOnce(new ApiError(429, { message: "x", code: "TOO_MANY_REQUESTS" }))
      .mockRejectedValueOnce(new ApiError(503, { message: "x", code: "PASSWORD_CHANGE_UNAVAILABLE" }));

    await fill(user, CURRENT, NEW);
    expect(await screen.findByText("محاولات كثيرة. حاول مجددًا بعد قليل.")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "تغيير كلمة المرور" }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
    expect(await screen.findByText("تغيير كلمة المرور غير متاح حاليًا.")).toBeInTheDocument();
    expect(browserStorageDump()).not.toMatch(/synthetic/);
  });
});
