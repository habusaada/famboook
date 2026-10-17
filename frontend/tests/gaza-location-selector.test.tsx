import { act, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { Controller, useForm, useWatch } from "react-hook-form";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { GazaLocationSelector, type GazaLocationSelectorProps } from "@/components/geography/gaza-location-selector";
import { ApiError, apiClient } from "@/lib/api/client";
import type { GazaLocation, GazaLocationDetails, GazaLocationLevel } from "@/lib/types/api/gaza-location";
import { renderWithClient } from "./helpers";

// FB-GEO-01 Stage 4A: the generic, data-driven Gaza location selector.
// SYNTHETIC hierarchy: names are placeholders, and two cities deliberately
// share one name under different governorates — relationships come only
// from parent UUIDs.

// Multi-level cascades with real user events: an explicit timeout keeps them
// reliable on a loaded machine (Stage 4C).
vi.setConfig({ testTimeout: 20_000 });

beforeAll(() => {
  // jsdom lacks the pointer / scroll APIs Radix Select uses.
  Object.assign(Element.prototype, {
    hasPointerCapture: () => false,
    releasePointerCapture: () => undefined,
    scrollIntoView: () => undefined,
  });
});

let seq = 0;
function node(level: GazaLocationLevel, name: string, parent: GazaLocation | null, hasChildren: boolean, landmarkType: string | null = null): GazaLocation {
  seq += 1;
  return {
    id: `00000000-0000-4000-8000-${String(seq).padStart(12, "0")}`,
    level,
    name,
    parent_id: parent?.id ?? null,
    has_children: hasChildren,
    landmark_type: landmarkType,
  };
}

const G1 = node("GOVERNORATE", "محافظة أولى", null, true);
const G2 = node("GOVERNORATE", "محافظة ثانية", null, true);
const C1 = node("CITY", "مدينة مشتركة", G1, true);
const C2 = node("CITY", "مدينة ثانية", G1, true);
const C3 = node("CITY", "مدينة مشتركة", G2, true); // same name as C1, other parent
const A1 = node("AREA", "منطقة أولى", C1, true);
const A2 = node("AREA", "منطقة ثانية", C2, false);
const A3 = node("AREA", "منطقة ثالثة", C3, true);
const N1 = node("NEIGHBORHOOD", "حي أول", A1, true);
const N2 = node("NEIGHBORHOOD", "حي ثانٍ", A1, false);
const N3 = node("NEIGHBORHOOD", "حي ثالث", A3, true); // has_children, but none active
const L1 = node("LANDMARK", "معلم أول", N1, false, "مصلى");
const L2 = node("LANDMARK", "معلم ثانٍ", N1, false, "مدرسة");

const BASE = "/api/v1/gaza-locations";
const childrenOf: Record<string, GazaLocation[]> = {
  [G1.id]: [C1, C2],
  [G2.id]: [C3],
  [C1.id]: [A1],
  [C2.id]: [A2],
  [C3.id]: [A3],
  [A1.id]: [N1, N2],
  [A3.id]: [N3],
  [N1.id]: [L1, L2],
  [N3.id]: [],
};

function details(target: GazaLocation, path: GazaLocation[], inactive: string[] = []): GazaLocationDetails {
  return {
    ...target,
    is_active: !inactive.includes(target.id),
    path: path.map((p) => ({ id: p.id, level: p.level, name: p.name, is_active: !inactive.includes(p.id) })),
  };
}

type Reply = unknown | Error | Promise<unknown>;

function mockApi(overrides: Record<string, Reply> = {}) {
  const routes: Record<string, Reply> = {
    [`${BASE}/governorates`]: { data: [G1, G2] },
    ...Object.fromEntries(Object.entries(childrenOf).map(([id, list]) => [`${BASE}/${id}/children`, { data: list }])),
    [`${BASE}/${L1.id}`]: { data: details(L1, [G1, C1, A1, N1]) },
    [`${BASE}/${C1.id}`]: { data: details(C1, [G1]) },
    [`${BASE}/${N2.id}`]: { data: details(N2, [G1, C1, A1], [N2.id]) },
    [`${BASE}/${L2.id}`]: { data: details(L2, [G1, C1, A1, N1], [C1.id]) },
    ...overrides,
  };
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (!(path in routes)) throw new ApiError(404, { message: "Not Found." });
    const reply = routes[path];
    if (reply instanceof Error) throw reply;
    return (await reply) as never;
  });
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((r) => {
    resolve = r;
  });
  return { promise, resolve };
}

