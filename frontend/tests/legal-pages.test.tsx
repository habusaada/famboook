import { existsSync, readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, describe, expect, it, vi } from "vitest";
import LegalLayout from "@/app/(legal)/layout";
import AboutPage, { metadata as aboutMetadata } from "@/app/(legal)/about/page";
import ContactPage, { metadata as contactMetadata } from "@/app/(legal)/contact/page";
import DataProtectionPage, { metadata as dataProtectionMetadata } from "@/app/(legal)/data-protection/page";
import PrivacyPage, { metadata as privacyMetadata } from "@/app/(legal)/privacy/page";
import TermsPage, { metadata as termsMetadata } from "@/app/(legal)/terms/page";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import { LegalLinks } from "@/components/legal/legal-links";
import { LegalPageView } from "@/components/legal/legal-page-view";
import { UserMenu } from "@/components/layout/user-menu";
import type { CurrentUser } from "@/lib/api/auth";
import { OPERATOR_DISPLAY_NAME } from "@/lib/legal/operator";
import { LEGAL_PAGES, type PublishedPolicy, legalPage, publishedLegalPages } from "@/lib/legal/pages";
import { RELEASE, RELEASE_DESIGNATION } from "@/lib/release";
import { renderWithClient } from "./helpers";

// FB-LEGAL-01B: the public legal and information pages, the release identity
// and the publication safeguards. Pending pages must never show policy text,
// a version, an effective date or an acceptance control, and are never
// linked. Synthetic data only.

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => "/",
  useSearchParams: () => new URLSearchParams(),
}));

beforeAll(() => {
  Object.assign(Element.prototype, {
    hasPointerCapture: () => false,
    releasePointerCapture: () => undefined,
    scrollIntoView: () => undefined,
  });
});

const ROOT = join(process.cwd(), "app", "(legal)");
const PAGES = {
  about: { Page: AboutPage, metadata: aboutMetadata },
  privacy: { Page: PrivacyPage, metadata: privacyMetadata },
  terms: { Page: TermsPage, metadata: termsMetadata },
  "data-protection": { Page: DataProtectionPage, metadata: dataProtectionMetadata },
  contact: { Page: ContactPage, metadata: contactMetadata },
} as const;
const PENDING = ["privacy", "terms", "data-protection", "contact"] as const;

function renderPage(id: keyof typeof PAGES) {
  const { Page } = PAGES[id];
  return render(
    <LegalLayout>
      <Page />
    </LegalLayout>
  );
}

