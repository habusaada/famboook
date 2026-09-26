"use client";

import { useCallback, useEffect, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";

const DEBOUNCE_MS = 350;

/**
 * Registry list state in the URL (?q=…&page=…&…): bookmarkable and
 * back/forward-safe. The search box is debounced; a new search or filter
 * returns to page 1. Search terms are names/codes only — never a National
 * ID (that is an exact POST check, not a search).
 */
export function useRegistrySearch() {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const q = params.get("q") ?? "";
  const page = Math.max(1, Number(params.get("page")) || 1);

  // The input follows the URL on back/forward (adjusting state when the
  // source changes, during render — no effect needed).
  const [text, setText] = useState(q);
  const [syncedQ, setSyncedQ] = useState(q);
  if (q !== syncedQ) {
    setSyncedQ(q);
    setText(q);
  }

  const update = useCallback(
    (patch: Record<string, string>, mode: "push" | "replace" = "push") => {
      const next = new URLSearchParams(params.toString());
      for (const [key, value] of Object.entries(patch)) {
        if (value) next.set(key, value);
        else next.delete(key);
      }
      const query = next.toString();
      router[mode](query ? `${pathname}?${query}` : pathname, { scroll: false });
    },
    [params, pathname, router]
  );

  useEffect(() => {
    const term = text.trim();
    if (term === q) return;
    const timer = setTimeout(() => update({ q: term, page: "" }, "replace"), DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [text, q, update]);

  return {
    text,
    setText,
    q,
    page,
    param: (key: string) => params.get(key) ?? "",
    setPage: (n: number) => update({ page: n > 1 ? String(n) : "" }),
    /** Set a filter (resets to page 1). */
    setFilter: (key: string, value: string) => update({ [key]: value, page: "" }),
    reset: () => {
      setText("");
      router.push(pathname, { scroll: false });
    },
  };
}
