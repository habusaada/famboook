import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { ChangeRequestComparison } from "@/components/change-requests/change-request-comparison";
import { FamilyResidenceUpdateForm } from "@/components/family/requests/family-residence-update-form";
import { ApiError, apiClient } from "@/lib/api/client";
import type { FamilyChangeRequestTypes } from "@/lib/api/family-change-requests";
import type { FamilyProfile } from "@/lib/api/family-household";
import { browserStorageDump, renderWithClient } from "./helpers";

// PWA-6.1: the household head's RESIDENCE_UPDATE form over the PWA-5e Family
// API. The server decides availability (type discovery) and validates; the
// form prefills from /family/household/profile. Synthetic data only.

const router = { push: vi.fn(), replace: vi.fn() };
vi.mock("next/navigation", () => ({
  useRouter: () => router,
  usePathname: () => "/family/requests/new/residence-update",
  useSearchParams: () => new URLSearchParams(),
}));

// jsdom lacks the pointer / scroll APIs Radix Select uses.
beforeAll(() => {
  Object.assign(Element.prototype, {
    hasPointerCapture: () => false,
    releasePointerCapture: () => undefined,
    scrollIntoView: () => undefined,
  });
});

beforeEach(() => {
  vi.restoreAllMocks();
  router.push.mockReset();
});

const NEW_ID = "5b1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4e";
const SUBMIT_PATH = "/api/v1/family/change-requests";

type Residence = NonNullable<FamilyProfile["residence"]>;

function residence(overrides: Partial<Residence> = {}): Residence {
  return {
    original_residence_text: "بني سهيلا",
    displacement_status: "NOT_DISPLACED",
    displacement_location_text: null,
    residence_type: "شقة",
    started_at: "2020-01-01",
    current_address: { governorate: "خانيونس", city: "خانيونس", area: "البلد", neighborhood: "حي الأمل", address_text: "شارع التجربة 1" },
    ...overrides,
  };
}

function profile(res: Residence | null = residence()): FamilyProfile {
  return {
    family: {
      family_code: "FAM-TEST-1",
      clan_name: null,
      branch_group_name: null,
      branch_name: null,
      head: { full_name: "رب أسرة تجريبي" },
      registration_date: null,
      paper_form_no: null,
      registered_member_count: 3,
    },
    declaration: null,
    residence: res,
  };
}

const AVAILABLE: FamilyChangeRequestTypes = { data: [{ type: "RESIDENCE_UPDATE" }], meta: { submission_enabled: true } };

function mockGet({ types = AVAILABLE as unknown, prof = { data: profile() } as unknown } = {}) {
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    const value = path.endsWith("/types") ? types : path === "/api/v1/family/household/profile" ? prof : undefined;
    if (value instanceof Error) throw value;
    if (value === undefined) throw new Error(`unexpected GET ${path}`);
    return value;
  });
}

const outcome = (replayed = false) => ({ data: { id: NEW_ID, request_code: "CRQ-000101", status: "SUBMITTED", replayed } });

async function renderForm() {
  const user = userEvent.setup();
  renderWithClient(<FamilyResidenceUpdateForm />);
  await screen.findByRole("button", { name: "إرسال الطلب للمراجعة" });
  return user;
}

async function chooseStatus(user: ReturnType<typeof userEvent.setup>, name: RegExp) {
  await user.click(screen.getByRole("combobox", { name: /هل الأسرة نازحة حاليًا؟/ }));
  await user.click(await screen.findByRole("option", { name }));
}

// =================================================================== availability

