import type { ReactElement } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render } from "@testing-library/react";
import { vi } from "vitest";
import { apiClient } from "@/lib/api/client";
import type { CoordinatorContext } from "@/lib/api/coordinator";
import type { FamilyAccount } from "@/lib/api/family-account";
import type { FamilyAuthCapabilities, FamilyUser } from "@/lib/api/family-auth";
import type {
  FamilyDelivery,
  FamilyHousehold,
  FamilyHouseholdHealth,
  FamilyMember,
  FamilyMembers,
  FamilyNeed,
  FamilyProfile,
} from "@/lib/api/family-household";
import type { FamilySelf } from "@/lib/api/family-self";

/** Renders inside a fresh, non-retrying query client. */
export function renderWithClient(ui: ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return { client, ...render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>) };
}

export const CAPABILITIES_PATH = "/api/v1/family/auth/capabilities";

/** The three global Family Auth capabilities (FU-14); all open unless overridden. */
export function familyAuthCapabilities(overrides: Partial<FamilyAuthCapabilities> = {}): FamilyAuthCapabilities {
  return { activation: true, login: true, password_reset: true, ...overrides };
}

/**
 * Answers GET /api/v1/family/auth/capabilities: the capabilities, an error
 * to reject with, or "pending" (never settles). Any other GET fails the test.
 */
export function mockFamilyAuthCapabilities(reply: FamilyAuthCapabilities | Error | "pending") {
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path !== CAPABILITIES_PATH) throw new Error(`Unexpected GET: ${path}`);
    if (reply === "pending") return new Promise<never>(() => {});
    if (reply instanceof Error) throw reply;
    return { data: reply } as never;
  });
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

/**
 * A well-formed but meaningless member_ref for fixtures: 64 lowercase hex
 * characters derived from the seed, so the same synthetic member always gets
 * the same reference.
 */
export function syntheticRef(seed: string): string {
  const hex = Array.from(seed, (c) => (c.codePointAt(0) ?? 0).toString(16)).join("");
  return hex.slice(-64).padStart(64, "0");
}

