"use client";

import { useEffect } from "react";
import { registerFamilyServiceWorker } from "@/lib/pwa/family-service-worker";

/**
 * Registers the Family Portal service worker once the page has loaded —
 * production builds only, so development never runs a stale worker. Rendered
 * by app/family/layout.tsx only: the Staff application registers nothing.
 */
export function FamilyServiceWorker() {
  useEffect(() => {
    if (process.env.NODE_ENV !== "production") return;
    void registerFamilyServiceWorker();
  }, []);

  return null;
}
