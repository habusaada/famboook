import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClientProvider } from "@tanstack/react-query";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import { FamilyIdentityHeader } from "@/components/families/family-identity-header";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CurrentUser } from "@/lib/api/auth";
import type { FamilyDetail } from "@/lib/types/api/family";
import { renderWithClient } from "./helpers";

// FU-10: the Staff «تسجيل إقرار أسرة» action in the family identity header.
// Synthetic data only.

const PATH = "/api/v1/families/FAM-000123/household-declarations";

// The UI formats with the "ar" locale; its digits depend on the ICU data.
const n = (value: number) => new Intl.NumberFormat("ar").format(value);

function family(overrides: Partial<FamilyDetail> = {}): FamilyDetail {
  return {
    family_code: "FAM-000123",
    clan: { code: "TEST", name: "عائلة الاختبار", is_active: true },
    branch: null,
    status: "ACTIVE",
    registration_date: "2026-09-01",
    registration_source: "PAPER_FORM",
    paper_form_no: null,
    notes: null,
    updated_at: null,
    residence: null,
    member_count: 2,
    declared_household_size: 7,
    declared_living_sons: 3,
    declared_living_daughters: 2,
    declared_at: "2026-09-01",
    declaration_source: "PAPER_FORM",
    current_declaration_id: 41,
    male_count: 1,
    female_count: 1,
    members: [],
    ...overrides,
  };
}

const NONE = {
  declared_household_size: null,
  declared_living_sons: null,
  declared_living_daughters: null,
  declared_at: null,
  declaration_source: null,
  current_declaration_id: null,
} satisfies Partial<FamilyDetail>;

function staff(permissions: string[]): CurrentUser {
  return { name: "موظف تجريبي", email: "staff@example.test", role: "DATA_ENTRY", role_label: "مدخل بيانات", permissions };
}

const snapshot = { health: null, needs: null, assessment: null, activity: null };

function renderHeader(subject: FamilyDetail, permissions = ["family.view", "family.update"]) {
  const result = renderWithClient(
    <AuthContext.Provider value={authValue(staff(permissions))}>
      <FamilyIdentityHeader family={subject} snapshot={snapshot} />
    </AuthContext.Provider>
  );
  const invalidate = vi.spyOn(result.client, "invalidateQueries");

  return { invalidate, ...result };
}

async function openDialog() {
  await userEvent.click(screen.getByRole("button", { name: "تسجيل إقرار أسرة" }));
  return screen.findByRole("dialog");
}

const input = (dialog: HTMLElement, label: string) => within(dialog).getByLabelText(new RegExp(label));

beforeEach(() => {
  vi.restoreAllMocks();
});

describe("the declared figures in the header", () => {
  it("shows the declared size, children, date and source beside — never against — the registered members", () => {
    renderHeader(family());

    const declared = document.querySelector('[data-fact="عدد أفراد الأسرة (المعلن)"]');
    expect(declared).toHaveTextContent(n(7));
    expect(declared).toHaveTextContent(`الأبناء الذكور ${n(3)} · البنات ${n(2)}`);
    expect(declared).toHaveTextContent("بتاريخ 2026-09-01 · نموذج ورقي");
    expect(document.querySelector('[data-fact="المسجلون تفصيليًا"]')).toHaveTextContent(n(2));
    // Never a computed difference.
    expect(document.body).not.toHaveTextContent(/مفقود|غير مسجلين|الفرق/);
  });

  it("says when nothing is declared, and when the date is unknown", () => {
    const { unmount } = renderHeader(family(NONE));
    expect(document.querySelector('[data-fact="عدد أفراد الأسرة (المعلن)"]')).toHaveTextContent("غير معلن");
    unmount();

    renderHeader(family({ declared_at: null, declaration_source: "VERIFIED_SOURCE" }));
    expect(document.querySelector("[data-declaration-context]")).toHaveTextContent("تاريخ الإقرار غير معروف · مصدر موثّق");
  });
});

describe("who sees the action", () => {
  it("is offered to a holder of family.update", () => {
    renderHeader(family());
    expect(screen.getByRole("button", { name: "تسجيل إقرار أسرة" })).toBeInTheDocument();
  });

  it("is hidden without family.update", () => {
    renderHeader(family(), ["family.view"]);
    expect(screen.queryByRole("button", { name: "تسجيل إقرار أسرة" })).not.toBeInTheDocument();
  });
});