function Harness({
  initial = null,
  spy,
  externalValue,
  ...props
}: { initial?: string | null; spy: (v: string | null) => void; externalValue?: string | null } & Partial<GazaLocationSelectorProps>) {
  const [value, setValue] = useState<string | null>(initial);
  return (
    <>
      <GazaLocationSelector
        id="test-location"
        value={value}
        onChange={(v) => {
          spy(v);
          setValue(v);
        }}
        {...props}
      />
      <output data-testid="value">{value ?? "null"}</output>
      <button type="button" onClick={() => setValue(externalValue ?? null)}>
        قيمة خارجية
      </button>
    </>
  );
}

function setup(props: { initial?: string | null; externalValue?: string | null } & Partial<GazaLocationSelectorProps> = {}) {
  const user = userEvent.setup();
  const spy = vi.fn();
  renderWithClient(<Harness spy={spy} {...props} />);
  return { user, spy };
}

// The loading-state trigger is REPLACED once the list arrives, so always
// re-query: combo() waits for the enabled control; anyCombo() takes any state.
const anyCombo = (label: RegExp) => screen.findByRole("combobox", { name: label });

async function combo(label: RegExp): Promise<HTMLElement> {
  return waitFor(() => {
    const element = screen.getByRole("combobox", { name: label });
    expect(element).toBeEnabled();
    return element;
  });
}

async function pick(user: ReturnType<typeof userEvent.setup>, label: RegExp, option: string) {
  await user.click(await combo(label));
  await user.click(await screen.findByRole("option", { name: option }));
}

async function optionNames(user: ReturnType<typeof userEvent.setup>, label: RegExp): Promise<string[]> {
  await user.click(await combo(label));
  const names = (await screen.findAllByRole("option")).map((o) => o.textContent ?? "");
  await user.keyboard("{Escape}");
  return names;
}

const value = () => screen.getByTestId("value").textContent;

beforeEach(() => {
  vi.restoreAllMocks();
});

// =================================================================== cascade

