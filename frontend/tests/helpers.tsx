import type { ReactElement } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render } from "@testing-library/react";
import type { FamilyUser } from "@/lib/api/family-auth";
import type { FamilyHousehold, FamilyMember, FamilyMembers } from "@/lib/api/family-household";

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

/** A synthetic household member (an available ALIVE son unless overridden). */
export function familyMember(overrides: Partial<FamilyMember> = {}): FamilyMember {
  return {
    available: true,
    full_name: "فرد الاختبار",
    relationship: { code: "SON", name: "ابن" },
    is_household_head: false,
    gender: "MALE",
    birth_date: "2010-01-01",
    life_status: "ALIVE",
    ...overrides,
  };
}

/** A synthetic members response: the head and one son unless overridden. */
export function familyMembers(members?: FamilyMember[]): FamilyMembers {
  return {
    family_code: "FAM-000123",
    members: members ?? [
      familyMember({ full_name: "سالم الاختبار", relationship: { code: "HEAD", name: "رب الأسرة" }, is_household_head: true, birth_date: "1980-01-01" }),
      familyMember(),
    ],
  };
}

type Answer = unknown | Error | (() => Promise<unknown>);

/**
 * apiClient.get by path for the Family Portal: /me answers with the user,
 * /household with the summary, /household/members with the members (or an
 * error to throw, or a function for a custom promise). Any other path fails
 * the test loudly.
 */
export function familyGet({
  user = familyUser(),
  household = familyHousehold(),
  members = familyMembers(),
}: { user?: FamilyUser | Error; household?: Answer; members?: Answer } = {}) {
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
    if (path === "/api/v1/family/household/members") {
      return typeof members === "function" || members instanceof Error ? answer(members) : { data: members };
    }
    throw new Error(`Unexpected GET ${path}`);
  };
}
