import { mkdirSync, rmSync, writeFileSync } from "node:fs";
import { PHASE_DEVELOPMENT_SERVER, PHASE_PRODUCTION_BUILD, PHASE_PRODUCTION_SERVER } from "next/constants";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { RELEASE_ID_PATTERN, legacyReleasesIn, parseDistDir, releaseTsconfigPath } from "@/lib/release-dir";
import config from "@/next.config";

// FB-DEVOPS-01: isolated build directories and their TypeScript configuration.

const saved = { ...process.env };
beforeEach(() => {
  process.env.NEXT_PUBLIC_API_URL = "https://api.example.invalid";
  delete process.env.NEXT_PUBLIC_LEGAL_EFFECTIVE_DATE;
  vi.spyOn(console, "log").mockImplementation(() => {});
  vi.spyOn(console, "warn").mockImplementation(() => {});
});
// A real generated configuration for a unique test release; only this file is removed.
const RELEASE = `vitest-${process.pid}-${Date.now()}`;
const MISSING = `${RELEASE}-missing`;
const createdTsconfig = `.builds/${RELEASE}.tsconfig.json`;
const plantedLegacy = `.next/${RELEASE}`;
afterEach(() => {
  process.env = { ...saved };
  rmSync(createdTsconfig, { force: true });
  rmSync(plantedLegacy, { recursive: true, force: true });
});
const createReleaseTsconfig = () => {
  mkdirSync(".builds", { recursive: true });
  writeFileSync(createdTsconfig, "{}\n");
};

const withDistDir = (value: string | undefined) => {
  if (value === undefined) delete process.env.FAMBOOOK_NEXT_DIST_DIR;
  else process.env.FAMBOOOK_NEXT_DIST_DIR = value;
};

describe("release identifiers and directories", () => {
  it.each(["a", "0", "fb-web-05", "20261031-1a2b3c4", "a".repeat(40)])("accepts the release %s", (id) => {
    expect(RELEASE_ID_PATTERN.test(id)).toBe(true);
    expect(parseDistDir(`.builds/${id}`)).toEqual({ distDir: `.builds/${id}`, kind: "release" });
  });

  it.each([".next/fb-seo-03-04", ".next/u4-acceptance", " .next/fb-web-04 "])("accepts the legacy directory %j", (value) => {
    expect(parseDistDir(value)).toEqual({ distDir: value.trim(), kind: "legacy" });
  });

  it.each([
    "/abs/path",
    "C:/abs",
    ".builds/../x",
    ".builds/a/b",
    ".builds/",
    ".builds/-x",
    ".builds/UPPER",
    ".builds/a.b",
    ".builds/a b",
    `.builds/${"a".repeat(41)}`,
    "./.builds/x",
    "builds/x",
    ".builds\\x",
    ".next/../x",
    ".next/a/b",
    ".next",
    "..",
    "x",
  ])("refuses %j", (value) => {
    expect(() => parseDistDir(value)).toThrow(/FAMBOOOK_NEXT_DIST_DIR/);
  });

  it("treats an unset or blank value as the default .next", () => {
    expect(parseDistDir(undefined)).toBeNull();
    expect(parseDistDir("  ")).toBeNull();
  });

  it("puts a release's TypeScript configuration next to it, outside the folder Next.js empties", () => {
    expect(releaseTsconfigPath(".builds/r1")).toBe(".builds/r1.tsconfig.json");
  });
});

describe("next.config.ts", () => {
  it("builds a release with its own TypeScript configuration", () => {
    withDistDir(`.builds/${RELEASE}`);
    createReleaseTsconfig();
    const built = config(PHASE_PRODUCTION_BUILD);
    expect(built.distDir).toBe(`.builds/${RELEASE}`);
    expect(built.typescript).toEqual({ tsconfigPath: createdTsconfig });
    expect(built.typescript?.ignoreBuildErrors).toBeUndefined();
  });

  it("refuses to build a release without its generated configuration, so tsconfig.json is never edited", () => {
    withDistDir(`.builds/${MISSING}`);
    expect(() => config(PHASE_PRODUCTION_BUILD)).toThrow(/build-frontend\.sh/);
  });

  it("refuses a development server on a release without its generated configuration", () => {
    withDistDir(`.builds/${MISSING}`);
    expect(() => config(PHASE_DEVELOPMENT_SERVER)).toThrow(/build-frontend\.sh/);
  });

  it("refuses a default build while a legacy release is in .next/, and only then", () => {
    mkdirSync(plantedLegacy, { recursive: true });
    withDistDir(undefined);
    expect(() => config(PHASE_PRODUCTION_BUILD)).not.toThrow(); // a folder with no build
    expect(legacyReleasesIn(".next")).not.toContain(plantedLegacy);
    writeFileSync(`${plantedLegacy}/BUILD_ID`, "x\n");
    expect(legacyReleasesIn(".next")).toContain(plantedLegacy);
    expect(() => config(PHASE_PRODUCTION_BUILD)).toThrow(new RegExp(`would delete .*${RELEASE}.*build-frontend\\.sh`));
    expect(() => config(PHASE_PRODUCTION_SERVER)).not.toThrow();
    withDistDir(".next/fb-seo-03-04");
    expect(() => config(PHASE_PRODUCTION_BUILD)).not.toThrow();
  });

  it("serves a release even when its configuration is gone", () => {
    withDistDir(`.builds/${MISSING}`);
    const served = config(PHASE_PRODUCTION_SERVER);
    expect(served.distDir).toBe(`.builds/${MISSING}`);
    expect(served.typescript).toBeUndefined();
  });

  it("keeps serving and developing in a legacy .next/<id>, with the default configuration", () => {
    withDistDir(".next/fb-seo-03-04");
    expect(config(PHASE_PRODUCTION_SERVER)).toMatchObject({ distDir: ".next/fb-seo-03-04" });
    expect(config(PHASE_PRODUCTION_SERVER).typescript).toBeUndefined();
    withDistDir(".next/u4-acceptance");
    expect(config(PHASE_DEVELOPMENT_SERVER).distDir).toBe(".next/u4-acceptance");
  });

  it("warns when a legacy directory is built", () => {
    withDistDir(".next/fb-seo-03-04");
    config(PHASE_PRODUCTION_BUILD);
    expect(console.warn).toHaveBeenCalledWith(expect.stringMatching(/legacy .*build-frontend\.sh/));
  });

  it("changes nothing when unset", () => {
    withDistDir(undefined);
    const built = config(PHASE_PRODUCTION_BUILD);
    expect(built.distDir).toBeUndefined();
    expect(built.typescript).toBeUndefined();
  });

  it("fails on an unsafe value before anything is built", () => {
    withDistDir(".builds/../../etc");
    expect(() => config(PHASE_PRODUCTION_BUILD)).toThrow(/FAMBOOOK_NEXT_DIST_DIR/);
  });
});
