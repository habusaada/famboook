import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import { PersonProfileView } from "@/components/people/person-profile-view";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CurrentUser } from "@/lib/api/auth";
import type { LifeStatus, PersonDetail } from "@/lib/types/api/person";
import { renderWithClient } from "./helpers";

// FU-10: the Staff «تسجيل وفاة» action on the person profile. Synthetic
// data only.

const router = { replace: vi.fn(), push: vi.fn() };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => "/people/PER-000123" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const PATH = "/api/v1/people/PER-000123/record-death";

function person(life_status: LifeStatus, head = false): PersonDetail {
  return {
    person_code: "PER-000123",
    full_name: "فرد تجريبي",
    gender: "MALE",
    marital_status: "MARRIED",
    birth_date: "1980-01-15",
    mobile: null,
    alternate_mobile: null,
    alternate_mobile_owner_relation: null,
    life_status,
    is_active: true,
    family_membership: {
      family_code: "FAM-000123",
      is_household_head: head,
      relationship_type: head ? { id: 1, code: "HEAD", name: "رب الأسرة" } : { id: 2, code: "SON", name: "ابن" },
      started_at: null,
    },
  } as PersonDetail;
}

function staff(permissions: string[]): CurrentUser {
  return { name: "موظف تجريبي", email: "staff@example.test", role: "ADMINISTRATOR", role_label: "مسؤول", permissions };
}

function renderProfile(subject: PersonDetail, permissions = ["person.view", "person.record-death"]) {
  const get = vi.spyOn(apiClient, "get").mockResolvedValue({ data: subject });
  const result = renderWithClient(
    <AuthContext.Provider value={authValue(staff(permissions))}>
      <PersonProfileView personCode="PER-000123" />
    </AuthContext.Provider>
  );

  return { get, ...result };
}

const action = () => screen.queryByRole("button", { name: "تسجيل وفاة" });

async function openDialog() {
  await userEvent.click(await screen.findByRole("button", { name: "تسجيل وفاة" }));
  return screen.findByRole("dialog");
}

beforeEach(() => {
  vi.restoreAllMocks();
  router.push.mockReset();
});

describe("who sees the action", () => {
  it.each(["ALIVE", "UNKNOWN"] as const)("is offered for %s to a holder of person.record-death", async (life) => {
    renderProfile(person(life));

    expect(await screen.findByRole("button", { name: "تسجيل وفاة" })).toBeInTheDocument();
  });

  it("is never offered for DECEASED — and nothing restores a death", async () => {
    renderProfile(person("DECEASED"));
    await screen.findByText("البيانات الشخصية");

    expect(action()).not.toBeInTheDocument();
    expect(screen.queryByText(/استعادة|إلغاء الوفاة/)).not.toBeInTheDocument();
  });

  it("is hidden without person.record-death", async () => {
    renderProfile(person("ALIVE"), ["person.view", "person.update"]);
    await screen.findByText("البيانات الشخصية");

    expect(action()).not.toBeInTheDocument();
  });

  it("sits next to the confirm-alive action for UNKNOWN", async () => {
    renderProfile(person("UNKNOWN"));

    expect(await screen.findByRole("button", { name: "تأكيد أنه على قيد الحياة" })).toBeInTheDocument();
    expect(action()).toBeInTheDocument();
  });
});