describe("routes and access", () => {
  it("serves the five routes from the (legal) group, outside the Staff and Family gates", () => {
    for (const id of Object.keys(PAGES)) {
      expect(existsSync(join(ROOT, id, "page.tsx")), id).toBe(true);
      expect(existsSync(join(process.cwd(), "app", "(staff)", id)), `(staff)/${id}`).toBe(false);
      expect(existsSync(join(process.cwd(), "app", "family", id)), `family/${id}`).toBe(false);
    }
    // No gate in the group's layout: no session is ever needed.
    const layout = readFileSync(join(ROOT, "layout.tsx"), "utf8");
    expect(layout).not.toMatch(/<(AuthGate|FamilyGate|CoordinatorGate|CoordinatorRoleGate)[\s>]|useAuth\(|useFamilyUser\(/);
    expect(Object.keys(PAGES).map((id) => legalPage(id as keyof typeof PAGES).href)).toEqual(["/about", "/privacy", "/terms", "/data-protection", "/contact"]);
  });

  it("is server-rendered: no client component or client state in the legal routes and components", () => {
    const files = [join(ROOT, "layout.tsx"), ...Object.keys(PAGES).map((id) => join(ROOT, id, "page.tsx"))];
    const components = join(process.cwd(), "components", "legal");
    files.push(...readdirSync(components).map((f) => join(components, f)));
    for (const file of files) {
      const source = readFileSync(file, "utf8");
      expect(source, file).not.toContain('"use client"');
      expect(source, file).not.toMatch(/useState|useEffect|useQuery/);
    }
  });

  it("inherits Arabic RTL from the root layout and uses a responsive, accessible structure", () => {
    expect(readFileSync(join(process.cwd(), "app", "layout.tsx"), "utf8")).toMatch(/lang="ar" dir="rtl"/);
    renderPage("about");
    const main = screen.getByRole("main");
    expect(main.className).toContain("w-full");
    expect(main.className).toContain("max-w-2xl");
    expect(main.className).toContain("px-4");
    expect(screen.getAllByRole("heading", { level: 1 })).toHaveLength(1);
    expect(screen.getByRole("contentinfo")).toBeInTheDocument();
  });
});

describe("/about — verified facts and the approved operator only", () => {
  it("shows the release designation, label, launch date and the operator wording", () => {
    renderPage("about");
    const article = screen.getByRole("article");
    expect(within(article).getByRole("heading", { level: 1, name: "عن فامبوك" })).toBeInTheDocument();
    expect(article.querySelector("[data-release-designation]")).toHaveTextContent("Famboook V1.0");
    expect(article).toHaveTextContent("Initial Production Release");
    expect(article.querySelector('time[datetime="2026-10-01"]')).not.toBeNull();
    expect(article.querySelector("[data-operator]")).toHaveTextContent(`تشغيل وإدارة البيانات: ${OPERATOR_DISPLAY_NAME}`);
  });

  it("invents no contact detail, legal claim or ownership claim", () => {
    renderPage("about");
    const article = screen.getByRole("article");
    expect(article.textContent).not.toMatch(/@|\+?\d{7,}|هاتف|بريد|عنوان|رقم التسجيل|مملوك|ملكية|متوافق|معتمد أمنيًا|ISO/);
    expect(within(article).queryAllByRole("link")).toHaveLength(0);
  });
});

describe("pages awaiting legal approval", () => {
  it("are registered as pending and are never linked", () => {
    for (const id of PENDING) expect(legalPage(id).status).toBe("PENDING_APPROVAL");
    expect(publishedLegalPages().map((p) => p.id)).toEqual(["about"]);

    render(<LegalLinks />);
    const links = within(screen.getByRole("navigation", { name: "روابط فامبوك" })).getAllByRole("link");
    expect(links.map((l) => l.getAttribute("href"))).toEqual(["/about"]);
  });

  it("show only a neutral notice: no policy text, version, effective date or acceptance control", () => {
    for (const id of PENDING) {
      const { container, unmount } = renderPage(id);
      const article = container.querySelector(`[data-legal-page="${id}"]`) as HTMLElement;
      expect(article, id).toHaveAttribute("data-legal-status", "PENDING_APPROVAL");
      expect(within(article).getByRole("heading", { level: 1, name: legalPage(id).title })).toBeInTheDocument();
      expect(article.querySelector("[data-legal-pending]")).toHaveTextContent("لم يُنشر محتواها بعد");
      expect(within(article).queryAllByRole("heading", { level: 2 })).toHaveLength(0);
      expect(article.querySelector("[data-legal-effective]")).toBeNull();
      expect(article.textContent).not.toMatch(/تاريخ السريان|الإصدار:|نافذ|أوافق|موافقة/);
      expect(container.querySelector("form, input, button, [role=checkbox]")).toBeNull();
      expect(article.textContent).not.toMatch(/@|\+?\d{7,}/);
      unmount();
    }
  });

  it("are never indexed (D9 pending), and every page has its own title", () => {
    for (const [id, { metadata }] of Object.entries(PAGES)) {
      expect(metadata.robots, id).toEqual({ index: false, follow: false });
      expect(metadata.title, id).toBe(`${legalPage(id as keyof typeof PAGES).title} — Famboook`);
      expect(metadata.description, id).toBeTruthy();
    }
  });

  it("an approved policy, once inserted in the registry, renders with its version and effective date — no restructuring", () => {
    const approved: PublishedPolicy = {
      ...legalPage("privacy"),
      status: "PUBLISHED",
      kind: "policy",
      version: "privacy-test-1",
      effectiveDate: "2026-12-01",
      sections: [{ heading: "قسم تجريبي", paragraphs: ["نص تجريبي للاختبار فقط."] }],
    };
    render(<LegalPageView page={approved} />);
    expect(screen.getByRole("article")).toHaveAttribute("data-legal-status", "PUBLISHED");
    expect(document.querySelector("[data-legal-effective]")).toHaveTextContent("privacy-test-1");
    expect(screen.getByRole("heading", { level: 2, name: "قسم تجريبي" })).toBeInTheDocument();
  });

  it("refuses to render an informational page as a policy", () => {
    vi.spyOn(console, "error").mockImplementation(() => undefined);
    expect(() => render(<LegalPageView page={legalPage("about")} />)).toThrow(/renders its own content/);
  });
});

describe("the release identity", () => {
  it("is one source of truth, independent of package.json", () => {
    expect(RELEASE).toEqual({ product: "Famboook", version: "V1.0", label: "Initial Production Release", launchDate: "2026-10-01" });
    expect(RELEASE_DESIGNATION).toBe("Famboook V1.0");
    const pkg = JSON.parse(readFileSync(join(process.cwd(), "package.json"), "utf8")) as { version: string };
    expect(pkg.version).toBe("0.1.0");
  });

  it("is in the footer of every legal page", () => {
    for (const id of Object.keys(PAGES) as (keyof typeof PAGES)[]) {
      const { unmount } = renderPage(id);
      expect(screen.getByRole("contentinfo").querySelector("[data-release]"), id).toHaveTextContent("Famboook V1.0");
      unmount();
    }
  });
});

describe("the Staff user menu", () => {
  const STAFF: CurrentUser = { name: "موظف تجريبي", email: "staff@example.test", role: "ADMINISTRATOR", role_label: "مدير", permissions: [] };

  it("offers the published pages only, next to logout, with no permission involved", async () => {
    renderWithClient(
      <AuthContext.Provider value={authValue(STAFF)}>
        <UserMenu />
      </AuthContext.Provider>
    );
    await userEvent.click(screen.getByRole("button", { name: /الحساب/ }));
    const about = await screen.findByRole("menuitem", { name: "عن فامبوك" });
    expect(about).toHaveAttribute("href", "/about");
    for (const id of PENDING) expect(document.querySelector(`[data-user-menu-legal="${id}"]`)).toBeNull();
    expect(screen.getByRole("menuitem", { name: "تسجيل الخروج" })).toBeInTheDocument();
    expect(LEGAL_PAGES.filter((p) => p.status === "PUBLISHED")).toHaveLength(1);
  });
});
