import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

// FB-UI-01 (docs/adr/FB-UI-01-UNIFIED-COLOR-SYSTEM.md): the Kingfisher palette
// is the ONE Famboook color system. :root defines it for both portals; the
// Family scope keeps only its geometry, elevation and Family-only tokens;
// semantic colors stay distinct; dark mode is out of scope (unchanged).

const css = readFileSync(join(__dirname, "..", "app", "globals.css"), "utf8");

/** The custom properties declared directly in the first block opened by `selector {`. */
function block(selector: string): Record<string, string> {
  const start = css.indexOf(`${selector} {`);
  expect(start, selector).toBeGreaterThanOrEqual(0);
  const body = css.slice(css.indexOf("{", start) + 1, css.indexOf("}", start));
  const props: Record<string, string> = {};
  for (const match of body.replace(/\/\*[\s\S]*?\*\//g, "").matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) {
    props[match[1]] = match[2].trim();
  }
  return props;
}

const root = block(":root");
const family = block('[data-portal="family"]');
const dark = block(".dark");
const theme = block("@theme inline");

const KINGFISHER: Record<string, string> = {
  "--brand-900": "#42047E",
  "--brand-800": "#53188B",
  "--brand-700": "#751BD5",
  "--brand-600": "#892CF1",
  "--brand-500": "#9F4FFD",
  "--brand-100": "#F2E7FF",
  "--brand-50": "#F9F5FF",
  "--canvas": "#F7F7FA",
  "--surface-1": "#FFFFFF",
  "--surface-2": "#FAFAFC",
  "--surface-3": "#FFFFFF",
  "--surface-hover": "#F4F4F7",
  "--surface-pressed": "#ECECF0",
  "--surface-selected": "var(--brand-50)",
  "--stroke-subtle": "#F0F0F2",
  "--background": "#FFFFFF",
  "--foreground": "#18181B",
  "--card": "#FFFFFF",
  "--card-foreground": "#18181B",
  "--primary": "var(--brand-700)",
  "--primary-foreground": "#FFFFFF",
  "--secondary": "#F4F4F5",
  "--secondary-foreground": "#18181B",
  "--muted": "#F4F4F5",
  "--muted-foreground": "#52525B",
  "--subtle-foreground": "#71717A",
  "--accent": "var(--brand-50)",
  "--accent-foreground": "var(--brand-900)",
  "--border": "#E4E4E7",
  "--input": "#D4D4D8",
  "--ring": "var(--brand-600)",
  "--success": "#09724D",
  "--success-soft": "#EEFFF8",
};

// The previous Staff teal ("Calm Institutional") brand and neutral values.
const TEAL = ["#123C3A", "#17504C", "#176B63", "#16877A", "#20A08F", "#DDF3EF", "#F0FAF8", "#F4F6F5", "#F8FAF9", "#F2F5F4", "#EAEFED", "#E7ECEA", "#17201E", "#F3F5F4", "#66716E", "#737E7B", "#E5E9E7", "#D5DCD9", "#1E7A4C", "#E6F4EC"];

describe("FB-UI-01: one Kingfisher color system", () => {
  it(":root holds the canonical Kingfisher values", () => {
    for (const [token, value] of Object.entries(KINGFISHER)) {
      expect(root[token], token).toBe(value);
    }
  });

  it("the Family scope no longer repeats any shared color token, so both portals resolve the same palette", () => {
    // Only geometry and elevation may differ per portal — never a color.
    const portalSpecific = new Set(["--radius", "--elevation-1", "--elevation-2"]);
    for (const token of Object.keys(root).filter((t) => !portalSpecific.has(t))) {
      expect(family, `${token} is redefined in the Family scope`).not.toHaveProperty(token);
    }
    expect(Object.keys(family).sort()).toEqual(
      ["--elevation-1", "--elevation-2", "--family-primary-hover", "--family-spring", "--family-spring-bright", "--radius"].sort()
    );
  });

  it("keeps warning, danger, info and destructive unchanged and distinct from the brand", () => {
    expect({
      warning: root["--warning"],
      warningSoft: root["--warning-soft"],
      danger: root["--danger"],
      dangerSoft: root["--danger-soft"],
      info: root["--info"],
      infoSoft: root["--info-soft"],
      destructive: root["--destructive"],
    }).toEqual({
      warning: "#9A5B06",
      warningSoft: "#FDF3E1",
      danger: "#B42318",
      dangerSoft: "#FDECEA",
      info: "#1D5F8C",
      infoSoft: "#E8F1F8",
      destructive: "#B42318",
    });
    const brand = Object.entries(root).filter(([token]) => token.startsWith("--brand-")).map(([, value]) => value);
    for (const semantic of ["--success", "--warning", "--danger", "--info", "--destructive"]) {
      expect(brand).not.toContain(root[semantic]);
    }
  });

  it("keeps the Staff radius and shadows", () => {
    expect(root["--radius"]).toBe("0.5rem");
    expect(root["--elevation-1"]).toBe("0 1px 2px rgb(18 32 30 / 0.04), 0 2px 8px -2px rgb(18 32 30 / 0.06)");
    expect(root["--elevation-2"]).toBe("0 2px 4px rgb(18 32 30 / 0.04), 0 6px 16px rgb(18 32 30 / 0.07)");
    expect(root["--elevation-3"]).toBe("0 4px 8px rgb(18 32 30 / 0.06), 0 16px 32px rgb(18 32 30 / 0.12)");
  });

  it("keeps the Family radius, shadows and Family-only tokens", () => {
    expect(family).toEqual({
      "--family-primary-hover": "#651CAD",
      "--elevation-1": "0 1px 2px rgb(24 24 27 / 0.04)",
      "--elevation-2": "0 1px 3px rgb(24 24 27 / 0.06)",
      "--radius": "0.75rem",
      "--family-spring": "#00BB76",
      "--family-spring-bright": "#07F49E",
    });
  });

  it("maps every Tailwind color token to a variable defined in :root", () => {
    const colors = Object.entries(theme).filter(([token]) => token.startsWith("--color-"));
    expect(colors.length).toBeGreaterThan(40);
    for (const [token, value] of colors) {
      const target = /^var\((--[\w-]+)\)$/.exec(value)?.[1];
      expect(target, token).toBeDefined();
      expect(root, `${token} → ${target}`).toHaveProperty(target!);
    }
  });

  it("no teal value remains in the active light theme, and text/border literals reference the shared tokens", () => {
    const light = Object.values(root).join(" ").toUpperCase();
    for (const teal of TEAL) {
      expect(light, teal).not.toContain(teal);
    }
    expect(root["--popover-foreground"]).toBe("var(--foreground)");
    expect(root["--sidebar-foreground"]).toBe("var(--foreground)");
    expect(root["--sidebar-border"]).toBe("var(--border)");
  });

  it("leaves dark mode out of scope: its block still exists, unchanged", () => {
    expect(dark["--primary"]).toBe("#20A08F");
    expect(dark["--brand-700"]).toBe("#5CC4B3");
    expect(Object.keys(dark).length).toBeGreaterThan(50);
  });
});
