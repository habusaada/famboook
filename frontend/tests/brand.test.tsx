import { readFileSync } from "node:fs";
import { join } from "node:path";
import { render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import { LoginView } from "@/components/auth/login-view";
import { AppShell } from "@/components/layout/app-shell";
import { BRAND_LOGO, BrandLogo } from "@/components/shared/brand-logo";
import { DEVELOPER_URL, SiteFooter } from "@/components/shared/site-footer";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CurrentUser } from "@/lib/api/auth";
import { renderWithClient } from "./helpers";

// FB-BRAND-01: the official Famboook identity — the shared logo, the
// copyright footer, and the icon set and manifest generated from the
// official icon. Assets are read from disk; no colour token is involved.

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  usePathname: () => "/",
  useSearchParams: () => new URLSearchParams(),
}));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const ROOT = join(__dirname, "..");
const read = (path: string) => readFileSync(join(ROOT, path));

/** Width and height of a PNG, from its IHDR chunk. */
function pngSize(path: string): [number, number] {
  const data = read(path);
  expect(data.subarray(1, 4).toString("ascii")).toBe("PNG");
  return [data.readUInt32BE(16), data.readUInt32BE(20)];
}

const STAFF: CurrentUser = { name: "موظف تجريبي", email: "staff@example.test", role: "REVIEWER", role_label: "مراجع", permissions: [] };

describe("the official logo", () => {
  it("renders the untouched official files, sized by height only", () => {
    render(
      <>
        <BrandLogo className="h-7" />
        <BrandLogo variant="icon" alt="" className="h-6" />
      </>
    );
    const full = document.querySelector('[data-brand-logo="full"][data-brand-theme="light"]');
    expect(full).toHaveAttribute("src", "/brand/FamboookLogoColoredLight.svg");
    expect(full).toHaveAttribute("alt", "Famboook");
    expect([full?.getAttribute("width"), full?.getAttribute("height")]).toEqual(["432", "144"]);
    expect(full).toHaveClass("h-7", "w-auto");
    const icon = document.querySelector('[data-brand-logo="icon"]');
    expect(icon).toHaveAttribute("src", "/brand/FamboookLogoColoredIcon.svg");
    expect(icon).toHaveAttribute("alt", "");
  });

  it("follows the existing theme mechanism: light by default, dark only under .dark", () => {
    render(<BrandLogo className="h-8" />);
    const light = document.querySelector('[data-brand-theme="light"]');
    const dark = document.querySelector('[data-brand-theme="dark"]');
    // Tailwind's dark: variant is the .dark class of globals.css — never set by
    // the app today, so the light variant is the one displayed.
    expect(light).toHaveClass("block", "dark:hidden");
    expect(dark).toHaveAttribute("src", "/brand/FamboookLogoColoredDark.svg");
    expect(dark).toHaveClass("hidden", "dark:block");
    expect(dark).toHaveAttribute("alt", "Famboook");
    expect(document.documentElement).not.toHaveClass("dark");
    expect(read("app/globals.css").toString()).toContain("@custom-variant dark (&:is(.dark *));");
  });

  it("keeps the declared ratios equal to the artwork's viewBox", () => {
    for (const [variant, path] of [
      ["full", "public/brand/FamboookLogoColoredLight.svg"],
      ["full", "public/brand/FamboookLogoColoredDark.svg"],
      ["icon", "public/brand/FamboookLogoColoredIcon.svg"],
    ] as const) {
      const [, , w, h] = /viewBox="([^"]+)"/.exec(read(path).toString())![1].split(/\s+/).map(Number);
      const declared = BRAND_LOGO[variant].width / BRAND_LOGO[variant].height;
      expect(Math.abs(declared - w / h)).toBeLessThan(0.01);
    }
  });

  it("brands the Staff login and the Staff sidebar and mobile sheet with the official logo, footer on the login only", async () => {
    vi.spyOn(apiClient, "get").mockRejectedValue(new ApiError(401, { message: "x" }));
    renderWithClient(<LoginView />);
    const lockup = document.querySelector("[data-brand-lockup]") as HTMLElement;
    expect(lockup.querySelector('[data-brand-theme="light"]')).toHaveAttribute("src", "/brand/FamboookLogoColoredLight.svg");
    // FB-UI-02: the logo alone — the separate «بوابة الطاقم» label is gone; the card heading says «دخول الطاقم».
    expect(lockup).not.toHaveTextContent("بوابة الطاقم");
    expect(screen.getByRole("heading", { level: 1, name: "دخول الطاقم" })).toBeInTheDocument();
    expect(lockup.querySelector("svg")).toBeNull();
    expect(document.querySelector("[data-site-footer]")).not.toBeNull();

    renderWithClient(
      <AuthContext.Provider value={authValue(STAFF)}>
        <AppShell>
          <p>محتوى</p>
        </AppShell>
      </AuthContext.Provider>
    );
    const logos = document.querySelectorAll('img[src="/brand/FamboookLogoColoredLight.svg"]');
    expect(logos.length).toBeGreaterThanOrEqual(2);
    // FB-BRAND-02: the footer stays on the public login only — never inside
    // the authenticated Staff application.
    expect(document.querySelectorAll("[data-site-footer]")).toHaveLength(1);
  });
});