describe("cascade from the API data only", () => {
  it("loads the governorates first, in API order, inside a labelled fieldset", async () => {
    mockApi();
    const { user } = setup();

    expect(screen.getByRole("group", { name: "الموقع الجغرافي" })).toBeInTheDocument();
    expect(await optionNames(user, /^المحافظة/)).toEqual([G1.name, G2.name]);
    // The dependent level is shown disabled until a governorate is chosen.
    expect(await anyCombo(/^المدينة/)).toBeDisabled();
  });

  it("loads each level only from the selected parent's direct children and emits each choice once", async () => {
    const get = mockApi();
    const { user, spy } = setup();

    await pick(user, /^المحافظة/, G1.name);
    expect(await optionNames(user, /^المدينة/)).toEqual(["غير محدد", C1.name, C2.name]);
    expect(get).toHaveBeenCalledWith(`${BASE}/${G1.id}/children`);

    await pick(user, /^المدينة/, C1.name);
    expect(await optionNames(user, /^المنطقة/)).toEqual(["غير محدد", A1.name]);

    await pick(user, /^المنطقة/, A1.name);
    expect(await optionNames(user, /^الحي/)).toEqual(["غير محدد", N1.name, N2.name]);

    await pick(user, /^الحي/, N1.name);
    expect(await optionNames(user, /^المعلم/)).toEqual(["بدون معلم", `${L1.name} (مصلى)`, `${L2.name} (مدرسة)`]);

    await pick(user, /^المعلم/, `${L1.name} (مصلى)`);
    expect(spy.mock.calls.map((c) => c[0])).toEqual([G1.id, C1.id, A1.id, N1.id, L1.id]);
    expect(value()).toBe(L1.id);
    expect(screen.getByRole("list", { name: "المسار الجغرافي" })).toHaveTextContent(
      [G1, C1, A1, N1, L1].map((n) => n.name).join("")
    );
  });

  it("changing a parent clears every descendant", async () => {
    mockApi();
    const { user, spy } = setup();
    await pick(user, /^المحافظة/, G1.name);
    await pick(user, /^المدينة/, C1.name);
    await pick(user, /^المنطقة/, A1.name);

    await pick(user, /^المحافظة/, G2.name);

    expect(spy).toHaveBeenLastCalledWith(G2.id);
    expect(value()).toBe(G2.id);
    expect(await combo(/^المدينة/)).toHaveTextContent("اختر المدينة");
    expect(screen.queryByRole("combobox", { name: /^الحي/ })).not.toBeInTheDocument();
  });

  it("never relates locations by name: same-named cities under different governorates stay distinct", async () => {
    mockApi();
    const { user, spy } = setup();

    await pick(user, /^المحافظة/, G2.name);
    // Only G2's direct child, although C1 has the same name.
    expect(await optionNames(user, /^المدينة/)).toEqual(["غير محدد", C3.name]);
    await pick(user, /^المدينة/, C3.name);
    expect(spy).toHaveBeenLastCalledWith(C3.id);

    await pick(user, /^المحافظة/, G1.name);
    await pick(user, /^المدينة/, C1.name);
    expect(spy).toHaveBeenLastCalledWith(C1.id);
  });

  it("treats every governorate the same way (no special case)", async () => {
    for (const [governorate, cities] of [[G1, [C1, C2]], [G2, [C3]]] as const) {
      const get = mockApi();
      const { user } = setup();
      await pick(user, /^المحافظة/, governorate.name);
      expect(await optionNames(user, /^المدينة/)).toEqual(["غير محدد", ...cities.map((c) => c.name)]);
      expect(get).toHaveBeenCalledWith(`${BASE}/${governorate.id}/children`);
      document.body.innerHTML = "";
      vi.restoreAllMocks();
    }
  });

  it("never auto-selects a single option", async () => {
    mockApi();
    const { user, spy } = setup();

    await pick(user, /^المحافظة/, G2.name); // G2 has exactly one city
    expect(await combo(/^المدينة/)).toHaveTextContent("اختر المدينة");
    expect(spy).toHaveBeenCalledTimes(1);
    expect(value()).toBe(G2.id);
  });

  it("allows a partial selection and clearing a level returns to its parent", async () => {
    mockApi();
    const { user, spy } = setup();
    await pick(user, /^المحافظة/, G1.name);
    await pick(user, /^المدينة/, C1.name);
    expect(value()).toBe(C1.id); // stopping at the city is allowed

    await pick(user, /^المدينة/, "غير محدد");
    expect(spy).toHaveBeenLastCalledWith(G1.id);

    // Clearing is staged: «مسح الاختيار» emits nothing until confirmed.
    const calls = spy.mock.calls.length;
    await user.click(screen.getByRole("button", { name: "مسح الاختيار" }));
    expect(spy).toHaveBeenCalledTimes(calls);
    await user.click(screen.getByRole("button", { name: "تأكيد المسح" }));
    expect(spy).toHaveBeenLastCalledWith(null);
    expect(spy).toHaveBeenCalledTimes(calls + 1);
    expect(value()).toBe("null");
  });

  it("offers an optional landmark with «بدون معلم», and hides it when showLandmark is false", async () => {
    mockApi();
    const { user, spy } = setup();
    await pick(user, /^المحافظة/, G1.name);
    await pick(user, /^المدينة/, C1.name);
    await pick(user, /^المنطقة/, A1.name);
    await pick(user, /^الحي/, N1.name);
    expect(await combo(/^المعلم/)).toBeInTheDocument();
    expect(screen.getByText("(اختياري)", { selector: "label[for='test-location-landmark'] *" })).toBeInTheDocument();

    await pick(user, /^المعلم/, `${L1.name} (مصلى)`);
    await pick(user, /^المعلم/, "بدون معلم");
    expect(spy).toHaveBeenLastCalledWith(N1.id);

    document.body.innerHTML = "";
    vi.restoreAllMocks();
    mockApi();
    const hidden = setup({ showLandmark: false });
    await pick(hidden.user, /^المحافظة/, G1.name);
    await pick(hidden.user, /^المدينة/, C1.name);
    await pick(hidden.user, /^المنطقة/, A1.name);
    await pick(hidden.user, /^الحي/, N1.name);
    expect(screen.queryByRole("combobox", { name: /^المعلم/ })).not.toBeInTheDocument();
  });

  it("shows an Arabic empty state when an active parent has no active children", async () => {
    mockApi();
    const { user } = setup();
    await pick(user, /^المحافظة/, G2.name);
    await pick(user, /^المدينة/, C3.name);
    await pick(user, /^المنطقة/, A3.name);
    await pick(user, /^الحي/, N3.name);

    expect(await screen.findByText("لا توجد خيارات متاحة في هذا المستوى.")).toBeInTheDocument();
  });

  it("marks levels up to requiredLevel as required (presentation only)", async () => {
    mockApi();
    setup({ requiredLevel: "GOVERNORATE" });
    expect(await combo(/^المحافظة$/)).toHaveAttribute("aria-required", "true");
  });
});

