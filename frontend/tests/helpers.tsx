import type { ReactElement } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render } from "@testing-library/react";
import type { FamilyUser } from "@/lib/api/family-auth";
import type { FamilyHousehold } from "@/lib/api/family-household";

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
    coordinator_space: false,
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

/** A synthetic household summary; nothing here is real registry data. */
export function familyHousehold(overrides: Partial<FamilyHousehold> = {}): FamilyHousehold {
  return {
    family_code: "FAM-000123",
    clan_name: "عائلة الاختبار",
    branch_name: "فرع الاختبار",
    head: { full_name: "سالم الاختبار" },
    declared_household_size: 7,
    declared_at: "2026-09-01",
    registered_member_count: 2,
    ...overrides,
  };
}

type Answer = unknown | Error | (() => Promise<unknown>);

/**
 * apiClient.get by path for the Family Portal: /me answers with the user,
 * /household with the summary (or an error to throw, or a function for a
 * custom promise). Any other path fails the test loudly.
 */
export function familyGet({ user = familyUser(), household = familyHousehold() }: { user?: FamilyUser | Error; household?: Answer } = {}) {
  const answer = async (value: Answer) => {
    if (typeof value === "function") return (value as () => Promise<unknown>)();
    if (value instanceof Error) throw value;
    return value;
  };

  return async (path: string) => {
    if (path === "/api/v1/family/me") return answer(user instanceof Error ? user : { user });
    if (path === "/api/v1/family/household") {
      return typeof household === "function" || household instanceof Error ? answer(household) : { data: household };
    }
    throw new Error(`Unexpected GET ${path}`);
  };
}