describe("the death dialog", () => {
  it("warns that the change is official and irreversible, and requires explicit choices before sending", async () => {
    const post = vi.spyOn(apiClient, "post");
    renderProfile(person("ALIVE"));
    const dialog = await openDialog();

    expect(dialog).toHaveTextContent("إجراء لا يمكن التراجع عنه");
    expect(dialog).toHaveTextContent("لا يمكن التراجع عنه من خلال النظام الحالي");
    expect(dialog.querySelector("[data-head-warning]")).toBeNull();
    for (const label of ["تاريخ الوفاة معروف", "تاريخ الوفاة غير معروف", "تحقق حضوري", "اتصال هاتفي", "مراجعة السجل"]) {
      expect(within(dialog).getByRole("radio", { name: label })).not.toBeChecked();
    }

    await userEvent.click(within(dialog).getByRole("button", { name: "تسجيل الوفاة" }));

    expect(await within(dialog).findByText("أدخل تاريخ الوفاة، أو اختر أن تاريخ الوفاة غير معروف")).toBeInTheDocument();
    expect(within(dialog).getByText("اختر طريقة التحقق")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it("shows the household-head warning for the current head", async () => {
    renderProfile(person("ALIVE", true));
    const dialog = await openDialog();

    const warning = dialog.querySelector("[data-head-warning]");
    expect(warning).not.toBeNull();
    expect(warning).toHaveTextContent("هذا الشخص هو رب الأسرة الحالي");
    expect(warning).toHaveTextContent("سيتوقف وصول هذه الأسرة إلى بوابة الأسرة");
    expect(warning).toHaveTextContent("لن يُعيَّن رب أسرة بديل تلقائيًا");
    expect(warning).toHaveTextContent("مراجعة رب الأسرة");
  });

  it("requires a date once «known» is chosen and refuses a date before the birth date", async () => {
    const post = vi.spyOn(apiClient, "post");
    renderProfile(person("ALIVE"));
    const dialog = await openDialog();

    await userEvent.click(within(dialog).getByRole("radio", { name: "تاريخ الوفاة معروف" }));
    await userEvent.click(within(dialog).getByRole("radio", { name: "تحقق حضوري" }));
    await userEvent.click(within(dialog).getByRole("button", { name: "تسجيل الوفاة" }));
    expect(await within(dialog).findByText("أدخل تاريخ الوفاة")).toBeInTheDocument();

    await userEvent.type(within(dialog).getByLabelText("تاريخ الوفاة"), "1979-12-31");
    await userEvent.click(within(dialog).getByRole("button", { name: "تسجيل الوفاة" }));
    expect(await within(dialog).findByText("تاريخ الوفاة لا يمكن أن يسبق تاريخ الميلاد.")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it("sends a known date with the method, closes and refreshes the person", async () => {
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: person("DECEASED") });
    const { get } = renderProfile(person("ALIVE"));
    const dialog = await openDialog();

    await userEvent.click(within(dialog).getByRole("radio", { name: "تاريخ الوفاة معروف" }));
    await userEvent.type(within(dialog).getByLabelText("تاريخ الوفاة"), "2024-11-20");
    await userEvent.click(within(dialog).getByRole("radio", { name: "اتصال هاتفي" }));
    get.mockResolvedValue({ data: person("DECEASED") });
    await userEvent.click(within(dialog).getByRole("button", { name: "تسجيل الوفاة" }));

    await waitFor(() => expect(post).toHaveBeenCalledWith(PATH, { death_date: "2024-11-20", verification_method: "STAFF_CALLBACK" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    await waitFor(() => expect(action()).not.toBeInTheDocument());
    expect(get.mock.calls.filter(([path]) => path === "/api/v1/people/PER-000123").length).toBeGreaterThanOrEqual(2);
  });

  it("sends an explicitly unknown date as null", async () => {
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: person("DECEASED") });
    renderProfile(person("UNKNOWN"));
    const dialog = await openDialog();

    await userEvent.click(within(dialog).getByRole("radio", { name: "تاريخ الوفاة غير معروف" }));
    await userEvent.click(within(dialog).getByRole("radio", { name: "مراجعة السجل" }));
    await userEvent.click(within(dialog).getByRole("button", { name: "تسجيل الوفاة" }));

    await waitFor(() => expect(post).toHaveBeenCalledWith(PATH, { death_date: null, verification_method: "AUTHORIZED_RECORD_REVIEW" }));
  });

  it("on 409 shows the fixed conflict wording and refreshes the person when closed", async () => {
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(409, { message: "x", code: "PERSON_ALREADY_DECEASED" }));
    const { get } = renderProfile(person("ALIVE"));
    const dialog = await openDialog();

    await userEvent.click(within(dialog).getByRole("radio", { name: "تاريخ الوفاة غير معروف" }));
    await userEvent.click(within(dialog).getByRole("radio", { name: "تحقق حضوري" }));
    await userEvent.click(within(dialog).getByRole("button", { name: "تسجيل الوفاة" }));

    expect(await within(dialog).findByText("وفاة هذا الشخص مسجّلة مسبقًا. لم يُغيَّر شيء، وستُحدَّث بياناته عند إغلاق النافذة.")).toBeInTheDocument();
    const before = get.mock.calls.length;
    get.mockResolvedValue({ data: person("DECEASED") });

    await userEvent.click(within(dialog).getByRole("button", { name: "إلغاء" }));

    await waitFor(() => expect(get.mock.calls.length).toBeGreaterThan(before));
    await waitFor(() => expect(action()).not.toBeInTheDocument());
  });

  it("keeps the dialog open with a clear message on 403", async () => {
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(403, { message: "x" }));
    renderProfile(person("ALIVE"));
    const dialog = await openDialog();

    await userEvent.click(within(dialog).getByRole("radio", { name: "تاريخ الوفاة غير معروف" }));
    await userEvent.click(within(dialog).getByRole("radio", { name: "تحقق حضوري" }));
    await userEvent.click(within(dialog).getByRole("button", { name: "تسجيل الوفاة" }));

    expect(await within(dialog).findByText("لا تملك صلاحية تسجيل الوفاة.")).toBeInTheDocument();
    expect(screen.getByRole("dialog")).toBeInTheDocument();
  });
});