/** A synthetic household member (an available ALIVE son unless overridden). */
export function familyMember(overrides: Partial<FamilyMember> = {}): FamilyMember {
  const member: Omit<FamilyMember, "member_ref"> = {
    available: true,
    full_name: "فرد الاختبار",
    relationship: { code: "SON", name: "ابن" },
    is_household_head: false,
    gender: "MALE",
    birth_date: "2010-01-01",
    marital_status: "SINGLE",
    life_status: "ALIVE",
    death_date: null,
    national_id_masked: "*****4321",
    mobile_masked: null,
    alternate_mobile_masked: null,
    alternate_mobile_owner_relation: null,
    membership_started_at: "2010-01-01",
    ...overrides,
  };

  return {
    // Deterministic per synthetic member unless a test sets its own.
    member_ref: overrides.member_ref ?? syntheticRef(`${member.full_name}|${member.relationship?.code}|${member.birth_date}`),
    ...member,
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

/** A synthetic, complete «أسرتي» record; nothing here is real registry data. */
export function familyProfile(
  family: Partial<FamilyProfile["family"]> = {},
  residence: Partial<NonNullable<FamilyProfile["residence"]>> | null = {},
  declaration: Partial<NonNullable<FamilyProfile["declaration"]>> | null = {}
): FamilyProfile {
  return {
    family: {
      family_code: "FAM-000123",
      clan_name: "عائلة الاختبار",
      branch_group_name: "مجموعة الاختبار",
      branch_name: "فرع الاختبار",
      head: { full_name: "سالم الاختبار" },
      registration_date: "2011-11-11",
      paper_form_no: "PF-7788",
      registered_member_count: 2,
      ...family,
    },
    declaration:
      declaration === null
        ? null
        : {
            declared_household_size: 7,
            declared_living_sons: 3,
            declared_living_daughters: 2,
            declared_at: "2026-09-01",
            source: "PAPER_FORM",
            ...declaration,
          },
    residence:
      residence === null
        ? null
        : {
            original_residence_text: "بني سهيلا – خانيونس",
            displacement_status: "DISPLACED",
            displacement_location_text: "مواصي خانيونس",
            residence_type: "خيمة",
            started_at: "2024-01-02",
            current_address: {
              governorate: "خانيونس",
              city: "خانيونس",
              area: "المواصي",
              neighborhood: "حي الاختبار",
              address_text: "قرب مسجد الاختبار",
            },
            ...residence,
          },
  };
}

/** A synthetic «بياناتي الشخصية» payload, masked as the server sends it. */
export function familySelf(overrides: Partial<FamilySelf> = {}): FamilySelf {
  return {
    full_name: "سالم أحمد الاختبار",
    national_id_masked: "*****6789",
    gender: "MALE",
    birth_date: "1980-01-15",
    marital_status: "MARRIED",
    mobile_masked: "05*****567",
    alternate_mobile_masked: "05*****321",
    alternate_mobile_owner_relation: "أخ",
    relationship: { code: "HEAD", name: "رب الأسرة" },
    is_household_head: true,
    membership_started_at: "2001-03-04",
    ...overrides,
  };
}

/** Synthetic «حسابي» account facts (PWA-3B.5); nothing here is real data. */
export function familyAccount(overrides: Partial<FamilyAccount> = {}): FamilyAccount {
  return {
    activated_at: "2026-10-01T09:00:00+03:00",
    mobile: { state: "TRUSTED", masked: "05*****567" },
    ...overrides,
  };
}

/** A synthetic coordinator context: one clan scope. */
export function coordinatorContext(overrides: Partial<CoordinatorContext> = {}): CoordinatorContext {
  return {
    scopes: [{ type: "CLAN", code: "TEST_CLAN", name: "عشيرة الاختبار", clan_name: "عشيرة الاختبار" }],
    family_count: 12,
    ...overrides,
  };
}

/** Synthetic household health (PWA-3B.6); by default nothing is registered. */
export function familyHealth(members: FamilyHouseholdHealth["members"] = []): FamilyHouseholdHealth {
  return { members };
}

/** A synthetic registered need (PWA-3B.7); nothing here is real data. */
export function familyNeed(overrides: Partial<FamilyNeed> = {}): FamilyNeed {
  return {
    category: { code: "FOOD", name: "الغذاء" },
    title: "سلة غذائية",
    quantity: "2",
    unit: "سلة",
    status: "OPEN",
    created_at: "2026-09-30",
    resolved_at: null,
    person: null,
    ...overrides,
  };
}

/** A synthetic received delivery (PWA-3B.7); nothing here is real data. */
export function familyDelivery(overrides: Partial<FamilyDelivery> = {}): FamilyDelivery {
  return {
    delivered_at: "2026-09-15",
    assistance: {
      title: "سلة رمضان",
      category: { code: "FOOD", name: "الغذاء" },
      type: "IN_KIND",
      provider_name: "جهة تجريبية",
      items: [{ item_name: "أرز", quantity: "5", unit: "كغ", unit_value: "4.5", currency: "ILS" }],
    },
    beneficiary: null,
    receipt_mode: "PERSONAL",
    recipient: { full_name: "سالم الاختبار", available: true },
    ...overrides,
  };
}

type Answer = unknown | Error | (() => Promise<unknown>);

/**
 * apiClient.get by path for the Family Portal: /me answers with the user,
 * /household with the summary, /household/members with the members,
 * /household/profile with the «أسرتي» profile, /household/health,
 * /household/needs, /household/assistance, /self,
 * /account and /coordinator/context likewise (or an error to throw, or a function for a
 * custom promise). Any other path fails the test loudly.
 */
export function familyGet({
  user = familyUser(),
  household = familyHousehold(),
  members = familyMembers(),
  profile = familyProfile(),
  self = familySelf(),
  account = familyAccount(),
  coordinator = coordinatorContext(),
  health = familyHealth(),
  needs = [] as unknown,
  assistance = [] as unknown,
}: {
  user?: FamilyUser | Error;
  household?: Answer;
  members?: Answer;
  profile?: Answer;
  self?: Answer;
  account?: Answer;
  coordinator?: Answer;
  health?: Answer;
  needs?: Answer;
  assistance?: Answer;
} = {}) {
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
    if (path === "/api/v1/family/household/profile") {
      return typeof profile === "function" || profile instanceof Error ? answer(profile) : { data: profile };
    }
    if (path === "/api/v1/family/household/needs") {
      return typeof needs === "function" || needs instanceof Error ? answer(needs) : { data: { needs } };
    }
    if (path === "/api/v1/family/household/assistance") {
      return typeof assistance === "function" || assistance instanceof Error ? answer(assistance) : { data: { deliveries: assistance } };
    }
    if (path === "/api/v1/family/household/health") {
      return typeof health === "function" || health instanceof Error ? answer(health) : { data: health };
    }
    if (path === "/api/v1/family/self") {
      return typeof self === "function" || self instanceof Error ? answer(self) : { data: self };
    }
    if (path === "/api/v1/family/account") {
      return typeof account === "function" || account instanceof Error ? answer(account) : { data: account };
    }
    if (path === "/api/v1/family/coordinator/context") {
      return typeof coordinator === "function" || coordinator instanceof Error ? answer(coordinator) : { data: coordinator };
    }
    throw new Error(`Unexpected GET ${path}`);
  };
}
