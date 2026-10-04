import { createHash } from "node:crypto";
import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { metadata, viewport } from "@/app/family/layout";

// The installable Family app (docs/11): the official Famboook icon is the
// single source of every app icon. Nothing here is redrawn: the SVG is the
// supplied file, byte for byte, and the PNGs are rendered from it.

const PUBLIC = join(__dirname, "..", "public");
const OFFICIAL_SVG_SHA256 = "17bd1244ad87475ca54a8c89b11a7523d78c659ae431bdbe4875e2deeaf40345";

type Icon = { src: string; sizes: string; type: string; purpose: string };
const manifest = JSON.parse(readFileSync(join(PUBLIC, "manifest.webmanifest"), "utf8")) as {
  start_url: string;
  scope: string;
  display: string;
  dir: string;
  lang: string;
  icons: Icon[];
};

/** Width and height from a PNG's IHDR chunk. */
function pngSize(file: string): [number, number] {
  const bytes = readFileSync(join(PUBLIC, file));
  expect(bytes.subarray(1, 4).toString("ascii")).toBe("PNG");
  return [bytes.readUInt32BE(16), bytes.readUInt32BE(20)];
}

describe("the Famboook app icon", () => {
  it("is the official SVG, unchanged", () => {
    const svg = readFileSync(join(PUBLIC, "icons", "famboook-icon.svg"));
    expect(createHash("sha256").update(svg).digest("hex")).toBe(OFFICIAL_SVG_SHA256);
  });

  it("has every raster size the platforms need", () => {
    expect(pngSize("icons/icon-192.png")).toEqual([192, 192]);
    expect(pngSize("icons/icon-512.png")).toEqual([512, 512]);
    expect(pngSize("icons/icon-maskable-512.png")).toEqual([512, 512]);
    expect(pngSize("icons/apple-touch-icon.png")).toEqual([180, 180]);
  });
});

describe("the web app manifest", () => {
  it("opens the Family Portal as a standalone RTL app", () => {
    expect(manifest).toMatchObject({ start_url: "/family", scope: "/family", display: "standalone", dir: "rtl", lang: "ar" });
  });

  it("lists only existing icons, at their real sizes, with a maskable one", () => {
    for (const icon of manifest.icons) {
      expect(existsSync(join(PUBLIC, icon.src)), icon.src).toBe(true);
      if (icon.type === "image/png") {
        expect(pngSize(icon.src).join("x"), icon.src).toBe(icon.sizes);
      }
    }
    const purposes = manifest.icons.map((icon) => `${icon.sizes}:${icon.purpose}`);
    expect(purposes).toEqual(expect.arrayContaining(["192x192:any", "512x512:any", "512x512:maskable"]));
  });

  it("is linked, with the Apple icon, from the Family Portal", () => {
    expect(metadata.manifest).toBe("/manifest.webmanifest");
    expect(JSON.stringify(metadata.icons)).toContain("/icons/apple-touch-icon.png");
    expect(metadata.appleWebApp).toMatchObject({ capable: true });
    expect(viewport.themeColor).toBe("#751BD5");
  });
});
