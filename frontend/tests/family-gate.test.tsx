import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyGate } from "@/components/family/family-gate";
import { FamilyHome } from "@/components/family/family-home";
import { ApiError, apiClient } from "@/lib/api/client";
import { familyUser, renderWithClient } from "./helpers";

const router = { replace: vi.fn(), push: vi.fn() };
vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

function renderPortal() {
  return renderWithClient(
    <FamilyGate>
      <FamilyHome />
    </FamilyGate>
  );
}

describe("FamilyGate", () => {
  beforeEach(() => {
    router.replace.mockReset();
  });

  it("asks only the Family API who is signed in", async () => {
    const get = vi.spyOn(apiClient, "get").mockResolvedValue({ user: familyUser() });

    renderPortal();

    await screen.findByText("سالم الاختبار");
    expect(get).toHaveBeenCalledTimes(1);
    expect(get).toHaveBeenCalledWith("/api/v1/family/me");
  });

  it("renders the shell and the home for a family user", async () => {
    vi.spyOn(apiClient, "get").mockResolvedValue({ user: familyUser() });

    renderPortal();

    expect(await screen.findByRole("heading", { level: 1, name: "سالم الاختبار" })).toBeInTheDocument();
    expect(screen.getByText("فرع الاختبار")).toBeInTheDocument();
    // Codes render left-to-right inside the RTL page.
    expect(screen.getByText("FAM-000123")).toHaveAttribute("dir", "ltr");
    expect(screen.getByText("حسابك مفعّل")).toBeInTheDocument();
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("shows the approved navigation with only the home enabled", async () => {
    vi.spyOn(apiClient, "get").mockResolvedValue({ user: familyUser() });

    renderPortal();

    const nav = await screen.findByRole("navigation", { name: "التنقل الرئيسي" });
    const home = within(nav).getByRole("link", { name: "الرئيسية" });
    expect(home).toHaveAttribute("href", "/family");
    expect(home).toHaveAttribute("aria-current", "page");
    // Nothing else is a link: no fake route or workflow.
    expect(within(nav).getAllByRole("link")).toHaveLength(1);
    for (const label of ["أسرتي", "طلباتي", "حسابي", "إجراء جديد (قريبًا)"]) {
      const item = within(nav).getByRole("button", { name: label });
      expect(item).toBeDisabled();
      expect(item).toHaveAttribute("aria-disabled", "true");
    }
  });

  it("sends a visitor without a session to the activation page", async () => {
    vi.spyOn(apiClient, "get").mockRejectedValue(new ApiError(401, { message: "Unauthenticated." }));

    renderPortal();

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/activate"));
    expect(screen.queryByRole("navigation")).not.toBeInTheDocument();
    expect(screen.queryByText("حسابك مفعّل")).not.toBeInTheDocument();
  });

  it("shows a neutral notice to an account that is not family-side", async () => {
    vi.spyOn(apiClient, "get").mockRejectedValue(new ApiError(403, { message: "forbidden" }));

    renderPortal();

    expect(await screen.findByRole("alert")).toHaveTextContent("هذا الحساب ليس حساب أسرة");
    expect(router.replace).not.toHaveBeenCalled();
    expect(screen.queryByRole("navigation")).not.toBeInTheDocument();
    expect(screen.queryByText("حسابك مفعّل")).not.toBeInTheDocument();
  });

  it("offers a retry when the server cannot be reached", async () => {
    const get = vi.spyOn(apiClient, "get").mockRejectedValueOnce(new ApiError(500, null)).mockResolvedValue({ user: familyUser() });

    renderPortal();
    await userEvent.click(await screen.findByRole("button", { name: "إعادة المحاولة" }));

    expect(await screen.findByText("حسابك مفعّل")).toBeInTheDocument();
    expect(get).toHaveBeenCalledTimes(2);
  });

  it("renders the access-unavailable state instead of the portal when there is no family context", async () => {
    vi.spyOn(apiClient, "get").mockResolvedValue({
      user: familyUser({ display_name: null, context: { available: false, family: null } }),
    });

    renderPortal();

    expect(await screen.findByRole("heading", { name: "الوصول غير متاح حاليًا" })).toBeInTheDocument();
    // No home, no family, no navigation — only the way out.
    expect(screen.queryByText("حسابك مفعّل")).not.toBeInTheDocument();
    expect(screen.queryByText("قريبًا في البوابة")).not.toBeInTheDocument();
    expect(screen.queryByRole("navigation")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "تسجيل الخروج" })).toBeEnabled();
  });

  it("keeps the family hidden even when a name is still known", async () => {
    vi.spyOn(apiClient, "get").mockResolvedValue({
      user: familyUser({ context: { available: false, family: null } }),
    });

    renderPortal();

    await screen.findByRole("heading", { name: "الوصول غير متاح حاليًا" });
    expect(screen.queryByText("سالم الاختبار")).not.toBeInTheDocument();
  });

  it("logs out through the Family API and returns to the entry page", async () => {
    vi.spyOn(apiClient, "get").mockResolvedValue({ user: familyUser() });
    const post = vi.spyOn(apiClient, "post").mockResolvedValue(null);

    renderPortal();
    await userEvent.click(await screen.findByRole("button", { name: "تسجيل الخروج" }));

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/activate"));
    expect(post).toHaveBeenCalledWith("/api/v1/family/auth/logout", {});
  });

  it("drops the local session even when the logout call fails", async () => {
    vi.spyOn(apiClient, "get").mockResolvedValue({ user: familyUser() });
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(500, null));

    renderPortal();
    await userEvent.click(await screen.findByRole("button", { name: "تسجيل الخروج" }));

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/activate"));
  });
});