// =================================================================== saved values

describe("initialization from a saved UUID", () => {
  const pathItems = () =>
    within(screen.getByRole("group", { name: "الموقع المحفوظ" })).getAllByRole("listitem").map((li) => li.textContent);

  it("shows a saved value with its full path, read-only, without calling onChange", async () => {
    mockApi();
    const { spy } = setup({ initial: L1.id });

    await screen.findByRole("group", { name: "الموقع المحفوظ" });
    expect(pathItems()).toEqual([G1, C1, A1, N1, L1].map((n) => n.name));
    expect(screen.queryByRole("combobox")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "تغيير" })).toBeInTheDocument();
    expect(spy).not.toHaveBeenCalled();
    expect(value()).toBe(L1.id);
  });

  it("«تغيير» rebuilds every level from the saved path as a candidate", async () => {
    mockApi();
    const { user, spy } = setup({ initial: L1.id });
    await screen.findByRole("group", { name: "الموقع المحفوظ" });

    await user.click(screen.getByRole("button", { name: "تغيير" }));
    expect(await combo(/^المحافظة/)).toHaveTextContent(G1.name);
    expect(await combo(/^المدينة/)).toHaveTextContent(C1.name);
    expect(await combo(/^المنطقة/)).toHaveTextContent(A1.name);
    expect(await combo(/^الحي/)).toHaveTextContent(N1.name);
    expect(await combo(/^المعلم/)).toHaveTextContent(`${L1.name} (مصلى)`);
    // Unchanged candidate: nothing to confirm.
    expect(screen.getByRole("button", { name: "تأكيد الاختيار" })).toBeDisabled();
    expect(spy).not.toHaveBeenCalled();
  });

  it("shows an inactive saved location read-only, never as a selectable value", async () => {
    mockApi();
    const { spy } = setup({ initial: N2.id });

    const saved = await screen.findByRole("group", { name: "الموقع المحفوظ" });
    expect(within(saved).getByText(N2.name)).toBeInTheDocument();
    expect(within(saved).getByText("غير مفعّلة")).toBeInTheDocument();
    expect(screen.queryByRole("combobox")).not.toBeInTheDocument();
    expect(spy).not.toHaveBeenCalled();
    expect(value()).toBe(N2.id);
  });

  it("flags an inactive ancestor in the saved path", async () => {
    mockApi();
    setup({ initial: L2.id });

    await screen.findByRole("group", { name: "الموقع المحفوظ" });
    expect(pathItems()).toEqual([G1.name, `${C1.name}غير مفعّلة`, A1.name, N1.name, L2.name]);
  });

  it("cancelling a replacement before choosing a candidate emits nothing", async () => {
    mockApi();
    const { user, spy } = setup({ initial: N2.id });
    await screen.findByRole("group", { name: "الموقع المحفوظ" });

    await user.click(screen.getByRole("button", { name: "تغيير" }));
    // An inactive saved chain is never offered again: the candidate starts empty.
    expect(await combo(/^المحافظة/)).toHaveTextContent("اختر المحافظة");
    await user.click(screen.getByRole("button", { name: "إلغاء" }));

    expect(await screen.findByRole("group", { name: "الموقع المحفوظ" })).toBeInTheDocument();
    expect(spy).not.toHaveBeenCalled();
    expect(value()).toBe(N2.id);
  });

  it("cancelling after choosing a candidate emits nothing and keeps the inactive saved value", async () => {
    mockApi();
    const { user, spy } = setup({ initial: N2.id });
    await screen.findByRole("group", { name: "الموقع المحفوظ" });

    await user.click(screen.getByRole("button", { name: "تغيير" }));
    await pick(user, /^المحافظة/, G2.name);
    await pick(user, /^المدينة/, C3.name);
    expect(spy).not.toHaveBeenCalled(); // candidates are never committed
    expect(value()).toBe(N2.id);

    await user.click(screen.getByRole("button", { name: "إلغاء" }));
    expect(spy).not.toHaveBeenCalled();
    expect(value()).toBe(N2.id);
    expect(within(await screen.findByRole("group", { name: "الموقع المحفوظ" })).getByText("غير مفعّلة")).toBeInTheDocument();
  });

  it("confirming a replacement emits the candidate exactly once", async () => {
    mockApi();
    const { user, spy } = setup({ initial: N2.id });
    await screen.findByRole("group", { name: "الموقع المحفوظ" });

    await user.click(screen.getByRole("button", { name: "تغيير" }));
    await pick(user, /^المحافظة/, G1.name);
    await pick(user, /^المدينة/, C1.name);
    await user.click(screen.getByRole("button", { name: "تأكيد الاختيار" }));

    expect(spy).toHaveBeenCalledTimes(1);
    expect(spy).toHaveBeenCalledWith(C1.id);
    expect(value()).toBe(C1.id);
    // The confirmed value is now the committed one, shown with its path.
    await waitFor(() => expect(pathItems()).toEqual([G1.name, C1.name]));
  });

  it("clearing a saved value emits null only after confirmation", async () => {
    mockApi();
    const { user, spy } = setup({ initial: L1.id });
    await screen.findByRole("group", { name: "الموقع المحفوظ" });

    await user.click(screen.getByRole("button", { name: "مسح الاختيار" }));
    expect(spy).not.toHaveBeenCalled();
    await user.click(screen.getByRole("button", { name: "إلغاء" }));
    expect(spy).not.toHaveBeenCalled();
    expect(value()).toBe(L1.id);

    await user.click(screen.getByRole("button", { name: "مسح الاختيار" }));
    await user.click(screen.getByRole("button", { name: "تأكيد المسح" }));
    expect(spy).toHaveBeenCalledTimes(1);
    expect(spy).toHaveBeenCalledWith(null);
    expect(value()).toBe("null");
  });

  it("keeps an unknown saved UUID unchanged and offers «تغيير»", async () => {
    const get = mockApi();
    const unknown = "00000000-0000-4000-8000-999999999999";
    const { spy } = setup({ initial: unknown });

    expect(await screen.findByText(/الموقع المحفوظ غير متاح/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "تغيير" })).toBeInTheDocument();
    expect(spy).not.toHaveBeenCalled();
    expect(value()).toBe(unknown);
    // A 404 is never retried.
    expect(get.mock.calls.filter((c) => c[0] === `${BASE}/${unknown}`)).toHaveLength(1);
  });

  it("keeps the saved value when its details fail, and retries on request", async () => {
    let fail = true;
    const get = mockApi();
    get.mockImplementation(async (path: string) => {
      if (path === `${BASE}/${C1.id}` && fail) throw new ApiError(500, { message: "Server Error" });
      if (path === `${BASE}/${C1.id}`) return { data: details(C1, [G1]) } as never;
      if (path === `${BASE}/governorates`) return { data: [G1, G2] } as never;
      return { data: childrenOf[path.split("/")[4]] ?? [] } as never;
    });
    const { user, spy } = setup({ initial: C1.id });

    expect(await screen.findByText(/تعذّر تحميل الموقع المحفوظ/, {}, { timeout: 4000 })).toBeInTheDocument();
    expect(value()).toBe(C1.id);
    fail = false;
    await user.click(screen.getByRole("button", { name: "إعادة المحاولة" }));
    await screen.findByRole("group", { name: "الموقع المحفوظ" });
    expect(pathItems()).toEqual([G1.name, C1.name]);
    expect(spy).not.toHaveBeenCalled();
  });

  it("follows a controlled value set by the parent form, without emitting", async () => {
    mockApi();
    const { user, spy } = setup({ externalValue: L1.id });

    await user.click(screen.getByRole("button", { name: "قيمة خارجية" }));
    await screen.findByRole("group", { name: "الموقع المحفوظ" });
    expect(pathItems()).toEqual([G1, C1, A1, N1, L1].map((n) => n.name));
    expect(spy).not.toHaveBeenCalled();
  });
});

