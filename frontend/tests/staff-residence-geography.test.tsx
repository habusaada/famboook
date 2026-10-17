import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import NewFamilyPage from "@/app/(staff)/families/new/page";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import { EditCurrentResidenceDialog, EditDisplacementDialog } from "@/components/families/edit-residence-dialogs";
import { FamilyResidenceTab } from "@/components/families/family-residence-tab";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CurrentUser } from "@/lib/api/auth";
import type { FamilyDetail, RegisterFamilyPayload, UpdateFamilyResidencePayload } from "@/lib/types/api/family";
import type { GazaLocation, GazaLocationDetails, GazaLocationLevel, GazaLocationSummary } from "@/lib/types/api/gaza-location";
import { renderWithClient } from "./helpers";

// FB-GEO-01 Stage 4B: the Gaza location selector in the Staff registration
// form, the Staff residence correction dialogs, and the residence display.
// SYNTHETIC hierarchy and family; names are placeholders.

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }), usePathname: () => "/families/new" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
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

// ------------------------------------------------------------------ fixtures

let seq = 0;
function node(level: GazaLocationLevel, name: string, parent: GazaLocation | null, hasChildren: boolean): GazaLocation {
  seq += 1;
  return {
    id: `00000000-0000-4000-8000-${String(seq).padStart(12, "0")}`,
    level,
    name,
    parent_id: parent?.id ?? null,
    has_children: hasChildren,
    landmark_type: null,
  };
}

// One governorate on purpose: a single option is never auto-selected.
const G1 = node("GOVERNORATE", "محافظة تجريبية", null, true);
const C1 = node("CITY", "مدينة أولى", G1, true);
const C2 = node("CITY", "مدينة ثانية", G1, false);
const A1 = node("AREA", "منطقة أولى", C1, false);
// Saved but no longer active (not in any active list).
const OLD = node("AREA", "منطقة قديمة", C1, false);

const BASE = "/api/v1/gaza-locations";

function details(target: GazaLocation, path: GazaLocation[], inactive: string[] = []): GazaLocationDetails {
  return {
    ...target,
    is_active: !inactive.includes(target.id),
    path: path.map((p) => ({ id: p.id, level: p.level, name: p.name, is_active: !inactive.includes(p.id) })),
  };
}

function summary(target: GazaLocation, path: GazaLocation[], inactive: string[] = []): GazaLocationSummary {
  const { id, level, name, is_active, path: items } = details(target, path, inactive);
  return { id, level, name, is_active, path: items };
}

const CLAN = { id: "1", code: "CLN-TEST", name: "عائلة تجريبية", is_active: true, branch_groups: [], ungrouped_branches: [] };

function mockGets() {
  const routes: Record<string, unknown> = {
    "/api/v1/reference/clans": { data: [CLAN] },
    [`${BASE}/governorates`]: { data: [G1] },
    [`${BASE}/${G1.id}/children`]: { data: [C1, C2] },
    [`${BASE}/${C1.id}/children`]: { data: [A1] },
    [`${BASE}/${A1.id}`]: { data: details(A1, [G1, C1]) },
    [`${BASE}/${C2.id}`]: { data: details(C2, [G1]) },
    [`${BASE}/${OLD.id}`]: { data: details(OLD, [G1, C1], [OLD.id]) },
  };
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (!(path in routes)) throw new ApiError(404, { message: "Not Found." });
    return routes[path] as never;
  });
}

function staff(permissions: string[]): CurrentUser {
  return { name: "موظف تجريبي", email: "staff@example.test", role: "DATA_ENTRY", role_label: "مدخل بيانات", permissions };
}

type Residence = NonNullable<FamilyDetail["residence"]>;

function residence(overrides: Partial<Residence> = {}): Residence {
  return {
    governorate: "نص محافظة",
    city: "نص مدينة",
    area: null,
    neighborhood: null,
    address_text: "عنوان تفصيلي تجريبي",
    residence_type: null,
    started_at: null,
    original_residence_text: "نص السكن الأصلي",
    displacement_status: "DISPLACED",
    displacement_location_text: "نص مكان النزوح",
    original_location_id: OLD.id,
    current_location_id: A1.id,
    original_location: summary(OLD, [G1, C1], [OLD.id]),
    current_location: summary(A1, [G1, C1]),
    ...overrides,
  };
}

// ------------------------------------------------------------------ helpers

async function combo(scope: HTMLElement, label: RegExp): Promise<HTMLElement> {
  return waitFor(() => {
    const element = within(scope).getByRole("combobox", { name: label });
    expect(element).toBeEnabled();
    return element;
  });
}

async function pick(user: ReturnType<typeof userEvent.setup>, scope: HTMLElement, label: RegExp, option: string) {
  await user.click(await combo(scope, label));
  await user.click(await screen.findByRole("option", { name: option }));
}

