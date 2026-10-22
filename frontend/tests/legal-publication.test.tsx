import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { render, screen, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import {
  EARLIEST_EFFECTIVE_DATE,
  POLICY_VERSION,
  assertPublishableEffectiveDate,
  isCalendarDate,
  legalEffectiveDate,
  publicationToday,
} from "@/lib/legal/effective-date";

// Publication of the three approved legal policies (Executive Director:
// version 1.0, effective date = the actual Production publication date, the
// same for all three, one coordinated release). The date comes from
// NEXT_PUBLIC_LEGAL_EFFECTIVE_DATE at build time; the dates below are
// SYNTHETIC test values, never a publication date.

const TEST_DATE = "2026-11-01"; // synthetic, test-only
const DRAFTS = join(process.cwd(), "..", "docs", "legal", "drafts");

/** Each approved policy, its source document and the source's LF-normalized SHA-256. */
const POLICIES = [
  {
    id: "privacy",
    source: "PRIVACY.ar.md",
    sha256: "7c1bf62cf925f04b6a29c24d77cf4e77e83211b91c99aa3ce50456de2b333475",
    sectionCount: 12,
  },
  {
    id: "terms",
    source: "TERMS.ar.md",
    sha256: "8932fc9a55befd7f718200415fc1f7828db3867da534a918fc62ce258e4e2b2c", // approved baseline 0b950e2
    sectionCount: 9,
  },
  {
    id: "data-protection",
    source: "DATA-PROTECTION.ar.md",
    sha256: "3b00bcdbc919f2ae2c97c0d6bf678a06a335e75c91af0b760b40c1e7cf874589",
    sectionCount: 8,
  },
] as const;

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => "/",
  useSearchParams: () => new URLSearchParams(),
}));

/** The registry and pages as built with the given effective date. */
async function loadWith(date: string | undefined) {
  vi.resetModules();
  vi.unstubAllEnvs();
  if (date !== undefined) vi.stubEnv("NEXT_PUBLIC_LEGAL_EFFECTIVE_DATE", date);
  const pages = await import("@/lib/legal/pages");
  const links = await import("@/components/legal/legal-links");
  const routes = {
    privacy: await import("@/app/(legal)/privacy/page"),
    terms: await import("@/app/(legal)/terms/page"),
    "data-protection": await import("@/app/(legal)/data-protection/page"),
  };
  return { pages, links, routes };
}

/** A source document, LF-normalized, parsed exactly as published: one entry per paragraph, review markers removed. */
function sourceOf(name: string) {
  const text = readFileSync(join(DRAFTS, name), "utf8").replace(/\r\n/g, "\n");
  const lines = text.split("\n");
  const sections: { heading: string; paragraphs: string[] }[] = [];
  for (const line of lines.slice(lines.indexOf("---") + 1)) {
    if (line.startsWith("## ")) sections.push({ heading: line.slice(3).trim(), paragraphs: [] });
    else if (line.trim()) sections[sections.length - 1].paragraphs.push(line.replace(/\s*<!-- R-\d+ -->\s*$/, "").trim());
  }
  return { text, sections };
}

afterEach(() => {
  vi.unstubAllEnvs();
  vi.resetModules();
});

describe("effective-date rules", () => {
  it("accepts only real YYYY-MM-DD dates and never defaults one", () => {
    expect(isCalendarDate("2026-10-22")).toBe(true);
    for (const bad of ["2026-13-01", "2026-02-30", "22-10-2026", "2026-10-2", "", "tomorrow"]) expect(isCalendarDate(bad), bad).toBe(false);
    expect(legalEffectiveDate(undefined)).toBeNull();
    expect(legalEffectiveDate("   ")).toBeNull();
    expect(() => legalEffectiveDate("2026-02-30")).toThrow(/real date written YYYY-MM-DD/);
  });

  it("refuses at build time a date in the future or before the publication authorization", () => {
    const now = new Date("2026-11-05T10:00:00Z");
    expect(publicationToday(now)).toBe("2026-11-05");
    expect(assertPublishableEffectiveDate("2026-11-05", now)).toBe("2026-11-05");
    expect(assertPublishableEffectiveDate("2026-10-30", now)).toBe("2026-10-30"); // a later rebuild keeps the original date
    expect(() => assertPublishableEffectiveDate("2026-11-06", now)).toThrow(/in the future/);
    expect(() => assertPublishableEffectiveDate("2026-10-21", now)).toThrow(/before the publication authorization/);
    expect(assertPublishableEffectiveDate(undefined, now)).toBeNull();
    expect(EARLIEST_EFFECTIVE_DATE).toBe("2026-10-22");
  });

  it("uses the publication time zone for 'today'", () => {
    // 22:30 UTC on 4 Nov is already 5 Nov in Gaza (UTC+2).
    expect(publicationToday(new Date("2026-11-04T22:30:00Z"))).toBe("2026-11-05");
  });

  it("fails loudly on a malformed or blank configured date instead of publishing", async () => {
    await expect(loadWith("2026-13-40")).rejects.toThrow(/NEXT_PUBLIC_LEGAL_EFFECTIVE_DATE/);
    await expect(loadWith("   ")).rejects.toThrow(/blank/);
  });
});

describe("without an effective date nothing is published", () => {
  it("keeps all three policies pending, with no content in their registry entries", async () => {
    const { pages } = await loadWith(undefined);
    for (const { id } of POLICIES) {
      const page = pages.legalPage(id);
      expect(page.status, id).toBe("PENDING_APPROVAL");
      expect(Object.keys(page).sort(), id).toEqual(["description", "href", "id", "status", "title"]);
    }
    expect(pages.publishedLegalPages().map((p) => p.id)).toEqual(["about", "contact"]);
  });
});

