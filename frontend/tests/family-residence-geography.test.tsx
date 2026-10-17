import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyResidenceUpdateForm } from "@/components/family/requests/family-residence-update-form";
import { ApiError, apiClient } from "@/lib/api/client";
import type { FamilyProfile } from "@/lib/api/family-household";
import type { GazaLocation, GazaLocationDetails, GazaLocationSummary } from "@/lib/types/api/gaza-location";
import { renderWithClient } from "./helpers";

// RESIDENCE_UPDATE in the Family PWA (FB-GEO-01 v2, payload v3 since
// FB-GEO-02): the residence form's two GazaLocationSelectors (source
// "family") — prefilled from the profile's summaries, sending only CHANGED
// references as UUIDs (or an explicit null), with the staged replace / clear
// semantics. Only the family mirrors are ever read. Synthetic data.

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => "/family/requests/new/residence-update",
  useSearchParams: () => new URLSearchParams(),
}));

beforeAll(() => {
  Object.assign(Element.prototype, {
    hasPointerCapture: () => false,
    releasePointerCapture: () => undefined,
    scrollIntoView: () => undefined,
  });
});

beforeEach(() => {
  vi.restoreAllMocks();
});

// Families never get landmark_type: the fixtures omit it.
const G1: GazaLocation = { id: "00000000-0000-4000-8000-000000000101", level: "GOVERNORATE", name: "محافظة تجريبية", parent_id: null, has_children: true };
const C1: GazaLocation = { id: "00000000-0000-4000-8000-000000000102", level: "CITY", name: "مدينة تجريبية", parent_id: G1.id, has_children: true };
const A1: GazaLocation = { id: "00000000-0000-4000-8000-000000000103", level: "AREA", name: "منطقة تجريبية", parent_id: C1.id, has_children: false };
const OLD: GazaLocation = { id: "00000000-0000-4000-8000-000000000104", level: "AREA", name: "منطقة قديمة", parent_id: C1.id, has_children: false };

const FAMILY = "/api/v1/family/change-requests/gaza-locations";
const SUBMIT = "/api/v1/family/change-requests";

function details(node: GazaLocation, inactive = false): GazaLocationDetails {
  return { ...node, is_active: !inactive, path: [G1, C1].map((p) => ({ id: p.id, level: p.level, name: p.name, is_active: true })) };
}

function summary(node: GazaLocation, inactive = false): GazaLocationSummary {
  const { id, level, name, is_active, path } = details(node, inactive);
  return { id, level, name, is_active, path };
}

type Residence = NonNullable<FamilyProfile["residence"]>;

function profile(overrides: Partial<Residence> = {}): FamilyProfile {
  return {
    family: {
      family_code: "FAM-TEST-1", clan_name: null, branch_group_name: null, branch_name: null,
      head: { full_name: "رب أسرة تجريبي" }, registration_date: null, paper_form_no: null, registered_member_count: 3,
    },
    declaration: null,
    residence: {
      original_residence_text: "بني سهيلا", displacement_status: "DISPLACED", displacement_location_text: "مواصي",
      residence_type: null, started_at: null,
      current_address: { governorate: "خانيونس", city: "خانيونس", area: null, neighborhood: null, address_text: "شارع 1" },
      original_location: summary(OLD, true),
      current_location: null,
      ...overrides,
    },
  };
}

function mockApi(prof = profile()) {
  const get = vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path.endsWith("/change-requests/types")) return { data: [{ type: "RESIDENCE_UPDATE" }], meta: { submission_enabled: true } } as never;
    if (path === "/api/v1/family/household/profile") return { data: prof } as never;
    const routes: Record<string, unknown> = {
      [`${FAMILY}/governorates`]: { data: [G1] },
      [`${FAMILY}/${G1.id}/children`]: { data: [C1] },
      [`${FAMILY}/${C1.id}/children`]: { data: [A1] },
      [`${FAMILY}/${OLD.id}`]: { data: details(OLD, true) },
      [`${FAMILY}/${A1.id}`]: { data: details(A1) },
    };
    if (path in routes) return routes[path] as never;
    throw new ApiError(404, { message: "Not Found." });
  });
  const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: { id: "5b1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4e", request_code: "CRQ-1", status: "SUBMITTED", replayed: false } } as never);
  return { get, post };
}

