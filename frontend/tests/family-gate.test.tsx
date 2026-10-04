import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyGate } from "@/components/family/family-gate";
import { FamilyHome } from "@/components/family/family-home";
import { ApiError, apiClient } from "@/lib/api/client";
import { familyGet, familyUser, renderWithClient } from "./helpers";

const router = { replace: vi.fn(), push: vi.fn() };
const pathname = "/family";
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => pathname }));
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
    const get = vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderPortal();

    await screen.findByText("سالم الاختبار");
    // The session first, once; the home then reads its household — never a Staff endpoint.
    expect(get.mock.calls[0]).toEqual(["/api/v1/family/me"]);
    expect(get.mock.calls.filter(([path]) => path === "/api/v1/family/me")).toHaveLength(1);
    expect(get.mock.calls.every(([path]) => String(path).startsWith("/api/v1/family/"))).toBe(true);
  });

  it("shows the official logo file, not a composed wordmark or icon", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    const { container } = renderPortal();

    const logo = await screen.findByRole("img", { name: "Famboook" });
    expect(logo).toHaveAttribute("src", "/brand/famboook-logo.svg");
    // Intrinsic 648 × 83: only the height is styled, so the ratio is kept.
    expect(logo).toHaveAttribute("width", "648");
    expect(logo).toHaveAttribute("height", "83");
    const brand = container.querySelector("[data-family-brand]");
    expect(brand).toHaveTextContent("بوابة الأسرة");
    expect(brand?.textContent).not.toContain("Famboook");
    expect(brand?.querySelector("svg")).toBeNull();
  });

  it("renders the shell and the home for a family user", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

    renderPortal();

    expect(await screen.findByRole("heading", { level: 1, name: "سالم الاختبار" })).toBeInTheDocument();
    expect(await screen.findByText("فرع الاختبار")).toBeInTheDocument();
    // Codes render left-to-right inside the RTL page.
    expect(screen.getByText("FAM-000123")).toHaveAttribute("dir", "ltr");
    expect(screen.getByRole("heading", { name: "ملخص الأسرة" })).toBeInTheDocument();
    expect(router.replace).not.toHaveBeenCalled();
  });

  it("shows the approved navigation with only the home enabled", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());

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

  it("sends a visitor without a session to the Family login", async () => {
    vi.spyOn(apiClient, "get").mockRejectedValue(new ApiError(401, { message: "Unauthenticated." }));

    renderPortal();

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/login"));
    expect(screen.queryByRole("navigation")).not.toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "ملخص الأسرة" })).not.toBeInTheDocument();
  });

  it("shows a neutral notice to an account that is not family-side", async () => {
    vi.spyOn(apiClient, "get").mockRejectedValue(new ApiError(403, { message: "forbidden" }));

    renderPortal();

    expect(await screen.findByRole("alert")).toHaveTextContent("هذا الحساب ليس حساب أسرة");
    // Never a silent redirect, never the portal: a notice and a clear way on.
    expect(router.replace).not.toHaveBeenCalled();
    expect(screen.getByRole("link", { name: "تسجيل الدخول بحساب الأسرة" })).toHaveAttribute("href", "/family/login");
    expect(screen.queryByRole("navigation")).not.toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "ملخص الأسرة" })).not.toBeInTheDocument();
  });

  it("offers a retry when the server cannot be reached", async () => {
    const get = vi.spyOn(apiClient, "get").mockRejectedValueOnce(new ApiError(500, null)).mockImplementation(familyGet());

    renderPortal();
    await userEvent.click(await screen.findByRole("button", { name: "إعادة المحاولة" }));

    expect(await screen.findByRole("heading", { name: "ملخص الأسرة" })).toBeInTheDocument();
    expect(get.mock.calls.filter(([path]) => path === "/api/v1/family/me")).toHaveLength(2);
  });

  it("renders the access-unavailable state instead of the portal when there is no family context", async () => {
    vi.spyOn(apiClient, "get").mockResolvedValue({
      user: familyUser({ display_name: null, context: { available: false, family: null } }),
    });

    renderPortal();

    expect(await screen.findByRole("heading", { name: "الوصول غير متاح حاليًا" })).toBeInTheDocument();
    // No home, no family, no navigation — only the way out.
    expect(screen.queryByRole("heading", { name: "ملخص الأسرة" })).not.toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "الخدمات السريعة" })).not.toBeInTheDocument();
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

  it("logs out through the Family API and returns to the Family login", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());
    const post = vi.spyOn(apiClient, "post").mockResolvedValue(null);

    renderPortal();
    await userEvent.click(await screen.findByRole("button", { name: "تسجيل الخروج" }));

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/login"));
    expect(post).toHaveBeenCalledWith("/api/v1/family/auth/logout", {});
  });

  it("drops the local session even when the logout call fails", async () => {
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(500, null));

    renderPortal();
    await userEvent.click(await screen.findByRole("button", { name: "تسجيل الخروج" }));

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/login"));
  });
});