describe("availability comes from the server", () => {
  it("shows the form only while RESIDENCE_UPDATE is listed", async () => {
    mockGet({ types: { data: [], meta: { submission_enabled: false } } });
    const post = vi.spyOn(apiClient, "post");
    renderWithClient(<FamilyResidenceUpdateForm />);

    expect(await screen.findByRole("heading", { name: "طلبات تحديث السكن غير متاحة حاليًا" })).toBeInTheDocument();
    expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "الانتقال إلى طلباتي" })).toHaveAttribute("href", "/family/requests");
    expect(post).not.toHaveBeenCalled();
  });

  it("does not offer the form when the switch is on but the type is not listed", async () => {
    mockGet({ types: { data: [{ type: "BIRTH_REPORT" }], meta: { submission_enabled: true } } });
    renderWithClient(<FamilyResidenceUpdateForm />);
    expect(await screen.findByRole("heading", { name: "طلبات تحديث السكن غير متاحة حاليًا" })).toBeInTheDocument();
  });

  it("explains a missing current residence instead of showing an empty form", async () => {
    mockGet({ prof: { data: profile(null) } });
    renderWithClient(<FamilyResidenceUpdateForm />);
    expect(await screen.findByRole("heading", { name: "لا يوجد سكن حالي مسجّل" })).toBeInTheDocument();
    expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
  });

  it("shows loading, then an error with retry", async () => {
    let fail = true;
    vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
      if (fail) throw new ApiError(500, { message: "x" });
      return path.endsWith("/types") ? AVAILABLE : { data: profile() };
    });
    const user = userEvent.setup();
    renderWithClient(<FamilyResidenceUpdateForm />);
    expect(screen.getByRole("status")).toHaveTextContent("جارٍ التحميل");

    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر تحميل بيانات السكن");
    fail = false;
    await user.click(screen.getByRole("button", { name: /إعادة المحاولة/ }));
    expect(await screen.findByRole("button", { name: "إرسال الطلب للمراجعة" })).toBeInTheDocument();
  });
});

// =================================================================== the form

