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

// Publication of the approved legal policies (Executive Director: version
// 1.0, effective date = the actual Production publication date, the same for
// every published policy). The date comes from NEXT_PUBLIC_LEGAL_EFFECTIVE_DATE
// at build time; the dates below are SYNTHETIC test values, never a
// publication date.

const TEST_DATE = "2026-11-01"; // synthetic, test-only
const TERMS_SOURCE = join(process.cwd(), "..", "docs", "legal", "drafts", "TERMS.ar.md");
const APPROVED_TERMS_SHA256 = "8932fc9a55befd7f718200415fc1f7828db3867da534a918fc62ce258e4e2b2c";

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
  const view = await import("@/components/legal/legal-page-view");
  const links = await import("@/components/legal/legal-links");
  const terms = await import("@/app/(legal)/terms/page");
  const privacy = await import("@/app/(legal)/privacy/page");
  const dataProtection = await import("@/app/(legal)/data-protection/page");
  return { pages, view, links, terms, privacy, dataProtection };
}

/** The approved source, parsed exactly as published: one entry per paragraph, review markers removed. */
function approvedTermsSections() {
  const text = readFileSync(TERMS_SOURCE, "utf8").replace(/\r\n/g, "\n");
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
  it("keeps the Terms pending, with no content in the registry entry", async () => {
    const { pages } = await loadWith(undefined);
    const terms = pages.legalPage("terms");
    expect(terms.status).toBe("PENDING_APPROVAL");
    expect(Object.keys(terms).sort()).toEqual(["description", "href", "id", "status", "title"]);
    expect(pages.publishedLegalPages().map((p) => p.id)).toEqual(["about", "contact"]);
  });
});

describe("the approved Terms of Use 1.0, published with the effective date", () => {
  it("is the approved source document (0b950e2)", () => {
    const { text } = approvedTermsSections();
    expect(createHash("sha256").update(text, "utf8").digest("hex")).toBe(APPROVED_TERMS_SHA256);
  });

  it("publishes exactly the approved text — no drift, no review marker", async () => {
    const { pages } = await loadWith(TEST_DATE);
    const terms = pages.legalPage("terms");
    if (terms.status !== "PUBLISHED" || terms.kind !== "policy") throw new Error("Terms not published");
    expect(terms.version).toBe("1.0");
    expect(terms.version).toBe(POLICY_VERSION);
    expect(terms.effectiveDate).toBe(TEST_DATE);
    const { sections } = approvedTermsSections();
    expect(sections).toHaveLength(9);
    expect(terms.sections).toEqual(sections);
    expect(JSON.stringify(terms.sections)).not.toMatch(/<!--|-->|R-\d{2}/);
  });

  it("renders the sections with version 1.0 and the effective date, in the approved order", async () => {
    const { terms, pages } = await loadWith(TEST_DATE);
    const Terms = terms.default;
    render(<Terms />);
    const article = screen.getByRole("article");
    expect(article).toHaveAttribute("data-legal-status", "PUBLISHED");
    const meta = article.querySelector("[data-legal-effective]") as HTMLElement;
    expect(meta).toHaveTextContent("الإصدار: 1.0");
    expect(meta.textContent).toMatch(/تاريخ السريان: .*2026/);
    expect(within(article).getAllByRole("heading", { level: 2 }).map((h) => h.textContent)).toEqual(approvedTermsSections().sections.map((s) => s.heading));
    expect(article).toHaveTextContent("يُعدّ من الاستخدام غير المسموح ما يأتي:");
    expect(article.textContent).not.toMatch(/<!--|R-\d{2}|NOT APPROVED|DRAFT/);
    expect(document.querySelector("form, input, button, [role=checkbox]")).toBeNull();
    expect(terms.metadata.robots).toEqual({ index: false, follow: false }); // D9: noindex
    expect(pages.legalMetadata("terms").robots).toEqual({ index: false, follow: false });
  });

  it("is linked with the other published pages; Privacy and Data Protection stay pending and unlinked", async () => {
    const { pages, links } = await loadWith(TEST_DATE);
    expect(pages.publishedLegalPages().map((p) => p.href)).toEqual(["/about", "/terms", "/contact"]);
    const LegalLinks = links.LegalLinks;
    render(<LegalLinks variant="list" />);
    const hrefs = within(screen.getByRole("navigation", { name: "روابط فامبوك" }))
      .getAllByRole("link")
      .map((l) => l.getAttribute("href"));
    expect(hrefs).toEqual(["/about", "/terms", "/contact"]);
    for (const id of ["privacy", "data-protection"] as const) {
      const page = pages.legalPage(id);
      expect(page.status, id).toBe("PENDING_APPROVAL");
      expect(Object.keys(page).sort(), id).toEqual(["description", "href", "id", "status", "title"]);
    }
  });

  it("shows only the neutral notice on /privacy and /data-protection, even with an effective date", async () => {
    const { privacy, dataProtection } = await loadWith(TEST_DATE);
    for (const Page of [privacy.default, dataProtection.default]) {
      const { container, unmount } = render(<Page />);
      const article = container.querySelector("article") as HTMLElement;
      expect(article).toHaveAttribute("data-legal-status", "PENDING_APPROVAL");
      expect(article.querySelector("[data-legal-effective]")).toBeNull();
      expect(article.textContent).not.toMatch(/تاريخ السريان|الإصدار:/);
      unmount();
    }
  });

  it("gives every published policy the same version and effective date", async () => {
    const { pages } = await loadWith(TEST_DATE);
    const policies = pages.LEGAL_PAGES.filter((p) => p.status === "PUBLISHED" && p.kind === "policy");
    expect(policies.length).toBeGreaterThan(0);
    for (const p of policies) {
      if (p.status !== "PUBLISHED" || p.kind !== "policy") continue;
      expect(p.version, p.id).toBe("1.0");
      expect(p.effectiveDate, p.id).toBe(TEST_DATE);
    }
  });

  it("adds no notification or authentication code to the legal modules", () => {
    for (const file of ["pages.ts", "effective-date.ts", "terms-v1.ts"]) {
      const source = readFileSync(join(process.cwd(), "lib", "legal", file), "utf8");
      expect(source, file).not.toMatch(/notification|announcement|useAuth|useFamilyUser|@\/lib\/api/);
    }
  });
});