// =================================================================== React Hook Form

function RhfHarness({ initial, report }: { initial: string | null; report: (state: { dirty: boolean; value: string | null }) => void }) {
  const { control, formState } = useForm<{ location: string | null }>({ defaultValues: { location: initial } });
  const dirty = Boolean(formState.dirtyFields.location);
  report({ dirty, value: useWatch({ control, name: "location" }) });
  return (
    <Controller
      control={control}
      name="location"
      render={({ field }) => <GazaLocationSelector id="rhf-location" value={field.value} onChange={field.onChange} />}
    />
  );
}

describe("React Hook Form integration", () => {
  it("leaves dirtyFields unchanged after a cancelled replacement, and dirties only on confirmation", async () => {
    mockApi();
    const user = userEvent.setup();
    const states: { dirty: boolean; value: string | null }[] = [];
    renderWithClient(<RhfHarness initial={N2.id} report={(s) => states.push(s)} />);
    await screen.findByRole("group", { name: "الموقع المحفوظ" });

    await user.click(screen.getByRole("button", { name: "تغيير" }));
    await pick(user, /^المحافظة/, G1.name);
    await user.click(screen.getByRole("button", { name: "إلغاء" }));
    await screen.findByRole("group", { name: "الموقع المحفوظ" });
    expect(states.at(-1)).toEqual({ dirty: false, value: N2.id });
    expect(states.every((s) => !s.dirty && s.value === N2.id)).toBe(true);

    await user.click(screen.getByRole("button", { name: "تغيير" }));
    await pick(user, /^المحافظة/, G1.name);
    await user.click(screen.getByRole("button", { name: "تأكيد الاختيار" }));
    await waitFor(() => expect(states.at(-1)).toEqual({ dirty: true, value: G1.id }));
  });
});

