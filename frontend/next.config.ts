import { existsSync } from "node:fs";
import type { NextConfig } from "next";
import { PHASE_DEVELOPMENT_SERVER, PHASE_PRODUCTION_BUILD, PHASE_PRODUCTION_SERVER } from "next/constants";
import { legacyReleasesIn, parseDistDir, releaseTsconfigPath } from "./lib/release-dir";
import { assertPublishableEffectiveDate } from "./lib/legal/effective-date";

export default function config(phase: string): NextConfig {
  // NEXT_PUBLIC_* values are inlined at build time. A production build
  // without the API URL would silently fall back to http://localhost:8000,
  // so refuse to build instead (docs/08-PILOT-DEPLOYMENT.md).
  if (phase === PHASE_PRODUCTION_BUILD && !process.env.NEXT_PUBLIC_API_URL) {
    throw new Error("NEXT_PUBLIC_API_URL must be set for a production build (e.g. https://api.famboook.com).");
  }

  // Legal policies (lib/legal/effective-date.ts): a production build with an
  // effective date publishes the approved policies with it, so the date must
  // be real, not before the authorization and not in the future. Unset: the
  // policies stay unpublished. The build log states which case applies.
  const effectiveDate =
    phase === PHASE_PRODUCTION_BUILD
      ? assertPublishableEffectiveDate(process.env.NEXT_PUBLIC_LEGAL_EFFECTIVE_DATE)
      : (process.env.NEXT_PUBLIC_LEGAL_EFFECTIVE_DATE ?? "").trim() || null;
  if (phase === PHASE_PRODUCTION_BUILD) {
    console.log(
      effectiveDate
        ? `Legal policies: PUBLISHED (privacy, terms, data-protection) with effective date ${effectiveDate} (version 1.0).`
        : "Legal policies: NOT PUBLISHED (NEXT_PUBLIC_LEGAL_EFFECTIVE_DATE is not set).",
    );
  }

  // Optional isolated build output (lib/release-dir.ts; FB-DEVOPS-01). Unset,
  // the default .next is used. A release in .builds/<id> is type-checked with its
  // generated .builds/<id>.tsconfig.json: only that release's generated types, and
  // Next.js never edits the tracked tsconfig.json. A release is built only through
  // deploy/scripts/build-frontend.sh, which writes that file first.
  const buildDir = parseDistDir(process.env.FAMBOOOK_NEXT_DIST_DIR);
  let tsconfigPath: string | undefined;
  if (buildDir?.kind === "release") {
    const releaseTsconfig = releaseTsconfigPath(buildDir.distDir);
    if (existsSync(releaseTsconfig)) {
      tsconfigPath = releaseTsconfig;
    } else if (phase !== PHASE_PRODUCTION_SERVER) {
      throw new Error(`${releaseTsconfig} is missing: build releases with deploy/scripts/build-frontend.sh.`);
    }
  }
  if (buildDir?.kind === "legacy" && phase === PHASE_PRODUCTION_BUILD) {
    console.warn(`Building into the legacy ${buildDir.distDir}: a default \`next build\` deletes it. Use deploy/scripts/build-frontend.sh.`);
  }

  // A default build empties .next/, including any release still served from it.
  if (!buildDir && phase === PHASE_PRODUCTION_BUILD) {
    const releases = legacyReleasesIn(".next");
    if (releases.length > 0) {
      throw new Error(`A default \`next build\` would delete ${releases.join(", ")}. Use deploy/scripts/build-frontend.sh.`);
    }
  }

  return {
    ...(buildDir ? { distDir: buildDir.distDir } : {}),
    ...(tsconfigPath ? { typescript: { tsconfigPath } } : {}),

    // Always inlined as a literal (the validated date or ""), so an unset date
    // lets the bundler drop the unpublished policy text from the client code.
    env: { NEXT_PUBLIC_LEGAL_EFFECTIVE_DATE: effectiveDate ?? "" },

    // Public Digital Family Card verification (docs/11 §19, FP-ADR-070): the
    // URL carries the opaque token, so the page is never cached, indexed or
    // sent as a referrer.
    async headers() {
      return [
        {
          source: "/verify/:path*",
          headers: [
            { key: "Cache-Control", value: "no-store" },
            { key: "X-Robots-Tag", value: "noindex, nofollow" },
            { key: "Referrer-Policy", value: "no-referrer" },
          ],
        },
      ];
    },

    // FB-WEB-04: the landing page moved from /welcome to the homepage, which
    // belongs to famboook.com. app.famboook.com shares this process (nginx
    // passes Host) and opens on the Family Portal. Temporary, so the entry
    // point can change without browsers keeping the old answer.
    async redirects() {
      return [
        { source: "/", has: [{ type: "host", value: "app\\.famboook\\.com" }], destination: "/family", permanent: false },
        { source: "/welcome", destination: "/", permanent: true },
      ];
    },

    async rewrites() {
      if (phase === PHASE_DEVELOPMENT_SERVER) return [];

      // Local development helper only: outside `next dev` it resolves to a
      // path that does not exist, so it answers a real HTTP 404 before any
      // page renders (the page itself also calls notFound()).
      return { beforeFiles: [{ source: "/dev-login", destination: "/__dev-login-unavailable" }], afterFiles: [], fallback: [] };
    },
  };
}
