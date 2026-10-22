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
const PENDING = ["privacy", "terms", "data-protection"] as const;

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

describe("/about — platform, onboarding and operator (FB-LEGAL-01C.3)", () => {
  it("explains the platform, registry, Family Portal, coordinators and Staff", () => {
    renderPage("about");
    for (const id of ["product", "registry", "family-portal", "coordinators", "staff", "onboarding"]) {
      expect(document.querySelector(`[data-info-section="${id}"]`), id).not.toBeNull();
    }
    expect(screen.getAllByRole("heading", { level: 2 }).map((h) => h.textContent)).toEqual([
      "المنصة",
      "سجل العائلات",
      "بوابة الأسرة",
      "المنسقون",
      "فريق العمل",
      "انضمام العائلات والعشائر",
      "الإصدار",
      "الجهة المشغّلة",
    ]);
  });

  it("lists the approved nine-step onboarding with joint approval, and the roster is never the import file", () => {
    renderPage("about");
    const steps = within(document.querySelector("[data-onboarding-steps]") as HTMLElement).getAllByRole("listitem");
    expect(steps).toHaveLength(9);
    expect(steps[6]).toHaveTextContent("منسق العشيرة المكلَّف رسميًا وإدارة Famboook الملف النهائي معًا");
    expect(screen.getByText(/لا يُعدّ الكشف الأولي الذي تقدّمه العشيرة ملفَّ الإدخال النهائي/)).toBeInTheDocument();
  });

  it("shows no email address: contact details belong to /contact", () => {
    renderPage("about");
    expect(screen.getByRole("article").textContent).not.toMatch(/@|pcdcd\.org/);
  });
});

describe("draft safety — unpublished text never reaches served code (FB-LEGAL-01C.3)", () => {
  it("keeps pending registry entries free of any content (the registry ships in client JavaScript)", () => {
    for (const id of PENDING) {
      expect(Object.keys(legalPage(id)).sort(), id).toEqual(["description", "href", "id", "status", "title"]);
    }
  });

  it("has no email address or draft wording anywhere in the served legal code", () => {
    const sources = [
      join(process.cwd(), "lib", "legal", "pages.ts"),
      join(process.cwd(), "lib", "legal", "operator.ts"),
      join(process.cwd(), "components", "shared", "site-footer.tsx"),
      join(process.cwd(), "components", "layout", "user-menu.tsx"),
      join(ROOT, "layout.tsx"),
      ...Object.keys(PAGES).map((id) => join(ROOT, id, "page.tsx")),
      ...readdirSync(join(process.cwd(), "components", "legal")).map((f) => join(process.cwd(), "components", "legal", f)),
    ];
    for (const file of sources) {
      const source = readFileSync(file, "utf8");
      const emails = source.match(/[A-Za-z0-9._-]+@[A-Za-z0-9-]+\.[A-Za-z]{2,}/g) ?? [];
      // FB-LEGAL-01D: only the verified ACTIVE address, declared once in operator.ts.
      expect(emails.filter((e) => e !== "info@pcdcd.org"), file).toEqual([]);
      if (!file.endsWith("operator.ts")) expect(emails, file).toEqual([]);
      expect(source, file).not.toMatch(/support@pcdcd\.org|media@pcdcd\.org/);
      expect(source, file).not.toMatch(/ملفات تعريف الارتباط والتخزين|الاستخدام غير المسموح|الإبلاغ عن مخاوف/);
    }
  });

  it("keeps the drafts in internal documentation, clearly marked, without the media address on the contact page text", () => {
    const drafts = join(process.cwd(), "..", "docs", "legal", "drafts");
    for (const name of ["PRIVACY.ar.md", "TERMS.ar.md", "DATA-PROTECTION.ar.md"]) {
      const text = readFileSync(join(drafts, name), "utf8");
      expect(text, name).toMatch(/NOT APPROVED/);
      expect(text, name).not.toMatch(/تاريخ السريان:\s*\d/);
    }
    // The page text is everything after the header's horizontal rule.
    const contact = readFileSync(join(drafts, "CONTACT.ar.md"), "utf8").split(/\r?\n---\r?\n/).slice(1).join("\n");
    expect(contact).toContain("info@pcdcd.org");
    expect(contact).not.toContain("media@pcdcd.org");
    // The support address is planned, not active: only a placeholder in the launch-day version.
    expect(contact).not.toContain("support@pcdcd.org");
    expect(contact).toMatch(/Launch-day version — NOT ACTIVE/);
    expect(existsSync(join(drafts, "FB-LEGAL-01C3-REVIEW.md"))).toBe(true);
  });
});

describe("pages awaiting legal approval", () => {
  it("are registered as pending and are never linked", () => {
    for (const id of PENDING) expect(legalPage(id).status).toBe("PENDING_APPROVAL");
    expect(publishedLegalPages().map((p) => p.id)).toEqual(["about", "contact"]);

    render(<LegalLinks />);
    const links = within(screen.getByRole("navigation", { name: "روابط فامبوك" })).getAllByRole("link");
    expect(links.map((l) => l.getAttribute("href"))).toEqual(["/about", "/contact"]);
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
    expect(await screen.findByRole("menuitem", { name: "التواصل" })).toHaveAttribute("href", "/contact");
    for (const id of PENDING) expect(document.querySelector(`[data-user-menu-legal="${id}"]`)).toBeNull();
    expect(screen.getByRole("menuitem", { name: "تسجيل الخروج" })).toBeInTheDocument();
    expect(LEGAL_PAGES.filter((p) => p.status === "PUBLISHED")).toHaveLength(2);
  });
});

describe("/contact — the verified active channel only (FB-LEGAL-01D)", () => {
  it("is published, linked, and shows the institutional address as a mail link", () => {
    expect(legalPage("contact").status).toBe("PUBLISHED");
    renderPage("contact");
    const article = screen.getByRole("article");
    expect(within(article).getByRole("heading", { level: 1, name: "التواصل" })).toBeInTheDocument();
    const email = within(article).getByRole("link", { name: "info@pcdcd.org" });
    expect(email).toHaveAttribute("href", "mailto:info@pcdcd.org");
    expect(email).toHaveAttribute("dir", "ltr");
    expect(article).toHaveTextContent(OPERATOR_DISPLAY_NAME);
    expect(within(screen.getByRole("contentinfo")).getByRole("link", { name: "التواصل" })).toHaveAttribute("href", "/contact");
  });

  it("never shows the planned support address, the media address, a telephone, an address or a form", () => {
    renderPage("contact");
    const article = screen.getByRole("article");
    expect(article.textContent).not.toMatch(/support@|media@|هاتف|جوال المركز|العنوان البريدي|ص\.ب|\+?\d{7,}/);
    expect(within(article).getAllByRole("link")).toHaveLength(1);
    expect(document.querySelector("form, input, textarea, button")).toBeNull();
    expect(article.textContent).not.toMatch(/خلال \d|ساعة|أيام عمل/);
  });

  it("keeps the remaining policies unpublished, unlinked and unindexed", () => {
    expect(publishedLegalPages().map((p) => p.href)).toEqual(["/about", "/contact"]);
    for (const id of ["privacy", "terms", "data-protection"] as const) expect(legalPage(id).status).toBe("PENDING_APPROVAL");
    expect(contactMetadata.robots).toEqual({ index: false, follow: false });
  });
});
