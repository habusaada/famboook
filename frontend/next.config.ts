import type { NextConfig } from "next";
import { PHASE_DEVELOPMENT_SERVER, PHASE_PRODUCTION_BUILD } from "next/constants";

export default function config(phase: string): NextConfig {
  // NEXT_PUBLIC_* values are inlined at build time. A production build
  // without the API URL would silently fall back to http://localhost:8000,
  // so refuse to build instead (docs/08-PILOT-DEPLOYMENT.md).
  if (phase === PHASE_PRODUCTION_BUILD && !process.env.NEXT_PUBLIC_API_URL) {
    throw new Error("NEXT_PUBLIC_API_URL must be set for a production build (e.g. https://api.famboook.com).");
  }

  return {
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

    async rewrites() {
      if (phase === PHASE_DEVELOPMENT_SERVER) return [];

      // Local development helper only: outside `next dev` it resolves to a
      // path that does not exist, so it answers a real HTTP 404 before any
      // page renders (the page itself also calls notFound()).
      return { beforeFiles: [{ source: "/dev-login", destination: "/__dev-login-unavailable" }], afterFiles: [], fallback: [] };
    },
  };
}