describe("the approved policies 1.0, published together with the effective date", () => {
  it.each(POLICIES)("$id: the source document is the approved text (pinned checksum)", ({ source, sha256 }) => {
    const { text } = sourceOf(source);
    expect(createHash("sha256").update(text, "utf8").digest("hex")).toBe(sha256);
  });

  it.each(POLICIES)("$id: publishes exactly the approved text — no drift, no review marker", async ({ id, source, sectionCount }) => {
    const { pages } = await loadWith(TEST_DATE);
    const page = pages.legalPage(id);
    if (page.status !== "PUBLISHED" || page.kind !== "policy") throw new Error(`${id} not published`);
    expect(page.version).toBe("1.0");
    expect(page.version).toBe(POLICY_VERSION);
    expect(page.effectiveDate).toBe(TEST_DATE);
    const { sections } = sourceOf(source);
    expect(sections).toHaveLength(sectionCount);
    expect(page.sections).toEqual(sections);
    expect(JSON.stringify(page.sections)).not.toMatch(/<!--|-->|R-\d{2}|NOT APPROVED|DRAFT/);
  });

  it.each(POLICIES)("$id: renders its sections with version 1.0 and the effective date, unindexed", async ({ id, source }) => {
    const { routes } = await loadWith(TEST_DATE);
    const route = routes[id];
    const Page = route.default;
    render(<Page />);
    const article = screen.getByRole("article");
    expect(article).toHaveAttribute("data-legal-status", "PUBLISHED");
    const meta = article.querySelector("[data-legal-effective]") as HTMLElement;
    expect(meta).toHaveTextContent("الإصدار: 1.0");
    expect(meta.textContent).toMatch(/تاريخ السريان: .*2026/);
    expect(within(article).getAllByRole("heading", { level: 2 }).map((h) => h.textContent)).toEqual(sourceOf(source).sections.map((s) => s.heading));
    expect(article.textContent).not.toMatch(/<!--|R-\d{2}|NOT APPROVED|DRAFT/);
    expect(document.querySelector("form, input, button, [role=checkbox]")).toBeNull();
    expect(route.metadata.robots).toEqual({ index: false, follow: false }); // D9: noindex
  });

  it("carries the seven approved decisions verbatim and none of the replaced claims", async () => {
    const { pages } = await loadWith(TEST_DATE);
    const text = (id: "privacy" | "data-protection") => {
      const page = pages.legalPage(id);
      if (page.status !== "PUBLISHED" || page.kind !== "policy") throw new Error(id);
      return page.sections.flatMap((s) => s.paragraphs).join("\n");
    };
    const privacy = text("privacy");
    const dataProtection = text("data-protection");
    expect(privacy).toContain("تُستضاف المنصة وبياناتها على خوادم تقنية.");
    expect(privacy).not.toContain("تتعاقد معهم");
    expect(privacy).toContain("لم يُوثَّق بعدُ في هذه السياسة الأساس القانوني المحدَّد");
    expect(privacy).toContain("لم تُعتمَد بعدُ مدد الاحتفاظ");
    expect(privacy).toContain("وتتولّى إدارة Famboook استلام الطلبات الواردة إلى هذا العنوان ومراجعتها.");
    expect(dataProtection).toContain("يجب على فريق العمل والمنسقين الحفاظ على سرية البيانات التي يطّلعون عليها، وألّا يستخدموها إلا للغرض الذي مُنحوا الاطلاع من أجله.");
    expect(dataProtection).toContain("تُتداول الكشوف وملفات الإدخال الأولية في حدود الفريق المكلَّف فقط.");
    expect(dataProtection).not.toContain("القنوات التي تعتمدها");
    expect(dataProtection).toContain("يجب أن تمرّ ملفات الإدخال الأولية بمراجعة لجودة البيانات، وأن يعتمدها منسق العشيرة المكلَّف وإدارة Famboook معًا قبل إدخالها.");
  });

  it("gives all three policies the same version and the same effective date", async () => {
    const { pages } = await loadWith(TEST_DATE);
    const policies = pages.LEGAL_PAGES.filter((p) => p.status === "PUBLISHED" && p.kind === "policy");
    expect(policies.map((p) => p.id)).toEqual(["privacy", "terms", "data-protection"]);
    for (const p of policies) {
      if (p.status !== "PUBLISHED" || p.kind !== "policy") continue;
      expect(p.version, p.id).toBe("1.0");
      expect(p.effectiveDate, p.id).toBe(TEST_DATE);
    }
  });

  it("links all five pages, in footer order", async () => {
    const { pages, links } = await loadWith(TEST_DATE);
    expect(pages.publishedLegalPages().map((p) => p.href)).toEqual(["/about", "/privacy", "/terms", "/data-protection", "/contact"]);
    const LegalLinks = links.LegalLinks;
    render(<LegalLinks variant="list" />);
    const hrefs = within(screen.getByRole("navigation", { name: "روابط فامبوك" }))
      .getAllByRole("link")
      .map((l) => l.getAttribute("href"));
    expect(hrefs).toEqual(["/about", "/privacy", "/terms", "/data-protection", "/contact"]);
  });

  it("adds no notification or authentication code to the legal modules", () => {
    for (const file of ["pages.ts", "effective-date.ts", "privacy-v1.ts", "terms-v1.ts", "data-protection-v1.ts"]) {
      const source = readFileSync(join(process.cwd(), "lib", "legal", file), "utf8");
      expect(source, file).not.toMatch(/notification|announcement|useAuth|useFamilyUser|@\/lib\/api/);
    }
  });
});