describe("the declaration dialog", () => {
  it("shows the current declaration, offers only Staff sources and no notes", async () => {
    renderHeader(family());
    const dialog = await openDialog();

    const current = dialog.querySelector("[data-current-declaration]");
    expect(current).toHaveTextContent(n(7));
    expect(current).toHaveTextContent("2026-09-01");
    expect(current).toHaveTextContent("نموذج ورقي");
    expect(within(dialog).getAllByRole("radio").map((r) => r.closest("label")?.textContent)).toEqual([
      "نموذج ورقي",
      "إدخال يدوي",
      "مصدر موثّق",
    ]);
    expect(within(dialog).queryByText("استيراد بيانات")).not.toBeInTheDocument();
    expect(within(dialog).queryByLabelText(/ملاحظات/)).not.toBeInTheDocument();
  });

  it("requires at least one declared value and a source before sending anything", async () => {
    const post = vi.spyOn(apiClient, "post");
    renderHeader(family());
    const dialog = await openDialog();

    await userEvent.click(within(dialog).getByRole("button", { name: "تسجيل الإقرار" }));

    expect(await within(dialog).findByText("أدخل قيمة معلنة واحدة على الأقل.")).toBeInTheDocument();
    expect(within(dialog).getByText("اختر مصدر الإقرار")).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it("sends 0 as a value, empty as null, and the current declaration as the expectation", async () => {
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: family() });
    const { invalidate } = renderHeader(family());
    const dialog = await openDialog();

    await userEvent.type(input(dialog, "عدد أفراد الأسرة"), "0");
    await userEvent.type(input(dialog, "البنات"), "4");
    await userEvent.type(input(dialog, "تاريخ الإقرار"), "2026-10-01");
    await userEvent.click(within(dialog).getByRole("radio", { name: "إدخال يدوي" }));
    await userEvent.click(within(dialog).getByRole("button", { name: "تسجيل الإقرار" }));

    await waitFor(() =>
      expect(post).toHaveBeenCalledWith(PATH, {
        declared_household_size: 0,
        declared_living_sons: null,
        declared_living_daughters: 4,
        declared_at: "2026-10-01",
        source: "MANUAL_ENTRY",
        expected_current_declaration_id: 41,
      })
    );
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["families"] });
  });

  it("expects no current declaration for a family without one, and sends an unknown date as null", async () => {
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: family() });
    renderHeader(family(NONE));
    const dialog = await openDialog();

    expect(dialog.querySelector("[data-current-declaration]")).toHaveTextContent("لا يوجد إقرار مسجّل لهذه الأسرة.");
    await userEvent.type(input(dialog, "عدد أفراد الأسرة"), "5");
    await userEvent.click(within(dialog).getByRole("radio", { name: "مصدر موثّق" }));
    await userEvent.click(within(dialog).getByRole("button", { name: "تسجيل الإقرار" }));

    await waitFor(() =>
      expect(post).toHaveBeenCalledWith(PATH, {
        declared_household_size: 5,
        declared_living_sons: null,
        declared_living_daughters: null,
        declared_at: null,
        source: "VERIFIED_SOURCE",
        expected_current_declaration_id: null,
      })
    );
  });

  /** Fills the minimum (size 9, paper form) and submits. */
  async function submitMinimal(dialog: HTMLElement) {
    await userEvent.type(input(dialog, "عدد أفراد الأسرة"), "9");
    await userEvent.click(within(dialog).getByRole("radio", { name: "نموذج ورقي" }));
    await userEvent.click(within(dialog).getByRole("button", { name: "تسجيل الإقرار" }));
  }

  const refreshed = () => family({ current_declaration_id: 42, declared_household_size: 6 });

  it("on HOUSEHOLD_DECLARATION_CHANGED overwrites nothing, refreshes the family and keeps the stale expectation", async () => {
    const post = vi
      .spyOn(apiClient, "post")
      .mockRejectedValue(new ApiError(409, { message: "x", code: "HOUSEHOLD_DECLARATION_CHANGED" }));
    const { invalidate, rerender, client } = renderHeader(family());
    const dialog = await openDialog();

    await submitMinimal(dialog);

    expect(
      await within(dialog).findByText(
        "تغيّر إقرار الأسرة منذ فتح هذه النافذة، ولم يُحفظ شيء. أغلق النافذة وراجع الإقرار المحدَّث قبل تسجيل إقرار جديد."
      )
    ).toBeInTheDocument();
    expect(screen.getByRole("dialog")).toBeInTheDocument();
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["families"] });

    // The refreshed family arrives while the dialog is open: a resubmission
    // still carries the expectation it was opened with.
    rerender(
      <QueryClientProvider client={client}>
        <AuthContext.Provider value={authValue(staff(["family.view", "family.update"]))}>
          <FamilyIdentityHeader family={refreshed()} snapshot={snapshot} />
        </AuthContext.Provider>
      </QueryClientProvider>
    );
    await userEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "تسجيل الإقرار" }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
    expect(post.mock.calls[1][1]).toMatchObject({ expected_current_declaration_id: 41 });
  });

  it("reopened after a change, reviews and expects the refreshed declaration", async () => {
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: refreshed() });
    renderHeader(refreshed());
    const dialog = await openDialog();

    expect(dialog.querySelector("[data-current-declaration]")).toHaveTextContent(n(6));
    await submitMinimal(dialog);

    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect(post.mock.calls[0][1]).toMatchObject({ expected_current_declaration_id: 42 });
  });
});
