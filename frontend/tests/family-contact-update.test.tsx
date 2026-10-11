import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyContactUpdateForm } from "@/components/family/requests/family-contact-update-form";
import { ApiError, apiClient } from "@/lib/api/client";
import { canStartFamilyRequest, familyRequestFormFor, type FamilyChangeRequestTypes } from "@/lib/api/family-change-requests";
import type { ContactUpdateState } from "@/lib/api/family-contact-update";
import type { FamilyMember } from "@/lib/api/family-household";
import { browserStorageDump, renderWithClient } from "./helpers";

// CONTACT_UPDATE (docs/11 FP-ADR-079): the household head changes a member's
// mobile — or their own, with the current password — then confirms with
// the code(s). The server decides and applies. Synthetic data only.

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => "/family/requests/new/contact-update",
  useSearchParams: () => new URLSearchParams(),
}));
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

const ID = "5b1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4e";
const REF_HEAD = "a".repeat(64);
const REF_SON = "b".repeat(64);
const REF_DECEASED = "d".repeat(64);
const OPEN: FamilyChangeRequestTypes = { data: [{ type: "CONTACT_UPDATE" }], meta: { submission_enabled: true } };
const PASSWORD = "synthetic current phrase";

function member(overrides: Partial<FamilyMember>): FamilyMember {
  return {
    member_ref: REF_SON,
    available: true,
    full_name: "ابن الاختبار",
    relationship: null,
    is_household_head: false,
    gender: "MALE",
    birth_date: "2005-03-01",
    marital_status: "SINGLE",
    life_status: "ALIVE",
    death_date: null,
    national_id_masked: "*****4567",
    mobile_masked: "05*****321",
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
      member({ member_ref: REF_HEAD, full_name: "رب الأسرة التجريبي", is_household_head: true, mobile_masked: "05*****567" }),
      member({}),
      member({ member_ref: REF_DECEASED, full_name: "جدّ متوفى", life_status: "DECEASED" }),
    ],
  },
};

function state(overrides: Partial<ContactUpdateState["verification"]> = {}): ContactUpdateState {
  const code = { masked: "05*****233", verified: false, expires_in_seconds: 300, resend_after_seconds: 60, can_resend: true };
  return {
    id: ID,
    request_code: "CRQ-000501",
    status: "SUBMITTED",
    replayed: false,
    verification: { self: false, trust_transfer: false, new_mobile: code, old_mobile: null, ...overrides },
  };
}

function mockGet(types: unknown = OPEN) {
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path.endsWith("/types")) return types;
    if (path === "/api/v1/family/household/members") return MEMBERS;
    throw new Error(`unexpected GET ${path}`);
  });
}

async function renderForm() {
  const user = userEvent.setup();
  renderWithClient(<FamilyContactUpdateForm />);
  await screen.findByRole("button", { name: "إرسال رمز التحقق" });
  return user;
}

async function pick(user: ReturnType<typeof userEvent.setup>, name: string) {
  await user.click(screen.getByRole("combobox", { name: /الفرد/ }));
  await user.click(await screen.findByRole("option", { name }));
}

describe("availability", () => {
  it("is offered only when the server lists CONTACT_UPDATE", async () => {
    expect(familyRequestFormFor("CONTACT_UPDATE")).toBe("/family/requests/new/contact-update");
    expect(canStartFamilyRequest(OPEN)).toBe(true);

    mockGet({ data: [{ type: "RESIDENCE_UPDATE" }], meta: { submission_enabled: true } });
    renderWithClient(<FamilyContactUpdateForm />);
    expect(await screen.findByRole("heading", { name: "تحديث رقم الجوال غير متاح حاليًا" })).toBeInTheDocument();
  });
});