const originalGroup = () => screen.getByRole("group", { name: /الموقع الجغرافي للسكن الأصلي/ });
const currentGroup = () => screen.getByRole("group", { name: /الموقع الجغرافي (للسكن الحالي|الحالي \/ مكان النزوح)/ });

// ============================================================ registration

// Full-form flows with many real user events: an explicit timeout keeps them
// reliable on a loaded machine (Stage 4C).
describe("Staff registration: geographic references", { timeout: 20_000 }, () => {
  async function renderPage() {
    mockGets();
    const post = vi.spyOn(apiClient, "post").mockImplementation(async (path: string) => {
      if (path === "/api/v1/people/national-id-check") return { data: { exists: false, matches: [] } } as never;
      if (path === "/api/v1/families") return { data: { family_code: "FAM-000900" } } as never;
      throw new Error(`Unexpected POST: ${path}`);
    });
    const user = userEvent.setup();
    renderWithClient(
      <AuthContext.Provider value={authValue(staff(["family.create"]))}>
        <NewFamilyPage />
      </AuthContext.Provider>
    );
    // Required non-geographic fields (the single Clan is preselected).
    await user.type(screen.getByLabelText(/الاسم الكامل/), "رب أسرة تجريبي");
    await user.type(screen.getByLabelText(/رقم الهوية الوطنية/), "401234567");
    await user.click(screen.getByText("ذكر"));
    // Free text, recorded as the form says.
    await user.type(screen.getByLabelText("المحافظة", { selector: "input" }), "نص محافظة");
    await user.type(screen.getByLabelText(/مكان السكن الأصلي/), "نص السكن الأصلي");
    return { post, user };
  }

  async function submit(user: ReturnType<typeof userEvent.setup>, post: { mock: { calls: unknown[][] } }) {
    await user.click(document.querySelector("[data-register-submit]") as HTMLElement);
    return waitFor(() => {
      const call = post.mock.calls.find(([path]) => path === "/api/v1/families");
      expect(call).toBeDefined();
      return (call![1] as RegisterFamilyPayload).residence;
    });
  }

  it("offers two independent, empty selectors and never auto-selects a single option", async () => {
    await renderPage();

    expect(await combo(originalGroup(), /^المحافظة/)).toHaveTextContent("اختر المحافظة");
    expect(await combo(currentGroup(), /^المحافظة/)).toHaveTextContent("اختر المحافظة");
    expect(document.querySelector("[data-location-text-note]")).toHaveTextContent("لا يحلّ محلّه");
  });

  it("sends null for both references when none is chosen, with the text unchanged", async () => {
    const { post, user } = await renderPage();

    const res = await submit(user, post);
    expect(res.original_location_id).toBeNull();
    expect(res.current_location_id).toBeNull();
    expect(res.governorate).toBe("نص محافظة");
    expect(res.original_residence_text).toBe("نص السكن الأصلي");
  });

  it("sends the original location only", async () => {
    const { post, user } = await renderPage();
    await pick(user, originalGroup(), /^المحافظة/, G1.name);
    await pick(user, originalGroup(), /^المدينة/, C1.name);

    const res = await submit(user, post);
    expect(res.original_location_id).toBe(C1.id);
    expect(res.current_location_id).toBeNull();
  });

  it("sends the current location only, without touching the text fields", async () => {
    const { post, user } = await renderPage();
    await pick(user, currentGroup(), /^المحافظة/, G1.name);
    await pick(user, currentGroup(), /^المدينة/, C1.name);
    await pick(user, currentGroup(), /^المنطقة/, A1.name);

    expect(screen.getByLabelText("المحافظة", { selector: "input" })).toHaveValue("نص محافظة");
    expect(screen.getByLabelText("المدينة", { selector: "input" })).toHaveValue("");
    const res = await submit(user, post);
    expect(res.original_location_id).toBeNull();
    expect(res.current_location_id).toBe(A1.id);
    expect(res.governorate).toBe("نص محافظة");
    expect(res.city).toBeNull();
  });

  it("sends both locations, each at its own depth", async () => {
    const { post, user } = await renderPage();
    await pick(user, originalGroup(), /^المحافظة/, G1.name);
    await pick(user, currentGroup(), /^المحافظة/, G1.name);
    await pick(user, currentGroup(), /^المدينة/, C2.name);

    const res = await submit(user, post);
    expect(res.original_location_id).toBe(G1.id);
    expect(res.current_location_id).toBe(C2.id);
  });
});

// ============================================================ correction

