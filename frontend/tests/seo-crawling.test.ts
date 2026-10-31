import { readdirSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import robots from "@/app/robots";
import sitemap from "@/app/sitemap";
import { metadata as homeMetadata } from "@/app/(public)/page";

// FB-SEO-03 / FB-SEO-04: robots.txt and sitemap.xml.

const rules = robots().rules;
const rule = Array.isArray(rules) ? rules[0] : rules;
const list = (value: string | string[] | undefined) => (value === undefined ? [] : Array.isArray(value) ? value : [value]);
const allow = list(rule.allow);
const disallow = list(rule.disallow);

/** Google's rule: the longest matching path wins; on a tie, Allow wins. */
function crawlable(path: string): boolean {
  const longest = (paths: string[]) => Math.max(-1, ...paths.filter((p) => path.startsWith(p)).map((p) => p.length));
  return longest(allow) >= longest(disallow);
}

describe("robots.txt", () => {
  it("applies to every crawler and points to the sitemap", () => {
    expect(Array.isArray(rules) ? rules : [rules]).toHaveLength(1);
    expect(rule.userAgent).toBe("*");
    expect(robots().sitemap).toBe("https://famboook.com/sitemap.xml");
  });

  it.each(["/", "/?utm=x", "/login", "/family/login", "/family/activate", "/family/forgot-password", "/verify/x", "/about", "/privacy", "/terms", "/data-protection", "/contact", "/_next/static/x.js", "/brand/famboook-og.png", "/manifest.webmanifest"])(
    "leaves %s crawlable",
    (path) => {
      expect(crawlable(path)).toBe(true);
    },
  );

  it.each(["/dashboard", "/families", "/families/1", "/people/P-1", "/civil-registry/lookup", "/administration/imports", "/change-requests/1", "/reports", "/dev-login", "/family", "/family/card", "/family/members", "/family/coordinator/requests", "/family/civil-registry", "/api/v1/me"])(
    "blocks %s",
    (path) => {
      expect(crawlable(path)).toBe(false);
    },
  );

  it("blocks every Staff section on disk except the sign-in page", () => {
    const sections = readdirSync(join(process.cwd(), "app/(staff)"), { withFileTypes: true })
      .filter((entry) => entry.isDirectory() && entry.name !== "login")
      .map((entry) => `/${entry.name}`);
    expect(sections.length).toBeGreaterThan(10);
    for (const section of sections) expect(crawlable(section), section).toBe(false);
  });

  it("blocks every Family Portal page on disk", () => {
    const pages = readdirSync(join(process.cwd(), "app/family/(portal)"), { withFileTypes: true })
      .filter((entry) => entry.isDirectory())
      .map((entry) => `/family/${entry.name}`);
    expect(pages.length).toBeGreaterThan(5);
    for (const page of pages) expect(crawlable(page), page).toBe(false);
  });
});

describe("sitemap.xml", () => {
  it("lists exactly the homepage, at its canonical URL", () => {
    expect(sitemap()).toEqual([{ url: "https://famboook.com/" }]);
    expect(sitemap()[0].url).toBe(homeMetadata.alternates?.canonical);
  });
});