// =================================================================== failures, races, a11y

describe("errors, stale responses and accessibility", () => {
  it("shows a retry action when a list fails, and does not retry a 404 automatically", async () => {
    let fail = true;
    const get = mockApi();
    get.mockImplementation(async (path: string) => {
      if (path === `${BASE}/governorates`) {
        if (fail) throw new ApiError(500, { message: "Server Error" });
        return { data: [G1, G2] } as never;
      }
      if (path === `${BASE}/${G1.id}/children`) throw new ApiError(404, { message: "Not Found." });
      return { data: [] } as never;
    });
    const { user } = setup();

    expect(await screen.findByText("تعذّر تحميل قائمة المحافظة.", {}, { timeout: 4000 })).toBeInTheDocument();
    fail = false;
    await user.click(screen.getByRole("button", { name: "إعادة المحاولة" }));
    await pick(user, /^المحافظة/, G1.name);

    expect(await screen.findByText("هذا المستوى غير متاح.")).toBeInTheDocument();
    expect(get.mock.calls.filter((c) => c[0] === `${BASE}/${G1.id}/children`)).toHaveLength(1);
  });

  it("never shows a previous parent's late response under the newly selected parent", async () => {
    const slow = deferred<unknown>();
    mockApi({ [`${BASE}/${G1.id}/children`]: slow.promise });
    const { user } = setup();

    await pick(user, /^المحافظة/, G1.name); // its cities are still loading
    await pick(user, /^المحافظة/, G2.name);
    expect(await optionNames(user, /^المدينة/)).toEqual(["غير محدد", C3.name]);

    await act(async () => slow.resolve({ data: [C1, C2] }));
    expect(await optionNames(user, /^المدينة/)).toEqual(["غير محدد", C3.name]);
  });

  it("renders every Select right-to-left and supports the keyboard", async () => {
    mockApi();
    const { user, spy } = setup();

    const governorate = await combo(/^المحافظة/);
    await waitFor(() => expect(governorate).toBeEnabled());
    expect(governorate).toHaveAttribute("dir", "rtl");
    expect(await anyCombo(/^المدينة/)).toHaveAttribute("dir", "rtl");

    governorate.focus();
    await user.keyboard("{Enter}");
    await screen.findByRole("option", { name: G1.name });
    await user.keyboard("{Enter}");
    await waitFor(() => expect(spy).toHaveBeenCalledWith(G1.id));
  });

  it("keeps every trigger touch-sized: the height wins over SelectTrigger's own h-8", async () => {
    // Stage 4C: a plain h-11 lost to data-[size=default]:h-8 and rendered
    // 32px in a real browser. jsdom has no CSS, so guard the classes.
    mockApi();
    setup();
    await combo(/^المحافظة/);
    for (const trigger of screen.getAllByRole("combobox")) {
      expect(trigger.className.split(/\s+/)).toEqual(expect.arrayContaining(["h-11!", "sm:h-10!"]));
    }
  });

  it("disables every control when disabled", async () => {
    mockApi();
    setup({ disabled: true });
    await waitFor(async () => expect(await anyCombo(/^المحافظة/)).toBeDisabled());
  });
});

