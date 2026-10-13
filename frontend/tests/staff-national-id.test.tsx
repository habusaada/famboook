import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import NewFamilyPage from "@/app/(staff)/families/new/page";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import { AddMemberDialog } from "@/components/families/add-member-dialog";
import { CorrectNationalIdDialog } from "@/components/people/correct-national-id-dialog";
import { DUPLICATE_NATIONAL_ID_MESSAGE } from "@/components/shared/national-id-duplicate";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CurrentUser } from "@/lib/api/auth";
import type { PersonDetail } from "@/lib/types/api/person";
import { NATIONAL_ID_MESSAGE } from "@/lib/utils/national-id";
import { renderWithClient } from "./helpers";

// CO-01D.5: the Staff forms that create or correct a Person apply the same
// nine-digit National ID rule as the server (RegistryNationalId), and the
// duplicate pre-check honours `exists` — a holder that is not described (a
// soft-deleted Person) is reported as taken with the generic message only.
// Synthetic values only.

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }), usePathname: () => "/families/new" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const CHECK_PATH = "/api/v1/people/national-id-check";
const MATCH = { person_code: "PER-000777", full_name: "شخص مسجّل", family: { family_code: "FAM-000777", is_household_head: true, relationship: null } };

function staff(permissions: string[]): CurrentUser {
  return { name: "موظف تجريبي", email: "staff@example.test", role: "DATA_ENTRY", role_label: "مدخل بيانات", permissions };
}

function mockGets() {
  return vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path === "/api/v1/reference/clans") return { data: [] } as never;
    if (path === "/api/v1/reference/relationship-types") return { data: [{ id: 3, code: "SON", name: "ابن" }] } as never;
    throw new Error(`Unexpected GET: ${path}`);
  });
}

const checkCalls = (post: { mock: { calls: unknown[][] } }) => post.mock.calls.filter(([path]) => path === CHECK_PATH);

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

// ------------------------------------------------------------ registration

describe("Staff family registration", () => {
  function renderPage() {
    mockGets();
    const post = vi.spyOn(apiClient, "post");
    const user = userEvent.setup();
    renderWithClient(
      <AuthContext.Provider value={authValue(staff(["family.create"]))}>
        <NewFamilyPage />
      </AuthContext.Provider>
    );
    return { post, user, input: () => screen.getByLabelText(/رقم الهوية الوطنية/) };
  }

  const identityState = () => document.querySelector("[data-identity-state]")?.getAttribute("data-identity-state");

  it("marks the National ID as required, never optional", () => {
    renderPage();
    const label = document.querySelector('label[for="headNationalId"]') as HTMLElement;
    expect(label).toHaveTextContent("(مطلوب)");
    expect(label).not.toHaveTextContent("(اختياري)");
    expect(screen.getByLabelText(/رقم الهوية الوطنية/)).toHaveAttribute("aria-required", "true");
  });

  it("reports an invalid value locally and never sends it to the pre-check", async () => {
    const { post, user, input } = renderPage();

    await user.type(input(), "40123456");
    await user.tab();

    expect(await screen.findByText(NATIONAL_ID_MESSAGE)).toBeInTheDocument();
    expect(identityState()).toBe("invalid");
    expect(checkCalls(post)).toHaveLength(0);
  });

  it("checks a valid value in canonical form and reports it clear", async () => {
    const { post, user, input } = renderPage();
    post.mockResolvedValue({ data: { exists: false, matches: [] } } as never);

    await user.type(input(), "٤٠١-٢٣٤ ٥٦٧");
    await user.tab();

    await waitFor(() => expect(identityState()).toBe("clear"));
    expect(checkCalls(post)).toEqual([[CHECK_PATH, { national_id: "401234567" }]]);
  });

  it("reports a value held by an undescribed record as taken — the generic message, nothing about it", async () => {
    const { post, user, input } = renderPage();
    post.mockResolvedValue({ data: { exists: true, matches: [] } } as never);

    await user.type(input(), "401234567");
    await user.tab();

    await waitFor(() => expect(identityState()).toBe("taken"));
    expect(screen.getByText(DUPLICATE_NATIONAL_ID_MESSAGE)).toBeInTheDocument();
    expect(screen.queryByText("لا يوجد شخص مسجّل بهذه الهوية.")).not.toBeInTheDocument();
    expect(document.querySelector("[data-duplicate-national-id]")).not.toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/PER-\d/);
  });

  it("lists the safe references of a described duplicate", async () => {
    const { post, user, input } = renderPage();
    post.mockResolvedValue({ data: { exists: true, matches: [MATCH] } } as never);

    await user.type(input(), "401234567");
    await user.tab();

    expect(await screen.findByText("PER-000777")).toBeInTheDocument();
    expect(identityState()).toBeUndefined();
  });

  it("treats a server-side 422 as an invalid value and other failures as unavailable", async () => {
    const { post, user, input } = renderPage();
    post.mockRejectedValueOnce(new ApiError(422, { errors: { national_id: [NATIONAL_ID_MESSAGE] } }));

    await user.type(input(), "401234567");
    await user.tab();
    await waitFor(() => expect(identityState()).toBe("invalid"));

    post.mockRejectedValueOnce(new ApiError(500, {}));
    await user.clear(input());
    await user.type(input(), "402345678");
    await user.tab();
    await waitFor(() => expect(identityState()).toBe("unavailable"));
  });

  it("refuses to submit without a valid National ID", async () => {
    const { post, user } = renderPage();

    await user.click(document.querySelector("[data-register-submit]") as HTMLElement);

    expect(await screen.findAllByText(NATIONAL_ID_MESSAGE)).not.toHaveLength(0);
    expect(post.mock.calls.filter(([path]) => path === "/api/v1/families")).toHaveLength(0);
  });
});