describe("the residence update form", () => {
  it("prefills the current residence, marks optional fields and explains the review", async () => {
    mockGet();
    await renderForm();

    expect(screen.getByRole("heading", { level: 1, name: "تحديث بيانات السكن" })).toBeInTheDocument();
    expect(screen.getByText("سيُرسل طلبك للمراجعة، ولن تتغير بيانات السجل الرسمي إلا بعد اعتماد الطلب وتطبيقه.")).toBeInTheDocument();
    expect(screen.getByLabelText(/المحافظة/)).toHaveValue("خانيونس");
    expect(screen.getByLabelText(/^الحي/)).toHaveValue("حي الأمل");
    expect(screen.getByLabelText(/العنوان التفصيلي/)).toHaveValue("شارع التجربة 1");
    expect(screen.getByLabelText(/السكن الأصلي قبل النزوح/)).toHaveValue("بني سهيلا");
    expect(screen.getByRole("combobox", { name: /هل الأسرة نازحة حاليًا؟/ })).toHaveTextContent("لا، غير نازحة");
    expect(screen.getByRole("combobox", { name: /هل الأسرة نازحة حاليًا؟/ })).toHaveAttribute("aria-required", "true");
    expect(screen.getAllByText("(اختياري)").length).toBeGreaterThanOrEqual(6);
    // No location field unless displaced; no field outside the V1 set.
    expect(screen.queryByLabelText(/مكان النزوح الحالي/)).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/نوع السكن/)).not.toBeInTheDocument();
  });

  it("submits the whole proposed residence with a UUID client_reference and opens the new request", async () => {
    mockGet();
    const post = vi.spyOn(apiClient, "post").mockResolvedValue(outcome());
    const user = await renderForm();

    await user.clear(screen.getByLabelText(/^الحي/));
    await user.type(screen.getByLabelText(/^الحي/), "  حي النصر ");
    await chooseStatus(user, /نعم، نازحة/);
    await user.type(await screen.findByLabelText(/مكان النزوح الحالي/), "مواصي خانيونس");
    await user.type(screen.getByLabelText(/سبب الطلب/), "انتقلنا داخل المدينة");
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith(`/family/requests/${NEW_ID}`));
    expect(post).toHaveBeenCalledTimes(1);
    const [path, body] = post.mock.calls[0] as [string, Record<string, unknown>];
    expect(path).toBe(SUBMIT_PATH);
    expect(body).toEqual({
      type: "RESIDENCE_UPDATE",
      client_reference: expect.stringMatching(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/),
      reason: "انتقلنا داخل المدينة",
      data: {
        governorate: "خانيونس",
        city: "خانيونس",
        area: "البلد",
        neighborhood: "حي النصر",
        address_text: "شارع التجربة 1",
        original_residence_text: "بني سهيلا",
        displacement_status: "DISPLACED",
        displacement_location_text: "مواصي خانيونس",
      },
    });
    // Never a family, person, residence or member identifier.
    expect(JSON.stringify(body)).not.toMatch(/family_id|person|residence_id|member_ref|FAM-TEST/);
  });

  it("clears the displacement location when the family is not displaced", async () => {
    mockGet({ prof: { data: profile(residence({ displacement_status: "DISPLACED", displacement_location_text: "مواصي خانيونس" })) } });
    const post = vi.spyOn(apiClient, "post").mockResolvedValue(outcome());
    const user = await renderForm();

    expect(screen.getByLabelText(/مكان النزوح الحالي/)).toHaveValue("مواصي خانيونس");
    await chooseStatus(user, /لا، غير نازحة/);
    expect(screen.queryByLabelText(/مكان النزوح الحالي/)).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    await waitFor(() => expect(post).toHaveBeenCalled());
    const body = post.mock.calls[0][1] as { data: Record<string, unknown> };
    expect(body.data.displacement_status).toBe("NOT_DISPLACED");
    expect(body.data.displacement_location_text).toBeNull();
  });

  it("refuses an unchanged proposal without calling the server", async () => {
    mockGet();
    const post = vi.spyOn(apiClient, "post");
    const user = await renderForm();

    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText(/لم تغيّر أي بيانات/)).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it("validates lengths on the client", async () => {
    mockGet();
    const post = vi.spyOn(apiClient, "post");
    const user = await renderForm();

    await user.clear(screen.getByLabelText(/المدينة/));
    await user.click(screen.getByLabelText(/المدينة/));
    await user.paste("م".repeat(256));
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    expect(await screen.findByText("النص طويل جدًا.")).toBeInTheDocument();
    expect(screen.getByLabelText(/المدينة/)).toHaveAttribute("aria-invalid", "true");
    expect(post).not.toHaveBeenCalled();
  });

  it("shows the server's field errors on their fields", async () => {
    mockGet();
    vi.spyOn(apiClient, "post").mockRejectedValue(
      new ApiError(422, { message: "x", errors: { city: ["اسم المدينة غير صالح."] } })
    );
    const user = await renderForm();

    await user.type(screen.getByLabelText(/المدينة/), "!");
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    expect(await screen.findByText("اسم المدينة غير صالح.")).toBeInTheDocument();
    expect(screen.getByRole("alert")).toHaveTextContent("لم يُرسل الطلب");
    expect(router.push).not.toHaveBeenCalled();
  });

  it.each([
    ["CHANGE_REQUEST_ALREADY_OPEN", 409, /طلب تحديث سكن مفتوح بالفعل/],
    ["CHANGE_REQUEST_PRECONDITION_FAILED", 422, /لا يوجد سكن حالي مسجّل لأسرتك/],
    ["CHANGE_REQUEST_SUBMISSION_DISABLED", 503, /غير متاح حاليًا/],
    ["TOO_MANY_REQUESTS", 429, /محاولات كثيرة/],
  ])("explains %s safely", async (code, status, text) => {
    mockGet();
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(status, { message: "SQLSTATE secret", code }));
    const user = await renderForm();

    await user.type(screen.getByLabelText(/المدينة/), " الجديدة");
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(text);
    expect(document.body).not.toHaveTextContent("SQLSTATE");
  });

  it("re-sends the SAME client_reference when the same proposal is retried, and a new one after a change", async () => {
    mockGet();
    const post = vi
      .spyOn(apiClient, "post")
      .mockRejectedValueOnce(new TypeError("Failed to fetch"))
      .mockRejectedValueOnce(new TypeError("Failed to fetch"))
      .mockResolvedValueOnce(outcome(true));
    const user = await renderForm();
    const send = () => user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    await user.type(screen.getByLabelText(/المدينة/), " الجديدة");
    await send();
    expect(await screen.findByRole("alert")).toHaveTextContent("لن يُكرَّر طلبك");
    await send();
    await waitFor(() => expect(post).toHaveBeenCalledTimes(2));

    await user.type(screen.getByLabelText(/المنطقة/), " الغربية");
    await send();
    await waitFor(() => expect(post).toHaveBeenCalledTimes(3));

    const refs = post.mock.calls.map((call) => (call[1] as { client_reference: string }).client_reference);
    expect(refs[0]).toBe(refs[1]);
    expect(refs[2]).not.toBe(refs[0]);
    await waitFor(() => expect(router.push).toHaveBeenCalledWith(`/family/requests/${NEW_ID}`));
  });

  it("disables the form and the button while sending, and sends once", async () => {
    mockGet();
    let resolve: (v: unknown) => void = () => undefined;
    const post = vi.spyOn(apiClient, "post").mockImplementation(() => new Promise((r) => (resolve = r)));
    const user = await renderForm();

    await user.type(screen.getByLabelText(/المدينة/), " الجديدة");
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    const button = await screen.findByRole("button", { name: "جارٍ الإرسال…" });
    expect(button).toBeDisabled();
    expect(screen.getByLabelText(/المدينة/)).toBeDisabled();
    await user.click(button);
    expect(post).toHaveBeenCalledTimes(1);
    resolve(outcome());
    await waitFor(() => expect(router.push).toHaveBeenCalled());
  });

  it("keeps nothing in browser storage and is fully labelled", async () => {
    mockGet();
    await renderForm();

    for (const name of ["عنوان السكن الحالي", "النزوح"]) {
      expect(screen.getByRole("group", { name })).toBeInTheDocument();
    }
    for (const input of screen.getAllByRole("textbox")) {
      expect(input).toHaveAccessibleName();
    }
    expect(screen.getByRole("link", { name: "طلب جديد" })).toHaveAttribute("href", "/family/requests/new");
    expect(browserStorageDump()).not.toMatch(/خانيونس|حي الأمل/);
  });
});