describe("the copyright footer", () => {
  it("credits Famboook and links the developer safely", () => {
    render(<SiteFooter />);
    const footer = screen.getByRole("contentinfo");
    expect(footer).toHaveTextContent("© 2026 فامبوك. جميع الحقوق محفوظة.");
    const link = within(footer).getByRole("link", { name: "المركز الفلسطيني للبيانات والتنمية المجتمعية – PCDCD" });
    expect(link).toHaveAttribute("href", DEVELOPER_URL);
    expect(link).toHaveAttribute("href", "https://www.facebook.com/pcdcd.gaza");
    expect(link).toHaveAttribute("target", "_blank");
    expect(link).toHaveAttribute("rel", "noopener noreferrer");
  });
});

describe("the icon set and the manifest", () => {
  const manifest = JSON.parse(read("public/manifest.webmanifest").toString()) as {
    id: string;
    start_url: string;
    scope: string;
    display: string;
    theme_color: string;
    background_color: string;
    icons: { src: string; sizes: string; type: string; purpose: string }[];
  };

  it("keeps every existing manifest property and points to the versioned icons", () => {
    expect({
      id: manifest.id,
      start_url: manifest.start_url,
      scope: manifest.scope,
      display: manifest.display,
      theme_color: manifest.theme_color,
      background_color: manifest.background_color,
    }).toEqual({ id: "/family", start_url: "/family", scope: "/family", display: "standalone", theme_color: "#751BD5", background_color: "#F7F7FA" });
    for (const icon of manifest.icons) expect(icon.src.startsWith("/icons/v2/")).toBe(true);
    expect(manifest.icons.map((i) => `${i.sizes}:${i.purpose}`)).toEqual(["192x192:any", "512x512:any", "192x192:maskable", "512x512:maskable", "any:any"]);
  });

  it("has every declared icon on disk at its declared size", () => {
    for (const icon of manifest.icons.filter((i) => i.type === "image/png")) {
      const [w, h] = icon.sizes.split("x").map(Number);
      expect(pngSize(`public${icon.src}`)).toEqual([w, h]);
    }
    expect(pngSize("public/icons/v2/apple-touch-icon.png")).toEqual([180, 180]);
    expect(pngSize("app/apple-icon.png")).toEqual([180, 180]);
  });

  it("serves the official icon byte for byte as the SVG favicon and PWA icon", () => {
    const official = read("public/brand/FamboookLogoColoredIcon.svg");
    expect(read("app/icon.svg").equals(official)).toBe(true);
    expect(read("public/icons/v2/famboook-icon.svg").equals(official)).toBe(true);
  });

  it("has a favicon.ico with 16, 32 and 48 pixel images", () => {
    const ico = read("app/favicon.ico");
    expect([ico.readUInt16LE(0), ico.readUInt16LE(2)]).toEqual([0, 1]);
    const count = ico.readUInt16LE(4);
    const sizes = Array.from({ length: count }, (_, i) => ico.readUInt8(6 + i * 16));
    expect(sizes).toEqual([16, 32, 48]);
  });

  it("keeps the legacy icons for installed apps (one more release)", () => {
    for (const path of ["public/icons/icon-192.png", "public/icons/icon-512.png", "public/icons/icon-maskable-512.png", "public/icons/apple-touch-icon.png", "public/icons/famboook-icon.svg"]) {
      expect(read(path).length).toBeGreaterThan(0);
    }
  });
});