// ------------------------------------------------------------- add member

describe("Staff add-member dialog", () => {
  async function openDialog() {
    mockGets();
    const post = vi.spyOn(apiClient, "post");
    const user = userEvent.setup();
    renderWithClient(<AddMemberDialog familyCode="FAM-000123" />);
    await user.click(screen.getByRole("button", { name: /إضافة فرد/ }));
    const dialog = await screen.findByRole("dialog");
    return { post, user, dialog, input: () => within(dialog).getByLabelText("رقم الهوية الوطنية") };
  }

  it("no longer marks the National ID as optional", async () => {
    const { dialog, input } = await openDialog();
    expect((dialog.querySelector('label[for="member-nationalId"]') as HTMLElement).textContent).not.toContain("اختياري");
    expect(input()).toHaveAttribute("inputmode", "numeric");
  });

  it("checks only a valid value, in canonical form, and shows a taken value generically", async () => {
    const { post, user, dialog, input } = await openDialog();
    post.mockResolvedValue({ data: { exists: true, matches: [] } } as never);

    await user.type(input(), "12345");
    await user.tab();
    expect(checkCalls(post)).toHaveLength(0);

    await user.clear(input());
    await user.type(input(), "۴۰۱۲۳۴۵۶۷");
    await user.tab();

    await waitFor(() => expect(checkCalls(post)).toEqual([[CHECK_PATH, { national_id: "401234567" }]]));
    expect(await within(dialog).findByText(DUPLICATE_NATIONAL_ID_MESSAGE)).toBeInTheDocument();
    expect(dialog.querySelector("[data-duplicate-national-id]")).not.toBeInTheDocument();
  });

  it("refuses to add a member without a valid National ID", async () => {
    const { post, user, dialog } = await openDialog();

    await user.type(within(dialog).getByLabelText("الاسم الكامل"), "مولود جديد");
    await user.click(within(dialog).getByRole("button", { name: "حفظ" }));

    expect(await within(dialog).findByText(NATIONAL_ID_MESSAGE)).toBeInTheDocument();
    expect(post.mock.calls.filter(([path]) => String(path).endsWith("/members"))).toHaveLength(0);
  });
});

// ------------------------------------------------------------- correction

describe("Correct National ID dialog", () => {
  const person = { person_code: "PER-000123", full_name: "فرد تجريبي", national_id_masked: "*****4567" } as PersonDetail;

  async function openDialog() {
    const put = vi.spyOn(apiClient, "put");
    const user = userEvent.setup();
    renderWithClient(<CorrectNationalIdDialog person={person} />);
    await user.click(screen.getByRole("button", { name: /تصحيح رقم الهوية/ }));
    const dialog = await screen.findByRole("dialog");
    return {
      put,
      user,
      dialog,
      value: () => within(dialog).getByLabelText("رقم الهوية الصحيح"),
      confirm: () => within(dialog).getByLabelText("تأكيد رقم الهوية"),
      save: () => user.click(screen.getByRole("button", { name: "حفظ رقم الهوية" })),
    };
  }

  it("refuses an invalid replacement before any request", async () => {
    const { put, user, dialog, value, confirm, save } = await openDialog();

    await user.type(value(), "SYN-400333444");
    await user.type(confirm(), "SYN-400333444");
    await save();

    expect(await within(dialog).findByText(NATIONAL_ID_MESSAGE)).toBeInTheDocument();
    expect(put).not.toHaveBeenCalled();
  });

  it("accepts the same value in two formats and sends both canonically", async () => {
    const { put, user, value, confirm, save } = await openDialog();
    put.mockResolvedValue({ data: { ...person, national_id_masked: "*****4567" } } as never);

    await user.type(value(), "٤٠١٢٣٤٥٦٧");
    await user.type(confirm(), "401 234 567");
    await save();

    await waitFor(() =>
      expect(put).toHaveBeenCalledWith("/api/v1/people/PER-000123/national-id", { national_id: "401234567", national_id_confirmation: "401234567" })
    );
  });

  it("still refuses a different confirmation", async () => {
    const { put, user, dialog, value, confirm, save } = await openDialog();

    await user.type(value(), "401234567");
    await user.type(confirm(), "401234568");
    await save();

    expect(await within(dialog).findByText("تأكيد رقم الهوية غير مطابق")).toBeInTheDocument();
    expect(put).not.toHaveBeenCalled();
  });

  it("shows a refusal by an undescribed holder generically, with no record details", async () => {
    const { put, user, dialog, value, confirm, save } = await openDialog();
    put.mockRejectedValue(
      new ApiError(422, { message: DUPLICATE_NATIONAL_ID_MESSAGE, errors: { national_id: [DUPLICATE_NATIONAL_ID_MESSAGE] }, duplicate: { matches: [] } })
    );

    await user.type(value(), "401234567");
    await user.type(confirm(), "401234567");
    await save();

    expect((await within(dialog).findAllByText(DUPLICATE_NATIONAL_ID_MESSAGE)).length).toBeGreaterThan(0);
    expect(dialog.querySelector("[data-duplicate-national-id]")).not.toBeInTheDocument();
    expect(dialog.textContent).not.toContain("PER-000777");
  });
});
