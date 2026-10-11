import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyAddMemberForm } from "@/components/family/requests/family-add-member-form";
import { ApiError, apiClient } from "@/lib/api/client";
import type { FamilyChangeRequestTypes } from "@/lib/api/family-change-requests";
import { cleanDigits } from "@/lib/schemas/family-add-member";
import { browserStorageDump, renderWithClient } from "./helpers";

// docs/11 FP-ADR-076: the household head's ADD_FAMILY_MEMBER form. The server
// decides availability (type discovery) and validates; the relationship
// options come from the registry. Synthetic data and IDs only.

const router = { push: vi.fn(), replace: vi.fn() };
vi.mock("next/navigation", () => ({
  useRouter: () => router,
  usePathname: () => "/family/requests/new/add-family-member",
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
  router.push.mockReset();
});

const NEW_ID = "5b1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4e";
const TYPES_PATH = "/api/v1/family/change-requests/types";
const RELATIONSHIPS_PATH = "/api/v1/family/change-requests/relationship-types";
const OPEN: FamilyChangeRequestTypes = { data: [{ type: "ADD_FAMILY_MEMBER" }], meta: { submission_enabled: true } };
const RELATIONSHIPS = {
  data: [
    { code: "SPOUSE", name: "زوج/زوجة" },
    { code: "SON", name: "ابن" },
    { code: "DAUGHTER", name: "ابنة" },
  ],
};

function mockGet({ types = OPEN as unknown, relationships = RELATIONSHIPS as unknown } = {}) {
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    const value = path === TYPES_PATH ? types : path === RELATIONSHIPS_PATH ? relationships : undefined;
    if (value instanceof Error) throw value;
    if (value === undefined) throw new Error(`unexpected GET ${path}`);
    return value;
  });
}

const outcome = (replayed = false) => ({ data: { id: NEW_ID, request_code: "CRQ-000201", status: "SUBMITTED", replayed } });

async function renderForm() {
  const user = userEvent.setup();
  renderWithClient(<FamilyAddMemberForm />);
  await screen.findByRole("button", { name: "إرسال الطلب للمراجعة" });
  return user;
}

async function choose(user: ReturnType<typeof userEvent.setup>, label: RegExp, option: string) {
  await user.click(screen.getByRole("combobox", { name: label }));
  await user.click(await screen.findByRole("option", { name: option }));
}

async function fillRequired(user: ReturnType<typeof userEvent.setup>, nationalId = "401234567") {
  await user.type(screen.getByLabelText(/الاسم الكامل/), "فرد تجريبي جديد");
  await user.type(screen.getByLabelText(/رقم الهوية/), nationalId);
  await choose(user, /الجنس/, "أنثى");
  await choose(user, /صلة القرابة برب الأسرة/, "ابنة");
}

describe("availability", () => {
  it("is unavailable unless the server lists ADD_FAMILY_MEMBER — the Production state", async () => {
    const get = mockGet({ types: { data: [{ type: "RESIDENCE_UPDATE" }], meta: { submission_enabled: true } } });
    renderWithClient(<FamilyAddMemberForm />);

    expect(await screen.findByRole("heading", { name: "طلبات إضافة فرد غير متاحة حاليًا" })).toBeInTheDocument();
    expect(screen.queryByRole("textbox")).not.toBeInTheDocument();
    // The relationship options are not even requested.
    expect(get.mock.calls.map(([p]) => p)).not.toContain(RELATIONSHIPS_PATH);
  });

  it("is unavailable while the channel is closed", async () => {
    mockGet({ types: { data: [], meta: { submission_enabled: false } } });
    renderWithClient(<FamilyAddMemberForm />);
    expect(await screen.findByRole("heading", { name: "طلبات إضافة فرد غير متاحة حاليًا" })).toBeInTheDocument();
  });

  it("shows an error with retry", async () => {
    mockGet({ relationships: new ApiError(500, { message: "x" }) });
    renderWithClient(<FamilyAddMemberForm />);
    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر تحميل النموذج");
    expect(screen.getByRole("button", { name: /إعادة المحاولة/ })).toBeInTheDocument();
  });
});

