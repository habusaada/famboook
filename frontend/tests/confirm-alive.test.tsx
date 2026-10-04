import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import { PersonProfileView } from "@/components/people/person-profile-view";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CurrentUser } from "@/lib/api/auth";
import type { LifeStatus, PersonDetail } from "@/lib/types/api/person";
import { renderWithClient } from "./helpers";

// FU-07: the Staff «تأكيد أنه على قيد الحياة» action on the person profile.
// Synthetic data only.

const router = { replace: vi.fn(), push: vi.fn() };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => "/people/PER-000123" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

function person(life_status: LifeStatus): PersonDetail {
  return {
    person_code: "PER-000123",
    full_name: "زوجة تجريبية",
    gender: "FEMALE",
    marital_status: "UNKNOWN",
    birth_date: null,
    mobile: null,
    alternate_mobile: null,
    alternate_mobile_owner_relation: null,
    life_status,
    is_active: true,
  };
}

function staff(permissions: string[]): CurrentUser {
  return { name: "موظف تجريبي", email: "staff@example.test", role: "ADMINISTRATOR", role_label: "مسؤول", permissions };
}

function renderProfile(life: LifeStatus, permissions = ["person.view", "person.record-death"]) {
  const get = vi.spyOn(apiClient, "get").mockResolvedValue({ data: person(life) });
  const result = renderWithClient(
    <AuthContext.Provider value={authValue(staff(permissions))}>
      <PersonProfileView personCode="PER-000123" />
    </AuthContext.Provider>
  );

  return { get, ...result };
}

const action = () => screen.queryByRole("button", { name: "تأكيد أنه على قيد الحياة" });

beforeEach(() => {
  router.push.mockReset();
});

describe("who sees the action", () => {
  it("is offered for an UNKNOWN life status to a holder of person.record-death", async () => {
    renderProfile("UNKNOWN");

    expect(await screen.findByRole("button", { name: "تأكيد أنه على قيد الحياة" })).toBeInTheDocument();
  });

  it.each(["ALIVE", "DECEASED"] as const)("is never offered for %s — and nothing restores a death", async (life) => {
    renderProfile(life);
    await screen.findByText("البيانات الشخصية");

    expect(action()).not.toBeInTheDocument();
    expect(screen.queryByText(/استعادة|إلغاء الوفاة/)).not.toBeInTheDocument();
  });

  it("is hidden without person.record-death", async () => {
    renderProfile("UNKNOWN", ["person.view", "person.update"]);
    await screen.findByText("البيانات الشخصية");

    expect(action()).not.toBeInTheDocument();
  });
});

describe("the confirmation dialog", () => {
  it("explains the change and requires a verification method before sending anything", async () => {
    const post = vi.spyOn(apiClient, "post");
    renderProfile("UNKNOWN");
    await userEvent.click(await screen.findByRole("button", { name: "تأكيد أنه على قيد الحياة" }));

    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent("من «غير معروف» إلى «حي» فقط");
    for (const label of ["تحقق حضوري", "اتصال هاتفي", "مراجعة السجل"]) {
      expect(within(dialog).getByRole("radio", { name: label })).not.toBeChecked();
    }

    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الحالة الحياتية" }));

    expect(await within(dialog).findByText("اختر طريقة التحقق")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it.each([
    ["تحقق حضوري", "IN_PERSON"],
    ["اتصال هاتفي", "STAFF_CALLBACK"],
    ["مراجعة السجل", "AUTHORIZED_RECORD_REVIEW"],
  ])("sends only the chosen method (%s → %s), closes and refreshes the person", async (label, method) => {
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: person("ALIVE") });
    const { get } = renderProfile("UNKNOWN");
    await userEvent.click(await screen.findByRole("button", { name: "تأكيد أنه على قيد الحياة" }));
    const dialog = await screen.findByRole("dialog");

    await userEvent.click(within(dialog).getByRole("radio", { name: label }));
    get.mockResolvedValue({ data: person("ALIVE") });
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الحالة الحياتية" }));

    await waitFor(() => expect(post).toHaveBeenCalledWith("/api/v1/people/PER-000123/confirm-alive", { verification_method: method }));
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    // The person query is refetched; the action disappears once ALIVE.
    await waitFor(() => expect(action()).not.toBeInTheDocument());
    expect(get.mock.calls.filter(([path]) => path === "/api/v1/people/PER-000123").length).toBeGreaterThanOrEqual(2);
  });

  it.each([
    [403, "لا تملك صلاحية تأكيد الحالة الحياتية."],
    [409, "تغيّرت الحالة الحياتية لهذا الشخص منذ فتح الصفحة. أعد تحميل الصفحة."],
  ])("keeps the dialog open with a clear message on %i", async (status, message) => {
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(status, { message: "x", code: "PERSON_ALREADY_ALIVE" }));
    renderProfile("UNKNOWN");
    await userEvent.click(await screen.findByRole("button", { name: "تأكيد أنه على قيد الحياة" }));
    const dialog = await screen.findByRole("dialog");

    await userEvent.click(within(dialog).getByRole("radio", { name: "تحقق حضوري" }));
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الحالة الحياتية" }));

    expect(await within(dialog).findByText(message)).toBeInTheDocument();
    expect(screen.getByRole("dialog")).toBeInTheDocument();
  });
});