// =================================================================== Family PWA source (API-D2)

describe("Family PWA source", () => {
  const FAMILY = "/api/v1/family/change-requests/gaza-locations";

  // The family mirrors serve the same data with the FAMILY-SAFE landmark_type
  // (FB-GEO-03): the type name, or null while an unusual-type review blocks
  // it — here L2's. Any Staff path fails the test.
  function mockFamilyApi(blocked: string[] = [L2.id]) {
    const get = mockApi();
    const staffReply = get.getMockImplementation()!;
    get.mockImplementation(async (path: string) => {
      if (!path.startsWith(FAMILY)) throw new Error(`Staff path used: ${path}`);
      const reply = (await staffReply(BASE + path.slice(FAMILY.length))) as { data: GazaLocation[] | GazaLocation };
      const safe = (item: GazaLocation) => (blocked.includes(item.id) ? { ...item, landmark_type: null } : item);
      return { data: Array.isArray(reply.data) ? reply.data.map(safe) : safe(reply.data) } as never;
    });
    return get;
  }

  it("cascades from the family mirrors only, emitting each choice once", async () => {
    const get = mockFamilyApi();
    const { user, spy } = setup({ source: "family" });

    await pick(user, /^المحافظة/, G1.name);
    await pick(user, /^المدينة/, C1.name);
    await pick(user, /^المنطقة/, A1.name);
    await pick(user, /^الحي/, N1.name);
    // A family-safe type is shown beside the name (FB-GEO-03).
    await pick(user, /^المعلم/, `${L1.name} (مصلى)`);

    expect(spy.mock.calls.map(([id]) => id)).toEqual([G1.id, C1.id, A1.id, N1.id, L1.id]);
    expect(get.mock.calls.length).toBeGreaterThan(0);
    expect(get.mock.calls.every(([path]) => String(path).startsWith(FAMILY))).toBe(true);
  });

  it("FB-GEO-03: labels a landmark «name (type)» only when the family-safe type is provided, and selects by the original id", async () => {
    mockFamilyApi();
    const { user, spy } = setup({ source: "family" });
    await pick(user, /^المحافظة/, G1.name);
    await pick(user, /^المدينة/, C1.name);
    await pick(user, /^المنطقة/, A1.name);
    await pick(user, /^الحي/, N1.name);

    const options = await optionNames(user, /^المعلم/);
    // Safe type shown; the withheld (null) type of L2 never appears — only its unchanged name.
    expect(options).toContain(`${L1.name} (مصلى)`);
    expect(options).toContain(L2.name);
    expect(options.join("|")).not.toContain("مدرسة");
    expect(options.filter((o) => o.includes(L2.name))).toEqual([L2.name]);

    await pick(user, /^المعلم/, L2.name);
    await pick(user, /^المعلم/, `${L1.name} (مصلى)`);
    // The original location ids are emitted, never a label.
    expect(spy.mock.calls.map(([id]) => id).slice(-2)).toEqual([L2.id, L1.id]);
    expect(value()).toBe(L1.id);
  });

  it("shows an inactive saved location read-only, and cancelling a replacement emits nothing", async () => {
    mockFamilyApi();
    const { user, spy } = setup({ source: "family", initial: N2.id });

    const saved = await screen.findByRole("group", { name: "الموقع المحفوظ" });
    expect(within(saved).getByText("غير مفعّلة")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "تغيير" }));
    await pick(user, /^المحافظة/, G2.name);
    await user.click(screen.getByRole("button", { name: "إلغاء" }));

    expect(await screen.findByRole("group", { name: "الموقع المحفوظ" })).toBeInTheDocument();
    expect(spy).not.toHaveBeenCalled();
    expect(value()).toBe(N2.id);
  });
});