describe("a member's number", () => {
  it("lists living members, sends the normalized number once, then completes with the code", async () => {
    mockGet();
    const post = vi
      .spyOn(apiClient, "post")
      .mockResolvedValueOnce({ data: state() })
      .mockResolvedValueOnce({ data: { id: ID, request_code: "CRQ-000501", status: "APPLIED", replayed: false, trust_transferred: false } });
    const user = await renderForm();

    await user.click(screen.getByRole("combobox", { name: /الفرد/ }));
    expect((await screen.findAllByRole("option")).map((o) => o.textContent)).toEqual(["رب الأسرة التجريبي (أنت)", "ابن الاختبار"]);
    await user.keyboard("{Escape}");

    await pick(user, "ابن الاختبار");
    expect(screen.getByText("05*****321")).toBeInTheDocument();
    expect(screen.queryByLabelText("كلمة المرور الحالية")).not.toBeInTheDocument();
    expect(screen.getByText(/لا يمنح هذا الفرد أي حساب/)).toBeInTheDocument();
    await user.type(screen.getByLabelText("رقم الجوال الجديد"), "056 111 2233");
    await user.click(screen.getByRole("button", { name: "إرسال رمز التحقق" }));

    expect(await screen.findByLabelText(/الرمز المرسل إلى الرقم الجديد/)).toBeInTheDocument();
    expect(post.mock.calls[0][0]).toBe("/api/v1/family/contact-updates");
    expect(post.mock.calls[0][1]).toEqual({ member_ref: REF_SON, mobile: "0561112233", client_reference: expect.stringMatching(/^[0-9a-f-]{36}$/) });
    expect(screen.queryByLabelText(/رقمك الحالي/)).not.toBeInTheDocument();

    await user.type(screen.getByLabelText(/الرمز المرسل إلى الرقم الجديد/), "123456");
    await user.click(screen.getByRole("button", { name: "تأكيد وتحديث الرقم" }));

    expect(await screen.findByText("تم تحديث رقم الجوال.")).toBeInTheDocument();
    expect(post.mock.calls[1]).toEqual([`/api/v1/family/contact-updates/${ID}/verify`, { code: "123456" }]);
    expect(screen.getByRole("link", { name: "عرض الطلب" })).toHaveAttribute("href", `/family/requests/${ID}`);
    expect(browserStorageDump()).not.toMatch(/0561112233|123456/);
  });

  it("validates the number on the client", async () => {
    mockGet();
    const post = vi.spyOn(apiClient, "post");
    const user = await renderForm();

    await pick(user, "ابن الاختبار");
    await user.type(screen.getByLabelText("رقم الجوال الجديد"), "0791234567");
    await user.click(screen.getByRole("button", { name: "إرسال رمز التحقق" }));
    expect(await screen.findByText("رقم الجوال يجب أن يتكون من 10 أرقام ويبدأ بـ 05.")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it("shows a wrong code, then a locked request with a fresh start", async () => {
    mockGet();
    vi.spyOn(apiClient, "post")
      .mockResolvedValueOnce({ data: state() })
      .mockRejectedValueOnce(new ApiError(422, { message: "x", errors: { code: ["رمز التحقق غير صحيح."] } }))
      .mockRejectedValueOnce(new ApiError(423, { message: "x", code: "OTP_LOCKED" }));
    const user = await renderForm();

    await pick(user, "ابن الاختبار");
    await user.type(screen.getByLabelText("رقم الجوال الجديد"), "0561112233");
    await user.click(screen.getByRole("button", { name: "إرسال رمز التحقق" }));
    await user.type(await screen.findByLabelText(/الرمز المرسل إلى الرقم الجديد/), "000000");
    await user.click(screen.getByRole("button", { name: "تأكيد وتحديث الرقم" }));
    expect(await screen.findByText("رمز التحقق غير صحيح.")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "تأكيد وتحديث الرقم" }));
    expect(await screen.findByText("تم إيقاف هذا الرمز. ابدأ من جديد.")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "ابدأ من جديد" }));
    expect(await screen.findByRole("button", { name: "إرسال رمز التحقق" })).toBeInTheDocument();
  });

  it("resends a code and explains the cooldown", async () => {
    mockGet();
    const post = vi
      .spyOn(apiClient, "post")
      .mockResolvedValueOnce({ data: state() })
      .mockRejectedValueOnce(new ApiError(429, { message: "x", code: "OTP_COOLDOWN" }))
      .mockResolvedValueOnce({ data: state() });
    const user = await renderForm();

    await pick(user, "ابن الاختبار");
    await user.type(screen.getByLabelText("رقم الجوال الجديد"), "0561112233");
    await user.click(screen.getByRole("button", { name: "إرسال رمز التحقق" }));
    await screen.findByLabelText(/الرمز المرسل إلى الرقم الجديد/);

    await user.click(screen.getByRole("button", { name: "إعادة إرسال الرمز" }));
    expect(await screen.findByText("يمكن طلب رمز جديد بعد قليل.")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "إعادة إرسال الرمز" }));
    expect(await screen.findByText(/أُرسل رمز جديد/)).toBeInTheDocument();
    expect(post.mock.calls[2]).toEqual([`/api/v1/family/contact-updates/${ID}/resend`, { target: "new" }]);
  });
});