async function renderForm() {
  const user = userEvent.setup();
  renderWithClient(<FamilyResidenceUpdateForm />);
  await screen.findByRole("button", { name: "إرسال الطلب للمراجعة" });
  return user;
}

const originalGroup = () => screen.getByRole("group", { name: /الموقع الجغرافي للسكن الفعلي قبل النزوح/ });
const currentGroup = () => screen.getByRole("group", { name: /الموقع الجغرافي للسكن الحالي/ });

async function pick(user: ReturnType<typeof userEvent.setup>, scope: HTMLElement, label: RegExp, option: string) {
  const trigger = await waitFor(() => {
    const element = within(scope).getByRole("combobox", { name: label });
    expect(element).toBeEnabled();
    return element;
  });
  await user.click(trigger);
  await user.click(await screen.findByRole("option", { name: option }));
}

async function submit(user: ReturnType<typeof userEvent.setup>, post: { mock: { calls: unknown[][] } }) {
  await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
  return waitFor(() => {
    const call = post.mock.calls.find(([path]) => path === SUBMIT);
    expect(call).toBeDefined();
    return (call![1] as { data: Record<string, unknown> }).data;
  });
}

describe("Family PWA residence form: geographic references", { timeout: 20_000 }, () => {
  it("shows the saved inactive original location read-only and an empty current selector", async () => {
    mockApi();
    await renderForm();

    const saved = await within(originalGroup()).findByRole("group", { name: "الموقع المحفوظ" });
    expect(within(saved).getByText(OLD.name)).toBeInTheDocument();
    expect(within(saved).getByText("غير مفعّلة")).toBeInTheDocument();
    expect(within(currentGroup()).getByRole("combobox", { name: /^المحافظة/ })).toHaveTextContent("اختر المحافظة");
    // The identity-card residence is shown read-only beside the selectors.
    expect(document.querySelector("[data-residence-update-readonly='identity-card']")).toHaveTextContent("بني سهيلا");
  });

  it("sends only the new current location as its UUID, omits the unchanged inactive original, and reads only family endpoints", async () => {
    const { get, post } = mockApi();
    const user = await renderForm();

    await pick(user, currentGroup(), /^المحافظة/, G1.name);
    await pick(user, currentGroup(), /^المدينة/, C1.name);
    await pick(user, currentGroup(), /^المنطقة/, A1.name);
    const data = await submit(user, post);

    expect(data).toEqual({ current_location_id: A1.id });
    const geography = get.mock.calls.map(([path]) => String(path)).filter((path) => path.includes("gaza-locations"));
    expect(geography.length).toBeGreaterThan(0);
    expect(geography.every((path) => path.startsWith(FAMILY))).toBe(true);
  });

  it("a cancelled replacement changes nothing, so the proposal is refused locally as unchanged", async () => {
    const { post } = mockApi();
    const user = await renderForm();
    const group = originalGroup();
    await within(group).findByRole("group", { name: "الموقع المحفوظ" });

    await user.click(within(group).getByRole("button", { name: "تغيير" }));
    await pick(user, group, /^المحافظة/, G1.name);
    await user.click(within(group).getByRole("button", { name: "إلغاء" }));
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    expect(await screen.findByText(/لم تغيّر أي بيانات/)).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it("a confirmed clear sends null", async () => {
    const { post } = mockApi();
    const user = await renderForm();
    const group = originalGroup();
    await within(group).findByRole("group", { name: "الموقع المحفوظ" });

    await user.click(within(group).getByRole("button", { name: "مسح الاختيار" }));
    await user.click(within(group).getByRole("button", { name: "تأكيد المسح" }));
    const data = await submit(user, post);

    expect(data).toEqual({ original_location_id: null });
  });

  it("maps the server's location errors onto the selectors", async () => {
    const { post } = mockApi();
    post.mockRejectedValueOnce(new ApiError(422, { message: "x", errors: { current_location_id: ["الموقع الجغرافي المحدد غير متاح للاختيار."] } }));
    const user = await renderForm();

    await pick(user, currentGroup(), /^المحافظة/, G1.name);
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    expect(await within(currentGroup()).findByText("الموقع الجغرافي المحدد غير متاح للاختيار.")).toBeInTheDocument();
  });
});