// Full-form flows with many real user events: an explicit timeout keeps them
// reliable on a loaded machine (Stage 4C).
describe("Staff residence correction: geographic references", { timeout: 20_000 }, () => {
  function setup(Dialog: typeof EditCurrentResidenceDialog, overrides: Partial<Residence> = {}) {
    mockGets();
    const patch = vi.spyOn(apiClient, "patch").mockResolvedValue({ data: {} } as never);
    const user = userEvent.setup();
    renderWithClient(<Dialog familyCode="FAM-000123" residence={residence(overrides)} />);
    return { patch, user };
  }

  async function open(user: ReturnType<typeof userEvent.setup>) {
    await user.click(screen.getByRole("button", { name: "تعديل" }));
    return screen.findByRole("dialog");
  }

  async function save(user: ReturnType<typeof userEvent.setup>, patch: { mock: { calls: unknown[][] } }) {
    await user.click(screen.getByRole("button", { name: "حفظ التعديلات" }));
    return waitFor(() => {
      expect(patch).toHaveBeenCalledTimes(1);
      return patch.mock.calls[0][1] as UpdateFamilyResidencePayload;
    });
  }

  it("hydrates the current location from its saved UUID, with its full path", async () => {
    const { user } = setup(EditCurrentResidenceDialog);
    await open(user);

    const saved = await within(currentGroup()).findByRole("group", { name: "الموقع المحفوظ" });
    expect(within(saved).getAllByRole("listitem").map((li) => li.textContent)).toEqual([G1.name, C1.name, A1.name]);
    // DISPLACED: the current location is the displacement location.
    expect(currentGroup()).toHaveAccessibleName(/مكان النزوح/);
  });

  it("keeps an unchanged reference out of the payload (text-only correction still works)", async () => {
    const { patch, user } = setup(EditCurrentResidenceDialog);
    await open(user);
    await within(currentGroup()).findByRole("group", { name: "الموقع المحفوظ" });

    const city = screen.getByLabelText(/^المدينة/, { selector: "input" });
    await user.clear(city);
    await user.type(city, "نص مدينة مصحّح");
    const payload = await save(user, patch);

    expect(payload).not.toHaveProperty("current_location_id");
    expect(payload).not.toHaveProperty("original_location_id");
    expect(payload).toMatchObject({ governorate: "نص محافظة", city: "نص مدينة مصحّح", address_text: "عنوان تفصيلي تجريبي" });
  });

  it("sends a confirmed replacement", async () => {
    const { patch, user } = setup(EditCurrentResidenceDialog);
    await open(user);
    const group = currentGroup();
    await within(group).findByRole("group", { name: "الموقع المحفوظ" });

    await user.click(within(group).getByRole("button", { name: "تغيير" }));
    await pick(user, group, /^المدينة/, C2.name);
    await user.click(within(group).getByRole("button", { name: "تأكيد الاختيار" }));

    expect((await save(user, patch)).current_location_id).toBe(C2.id);
  });

  it("sends null only for a confirmed clear", async () => {
    const { patch, user } = setup(EditCurrentResidenceDialog);
    await open(user);
    const group = currentGroup();
    await within(group).findByRole("group", { name: "الموقع المحفوظ" });

    await user.click(within(group).getByRole("button", { name: "مسح الاختيار" }));
    await user.click(within(group).getByRole("button", { name: "تأكيد المسح" }));

    const payload = await save(user, patch);
    expect(payload).toHaveProperty("current_location_id", null);
    expect(payload.governorate).toBe("نص محافظة");
  });

  it("omits a reference whose replacement was cancelled", async () => {
    const { patch, user } = setup(EditCurrentResidenceDialog);
    await open(user);
    const group = currentGroup();
    await within(group).findByRole("group", { name: "الموقع المحفوظ" });

    await user.click(within(group).getByRole("button", { name: "تغيير" }));
    await pick(user, group, /^المدينة/, C2.name);
    await user.click(within(group).getByRole("button", { name: "إلغاء" }));

    expect(await save(user, patch)).not.toHaveProperty("current_location_id");
  });

  it("shows an inactive saved original location and keeps it when untouched", async () => {
    const { patch, user } = setup(EditDisplacementDialog);
    await open(user);

    const saved = await within(originalGroup()).findByRole("group", { name: "الموقع المحفوظ" });
    expect(within(saved).getByText(OLD.name)).toBeInTheDocument();
    expect(within(saved).getByText("غير مفعّلة")).toBeInTheDocument();

    const payload = await save(user, patch);
    expect(payload).not.toHaveProperty("original_location_id");
    // Displacement behaviour is unchanged.
    expect(payload).toMatchObject({
      original_residence_text: "نص السكن الأصلي",
      displacement_status: "DISPLACED",
      displacement_location_text: "نص مكان النزوح",
    });
  });

  it("never clears the displacement text or the current location when the family is no longer displaced (FB-GEO-02)", async () => {
    const { patch, user } = setup(EditDisplacementDialog);
    const dialog = await open(user);

    await user.click(within(dialog).getByRole("combobox", { name: /هل الأسرة نازحة حاليًا؟/ }));
    await user.click(await screen.findByRole("option", { name: "لا" }));
    expect(within(dialog).getByText(/مكان النزوح السابق \(نص تاريخي\)/)).toHaveTextContent("نص مكان النزوح");

    const payload = await save(user, patch);
    expect(payload).toMatchObject({ displacement_status: "NOT_DISPLACED" });
    expect(payload).not.toHaveProperty("displacement_location_text");
    expect(payload).not.toHaveProperty("current_location_id");
  });

  it("changes one reference while the other dialog's reference is never sent", async () => {
    const { patch, user } = setup(EditDisplacementDialog);
    await open(user);
    const group = originalGroup();
    await within(group).findByRole("group", { name: "الموقع المحفوظ" });

    await user.click(within(group).getByRole("button", { name: "تغيير" }));
    await pick(user, group, /^المحافظة/, G1.name);
    await pick(user, group, /^المدينة/, C1.name);
    await user.click(within(group).getByRole("button", { name: "تأكيد الاختيار" }));

    const payload = await save(user, patch);
    expect(payload.original_location_id).toBe(C1.id);
    expect(payload).not.toHaveProperty("current_location_id");
  });

  it("selects a location for an empty reference and sends it", async () => {
    const { patch, user } = setup(EditCurrentResidenceDialog, { current_location_id: null, current_location: null });
    await open(user);
    const group = currentGroup();

    expect(await combo(group, /^المحافظة/)).toHaveTextContent("اختر المحافظة");
    await pick(user, group, /^المحافظة/, G1.name);

    expect((await save(user, patch)).current_location_id).toBe(G1.id);
  });

  it("cancelling the dialog saves nothing, and reopening restores the saved value", async () => {
    const { patch, user } = setup(EditCurrentResidenceDialog);
    const dialog = await open(user);
    const group = currentGroup();
    await within(group).findByRole("group", { name: "الموقع المحفوظ" });
    await user.click(within(group).getByRole("button", { name: "مسح الاختيار" }));
    await user.click(within(group).getByRole("button", { name: "تأكيد المسح" }));

    await user.click(within(dialog).getByRole("button", { name: "إلغاء" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(patch).not.toHaveBeenCalled();

    await open(user);
    expect(await within(currentGroup()).findByRole("group", { name: "الموقع المحفوظ" })).toHaveTextContent(A1.name);
  });
});

// ============================================================ display

describe("Staff residence display: geographic references", () => {
  function renderTab(overrides: Partial<Residence> = {}) {
    const family = { family_code: "FAM-000123", residence: residence(overrides) } as FamilyDetail;
    const get = vi.spyOn(apiClient, "get");
    renderWithClient(
      <AuthContext.Provider value={authValue(staff([]))}>
        <FamilyResidenceTab family={family} />
      </AuthContext.Provider>
    );
    return { get };
  }

  const references = () => Array.from(document.querySelectorAll("[data-location-reference]")) as HTMLElement[];
  const pathOf = (el: HTMLElement) => within(el).queryAllByRole("listitem").map((li) => li.textContent);

  it("shows both full paths from the response summaries, beside the free text, with no request", () => {
    const { get } = renderTab({ original_location: summary(A1, [G1, C1]) });

    const [original, current] = references();
    expect(pathOf(original)).toEqual([G1.name, C1.name, A1.name]);
    expect(pathOf(current)).toEqual([G1.name, C1.name, A1.name]);
    expect(current).toHaveTextContent("مكان النزوح");
    for (const text of ["نص السكن الأصلي", "نص مكان النزوح", "عنوان تفصيلي تجريبي"]) {
      expect(screen.getAllByText(text, { exact: false }).length).toBeGreaterThan(0);
    }
    expect(get).not.toHaveBeenCalled();
  });

  it("marks an inactive location", () => {
    renderTab();
    const [original] = references();
    expect(pathOf(original)).toEqual([G1.name, C1.name, `${OLD.name}غير مفعّلة`]);
  });

  it("shows an Arabic empty state for a missing reference, keeping the text", () => {
    renderTab({ original_location_id: null, original_location: null, current_location_id: null, current_location: null });

    const [original, current] = references();
    expect(original).toHaveTextContent("لم يُحدَّد موقع جغرافي");
    expect(current).toHaveTextContent("لم يُحدَّد موقع جغرافي");
    expect(screen.getByText("نص السكن الأصلي")).toBeInTheDocument();
  });

  it("never shows a location UUID", () => {
    renderTab();
    expect(document.body.textContent).not.toContain(A1.id);
    expect(document.body.textContent).not.toContain(OLD.id);
  });
});
