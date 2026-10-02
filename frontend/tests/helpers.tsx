import type { ReactElement } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render } from "@testing-library/react";
import type { FamilyUser } from "@/lib/api/family-auth";

/** Renders inside a fresh, non-retrying query client. */
export function renderWithClient(ui: ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return { client, ...render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>) };
}

/** A synthetic family-side user; nothing here is real registry data. */
export function familyUser(overrides: Partial<FamilyUser> = {}): FamilyUser {
  return {
    display_name: "سالم الاختبار",
    roles: ["FAMILY_USER"],
    coordinator: false,
    context: { available: true, family: { code: "FAM-000123", name: "فرع الاختبار" } },
    ...overrides,
  };
}

/** Everything a test may have left in browser storage. */
export function browserStorageDump(): string {
  const dump = (storage: Storage) =>
    Array.from({ length: storage.length }, (_, i) => `${storage.key(i)}=${storage.getItem(storage.key(i) ?? "")}`).join("\n");

  return `${dump(window.localStorage)}\n${dump(window.sessionStorage)}\n${document.cookie}`;
}