// =================================================================== comparison

describe("the shared comparison (Staff and family)", () => {
  const rows = [
    { label: "الحي", current: "حي الأمل", proposed: "حي النصر" },
    { label: "مكان النزوح الحالي", current: "مواصي خانيونس", proposed: null },
  ];

  it("marks each changed row in text while the request is open", () => {
    renderWithClient(<ChangeRequestComparison rows={rows} />);
    const table = screen.getByRole("table");
    expect(within(table).getAllByText("تعديل مطلوب")).toHaveLength(2);
    expect(within(table).getByRole("columnheader", { name: "البيانات الحالية" })).toBeInTheDocument();
    expect(within(table).getByRole("columnheader", { name: "التعديل المطلوب" })).toBeInTheDocument();
  });

  it("drops the change markers once applied, because «current» is then the registry after the change", () => {
    renderWithClient(<ChangeRequestComparison rows={[{ label: "الحي", current: "حي النصر", proposed: "حي النصر" }]} applied />);
    expect(screen.getByText(/طُبّق هذا التعديل على السجل/)).toBeInTheDocument();
    expect(screen.queryByText("بدون تغيير")).not.toBeInTheDocument();
    expect(screen.queryByText("تعديل مطلوب")).not.toBeInTheDocument();
    expect(within(screen.getByRole("table")).getByRole("columnheader", { name: "السجل الآن" })).toBeInTheDocument();
  });
});
