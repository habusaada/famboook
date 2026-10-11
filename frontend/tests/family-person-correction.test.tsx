import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyPersonCorrectionForm } from "@/components/family/requests/family-person-correction-form";
import { ApiError, apiClient } from "@/lib/api/client";
import type { FamilyChangeRequestTypes } from "@/lib/api/family-change-requests";
import type { FamilyMember } from "@/lib/api/family-household";
import { browserStorageDump, renderWithClient } from "./helpers";

// PERSON_CORRECTION: the household head picks a member (by member_ref), sees
// the current values and proposes corrections. The server decides
// availability and validates. Synthetic data only.

const router = { push: vi.fn(), replace: vi.fn() };
vi.mock("next/navigation", () => ({
  useRouter: () => router,
  usePathname: () => "/family/requests/new/person-correction",
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
const REF_HEAD = "a".repeat(64);
const REF_SARA = "b".repeat(64);
const REF_GONE = "c".repeat(64);
const OPEN: FamilyChangeRequestTypes = { data: [{ type: "PERSON_CORRECTION" }], meta: { submission_enabled: true } };

function member(overrides: Partial<FamilyMember>): FamilyMember {
  return {
    member_ref: REF_SARA,
    available: true,
    full_name: "سارة الاختبار",
    relationship: null,
    is_household_head: false,
    gender: "FEMALE",
    birth_date: "2005-03-01",
    marital_status: "SINGLE",
    life_status: "ALIVE",
    death_date: null,
    national_id_masked: "*****4567",
    mobile_masked: "*******233",
    alternate_mobile_masked: null,
    alternate_mobile_owner_relation: null,
    membership_started_at: null,
    ...overrides,
  };
}

const MEMBERS = {
  data: {
    family_code: "FAM-TEST-1",
    members: [
      member({ member_ref: REF_HEAD, full_name: "رب الأسرة التجريبي", is_household_head: true, gender: "MALE", marital_status: "MARRIED", birth_date: "1980-01-01" }),
      member({}),
      member({ member_ref: REF_GONE, available: false, full_name: null }),
    ],
  },
};

function mockGet({ types = OPEN as unknown, members = MEMBERS as unknown } = {}) {
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    const value = path.endsWith("/types") ? types : path === "/api/v1/family/household/members" ? members : undefined;
    if (value instanceof Error) throw value;
    if (value === undefined) throw new Error(`unexpected GET ${path}`);
    return value;
  });
}

async function renderForm() {
  const user = userEvent.setup();
  renderWithClient(<FamilyPersonCorrectionForm />);
  await screen.findByRole("button", { name: "إرسال الطلب للمراجعة" });
  return user;
}

async function pick(user: ReturnType<typeof userEvent.setup>, name: string) {
  await user.click(screen.getByRole("combobox", { name: /الفرد/ }));
  await user.click(await screen.findByRole("option", { name }));
}

describe("availability", () => {
  it("is unavailable unless the server lists PERSON_CORRECTION", async () => {
    mockGet({ types: { data: [{ type: "RESIDENCE_UPDATE" }], meta: { submission_enabled: true } } });
    renderWithClient(<FamilyPersonCorrectionForm />);
    expect(await screen.findByRole("heading", { name: "طلبات تصحيح بيانات الأفراد غير متاحة حاليًا" })).toBeInTheDocument();
    expect(screen.queryByRole("combobox")).not.toBeInTheDocument();
  });

  it("shows an error with retry", async () => {
    mockGet({ members: new ApiError(500, { message: "x" }) });
    renderWithClient(<FamilyPersonCorrectionForm />);
    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر تحميل النموذج");
  });
});

describe("the person correction form", () => {
  it("lists only available members, marks the head, and shows current values for the chosen one", async () => {
    mockGet();
    const user = await renderForm();

    expect(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" })).toBeDisabled();
    await user.click(screen.getByRole("combobox", { name: /الفرد/ }));
    const options = (await screen.findAllByRole("option")).map((o) => o.textContent);
    expect(options).toEqual(["رب الأسرة التجريبي (رب الأسرة)", "سارة الاختبار"]);
    await user.keyboard("{Escape}");

    await pick(user, "سارة الاختبار");
    const current = screen.getByRole("region", { name: "البيانات الحالية في السجل" });
    expect(current).toHaveTextContent("سارة الاختبار");
    expect(current).toHaveTextContent("2005-03-01");
    expect(current).toHaveTextContent("أنثى");
    expect(screen.getByLabelText(/الاسم الكامل/)).toHaveValue("سارة الاختبار");
    expect(screen.getByLabelText(/تاريخ الميلاد/)).toHaveValue("2005-03-01");
    // Never the National ID or mobiles: they have their own procedures.
    expect(screen.queryByLabelText(/رقم الهوية/)).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/رقم الجوال/)).not.toBeInTheDocument();
  });

  it("submits the member_ref and the corrected values, then opens the request", async () => {
    mockGet();
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: { id: NEW_ID, request_code: "CRQ-000301", status: "SUBMITTED", replayed: false } });
    const user = await renderForm();

    await pick(user, "سارة الاختبار");
    await user.clear(screen.getByLabelText(/الاسم الكامل/));
    await user.type(screen.getByLabelText(/الاسم الكامل/), "سارة محمد الاختبار");
    await user.click(screen.getByRole("combobox", { name: /الحالة الاجتماعية/ }));
    await user.click(await screen.findByRole("option", { name: "متزوج/ة" }));
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith(`/family/requests/${NEW_ID}`));
    expect(post.mock.calls[0][1]).toEqual({
      type: "PERSON_CORRECTION",
      client_reference: expect.stringMatching(/^[0-9a-f-]{36}$/),
      reason: null,
      data: { member_ref: REF_SARA, full_name: "سارة محمد الاختبار", birth_date: "2005-03-01", gender: "FEMALE", marital_status: "MARRIED" },
    });
    expect(JSON.stringify(post.mock.calls[0][1])).not.toMatch(/national_id|mobile|person_id|family_id/);
  });

  it("refuses an unchanged proposal without calling the server", async () => {
    mockGet();
    const post = vi.spyOn(apiClient, "post");
    const user = await renderForm();

    await pick(user, "سارة الاختبار");
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText(/لم تغيّر أي بيانات/)).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it("validates on the client and shows the server's field errors and refusals", async () => {
    mockGet();
    const post = vi
      .spyOn(apiClient, "post")
      .mockRejectedValueOnce(new ApiError(422, { message: "x", errors: { gender: ["لا يمكن تصحيح الجنس حاليًا لوجود سجل حمل أو رضاعة نشط لهذا الفرد. تواصل مع فريق السجل."] } }))
      .mockRejectedValueOnce(new ApiError(409, { message: "x", code: "CHANGE_REQUEST_ALREADY_OPEN" }));
    const user = await renderForm();

    await pick(user, "سارة الاختبار");
    await user.clear(screen.getByLabelText(/الاسم الكامل/));
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText("الاسم الكامل مطلوب.")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();

    await user.type(screen.getByLabelText(/الاسم الكامل/), "سارة");
    await user.click(screen.getByRole("combobox", { name: /الجنس/ }));
    await user.click(await screen.findByRole("option", { name: "ذكر" }));
    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText(/لوجود سجل حمل أو رضاعة نشط/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "إرسال الطلب للمراجعة" }));
    expect(await screen.findByText(/طلب تصحيح مفتوح لهذا الفرد/)).toBeInTheDocument();
    expect(browserStorageDump()).not.toMatch(/سارة/);
  });
});