describe("the head's own number", () => {
  it("requires the current password, then both codes when the trust moves (R2)", async () => {
    mockGet();
    const post = vi
      .spyOn(apiClient, "post")
      .mockResolvedValueOnce({
        data: state({ self: true, trust_transfer: true, old_mobile: { masked: "05*****567", verified: false, expires_in_seconds: 300, resend_after_seconds: 60, can_resend: true } }),
      })
      .mockResolvedValueOnce({ data: { id: ID, request_code: "CRQ-000501", status: "APPLIED", replayed: false, trust_transferred: true } });
    const user = await renderForm();

    await pick(user, "رب الأسرة التجريبي (أنت)");
    expect(screen.getByText(/وينتقل التوثيق إلى الرقم الجديد/)).toBeInTheDocument();
    await user.type(screen.getByLabelText("رقم الجوال الجديد"), "0561112233");
    await user.click(screen.getByRole("button", { name: "إرسال رمز التحقق" }));
    expect(await screen.findByText("أدخل كلمة المرور الحالية لتغيير رقم جوالك.")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();

    await user.type(screen.getByLabelText("كلمة المرور الحالية"), PASSWORD);
    await user.click(screen.getByRole("button", { name: "إرسال رمز التحقق" }));
    await screen.findByLabelText(/الرمز المرسل إلى رقمك الحالي/);
    expect(post.mock.calls[0][1]).toMatchObject({ member_ref: REF_HEAD, current_password: PASSWORD });

    // Both codes are required on the client too.
    await user.type(screen.getByLabelText(/الرمز المرسل إلى الرقم الجديد/), "123456");
    await user.click(screen.getByRole("button", { name: "تأكيد وتحديث الرقم" }));
    expect(await screen.findByText("أدخل الرمز المكوّن من 6 أرقام.")).toBeInTheDocument();
    await user.type(screen.getByLabelText(/الرمز المرسل إلى رقمك الحالي/), "654321");
    await user.click(screen.getByRole("button", { name: "تأكيد وتحديث الرقم" }));

    expect(await screen.findByText(/انتقل توثيق رقمك إلى الرقم الجديد/)).toBeInTheDocument();
    expect(post.mock.calls[1][1]).toEqual({ code: "123456", old_code: "654321" });
    expect(browserStorageDump()).not.toMatch(/synthetic|123456|654321/);
  });

  it("explains the recovery limitation when the trust cannot move (R1)", async () => {
    mockGet();
    vi.spyOn(apiClient, "post")
      .mockResolvedValueOnce({ data: state({ self: true, trust_transfer: false }) })
      .mockResolvedValueOnce({ data: { id: ID, request_code: "CRQ-000501", status: "APPLIED", replayed: false, trust_transferred: false } });
    const user = await renderForm();

    await pick(user, "رب الأسرة التجريبي (أنت)");
    await user.type(screen.getByLabelText("رقم الجوال الجديد"), "0561112233");
    await user.type(screen.getByLabelText("كلمة المرور الحالية"), PASSWORD);
    await user.click(screen.getByRole("button", { name: "إرسال رمز التحقق" }));
    await user.type(await screen.findByLabelText(/الرمز المرسل إلى الرقم الجديد/), "123456");
    await user.click(screen.getByRole("button", { name: "تأكيد وتحديث الرقم" }));

    expect(await screen.findByText(/لا يمكن استخدامه لاستعادة كلمة المرور/)).toBeInTheDocument();
  });

  it("shows the server's refusal of a wrong current password", async () => {
    mockGet();
    vi.spyOn(apiClient, "post").mockRejectedValueOnce(new ApiError(422, { message: "x", errors: { current_password: ["كلمة المرور الحالية غير صحيحة."] } }));
    const user = await renderForm();

    await pick(user, "رب الأسرة التجريبي (أنت)");
    await user.type(screen.getByLabelText("رقم الجوال الجديد"), "0561112233");
    await user.type(screen.getByLabelText("كلمة المرور الحالية"), "wrong synthetic phrase");
    await user.click(screen.getByRole("button", { name: "إرسال رمز التحقق" }));

    expect(await screen.findByText("كلمة المرور الحالية غير صحيحة.")).toBeInTheDocument();
    await waitFor(() => expect(screen.getByLabelText("كلمة المرور الحالية")).toHaveAttribute("aria-invalid", "true"));
  });
});