describe("the add-member form", () => {
  it("marks the required fields, offers the registry's relationships and never HEAD", async () => {
    mockGet();
    const user = await renderForm();

    for (const label of [/الاسم الكامل/, /رقم الهوية/]) expect(screen.getByLabelText(label)).toHaveAttribute("aria-required", "true");
    for (const label of [/الجنس/, /صلة القرابة برب الأسرة/]) expect(screen.getByRole("combobox", { name: label })).toHaveAttribute("aria-required", "true");
    expect(screen.getAllByText("(اختياري)").length).toBeGreaterThanOrEqual(4);
    expect(screen.getByText(/لن تتغير بيانات السجل الرسمي إلا بعد اعتماد الطلب وتطبيقه/)).toBeInTheDocument();
    expect(screen.getByText(/«تسجيل مولود»/)).toBeInTheDocument();

    await user.click(screen.getByRole("combobox", { name: /صلة القرابة برب الأسرة/ }));
    const options = (await screen.findAllByRole("option")).map((o) => o.textContent);
    expect(options).toEqual(["زوج/زوجة", "ابن", "ابنة"]);
    expect(options).not.toContain("رب الأسرة");
  });

  it("submits a canonical nine-digit National ID with a UUID client_reference and opens the request", async () => {
    mockGet();
    const post = vi.spyOn(apiClient, "post").mockResolvedValue(outcome());
    const user = await renderForm();

    await fillRequired(user, "٤٠١-٢٣٤ ٥٦٧");
    await user.type(screen.getByLabelText(/رقم الجوال/), "059 111 2233");
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith(`/family/requests/${NEW_ID}`));
    const [path, body] = post.mock.calls[0] as [string, Record<string, unknown>];
    expect(path).toBe("/api/v1/family/change-requests");
    expect(body).toEqual({
      type: "ADD_FAMILY_MEMBER",
      client_reference: expect.stringMatching(/^[0-9a-f-]{36}$/),
      reason: null,
      data: {
        full_name: "فرد تجريبي جديد",
        national_id: "401234567",
        gender: "FEMALE",
        relationship: "DAUGHTER",
        birth_date: null,
        marital_status: null,
        mobile: "0591112233",
      },
    });
    expect(JSON.stringify(body)).not.toMatch(/family_id|person_id|member_ref|is_household_head/);
  });

  it("requires a real National ID — never empty, short or a placeholder — even for a newborn", async () => {
    mockGet();
    const post = vi.spyOn(apiClient, "post");
    const user = await renderForm();

    await user.type(screen.getByLabelText(/الاسم الكامل/), "مولود");
    await choose(user, /الجنس/, "ذكر");
    await choose(user, /صلة القرابة برب الأسرة/, "ابن");
    await user.type(screen.getByLabelText(/تاريخ الميلاد/), new Date().toISOString().slice(0, 10));
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText("رقم الهوية مطلوب.")).toBeInTheDocument();

    for (const [value, message] of [
      ["40123456", "رقم الهوية يجب أن يتكون من 9 أرقام."],
      ["000000000", "أدخل رقم الهوية الحقيقي كما في الوثيقة."],
    ]) {
      await user.clear(screen.getByLabelText(/رقم الهوية/));
      await user.type(screen.getByLabelText(/رقم الهوية/), value);
      await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
      expect(await screen.findByText(message)).toBeInTheDocument();
    }
    expect(post).not.toHaveBeenCalled();
  });

  it("shows server field errors and refusals without revealing whether the ID is known", async () => {
    mockGet();
    vi.spyOn(apiClient, "post")
      .mockRejectedValueOnce(new ApiError(422, { message: "x", errors: { national_id: ["رقم الهوية يجب أن يتكون من 9 أرقام."] } }))
      .mockRejectedValueOnce(new ApiError(409, { message: "x", code: "CHANGE_REQUEST_ALREADY_OPEN" }));
    const user = await renderForm();

    await fillRequired(user);
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText("رقم الهوية يجب أن يتكون من 9 أرقام.")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText(/طلب مفتوح لإضافة فرد بنفس رقم الهوية/)).toBeInTheDocument();
    expect(document.body).not.toHaveTextContent(/مسجّل مسبقًا|PER-|أسرة أخرى/);
  });

  it("re-sends the same client_reference for the same proposal and keeps nothing in browser storage", async () => {
    mockGet();
    const post = vi.spyOn(apiClient, "post").mockRejectedValueOnce(new TypeError("Failed to fetch")).mockResolvedValueOnce(outcome(true));
    const user = await renderForm();

    await fillRequired(user);
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText(/لن يُكرَّر طلبك/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
    const refs = post.mock.calls.map((c) => (c[1] as { client_reference: string }).client_reference);
    expect(refs[0]).toBe(refs[1]);
    expect(browserStorageDump()).not.toMatch(/401234567|فرد تجريبي/);
  });
});

describe("cleanDigits", () => {
  it("converts Arabic-Indic digits and drops separators like the server", () => {
    expect(cleanDigits("٤٠١-٢٣٤ ٥٦٧")).toBe("401234567");
    expect(cleanDigits("A01234567")).toBe("A01234567");
  });
});
