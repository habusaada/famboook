// FB-DEVOPS-01: where an isolated Next.js build is written
// (docs/deployment/FB-DEVOPS-01-ISOLATED-FRONTEND-BUILDS.md).
//
// Releases live in .builds/<id>, outside .next/: a default `next build` empties
// .next/ and would delete them. deploy/scripts/build-frontend.sh builds a
// release with the generated .builds/<id>.tsconfig.json. .next/<id> is still
// accepted for the builds made before (Production until it is migrated, and the
// FB-CIVIL-01A-UI U4 development server).

import { existsSync, readdirSync } from "node:fs";

/** The same rule as deploy/scripts/build-frontend.sh. */
export const RELEASE_ID_PATTERN = /^[a-z0-9][a-z0-9-]{0,39}$/;
const LEGACY_DIST_DIR_PATTERN = /^\.next\/[a-z0-9-]{1,40}$/;

export type BuildDir = { distDir: string; kind: "release" | "legacy" };

/** The validated FAMBOOOK_NEXT_DIST_DIR, or null when unset. Throws on anything else. */
export function parseDistDir(value: string | undefined): BuildDir | null {
  const distDir = (value ?? "").trim();
  if (!distDir) return null;
  if (distDir.startsWith(".builds/") && RELEASE_ID_PATTERN.test(distDir.slice(".builds/".length))) {
    return { distDir, kind: "release" };
  }
  if (LEGACY_DIST_DIR_PATTERN.test(distDir)) return { distDir, kind: "legacy" };
  throw new Error(
    "FAMBOOOK_NEXT_DIST_DIR must be .builds/<release-id> (lowercase letters, digits and '-', 1–40 characters, starting with a letter or digit), or a legacy .next/<id>.",
  );
}

/** The generated TypeScript configuration of a release, relative to frontend/. */
export function releaseTsconfigPath(distDir: string): string {
  return `${distDir}.tsconfig.json`;
}

/** The built releases inside a legacy .next/: a default `next build` would delete them. */
export function legacyReleasesIn(nextDir: string): string[] {
  if (!existsSync(nextDir)) return [];
  return readdirSync(nextDir, { withFileTypes: true })
    .filter((entry) => entry.isDirectory() && existsSync(`${nextDir}/${entry.name}/BUILD_ID`))
    .map((entry) => `${nextDir}/${entry.name}`);
}
