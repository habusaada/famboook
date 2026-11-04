import { readFileSync } from "node:fs";
import { act, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyGate } from "@/components/family/family-gate";
import { FamilyMembers } from "@/components/family/members/family-members";
import { RegistryChildren } from "@/components/family/members/registry-children";
import { ApiError, apiClient } from "@/lib/api/client";
import {
  FAMILY_REGISTRY_CHILDREN_QUERY_KEY,
  REGISTRY_ADMISSIONS_PATH,
  REGISTRY_CHILDREN_PATH,
  type RegistryChild,
  type RegistryChildrenPage,
  registryChildrenEntryVisible,
} from "@/lib/api/family-registry-admission";
import { browserStorageDump, familyGet, renderWithClient } from "./helpers";

// FB-FAMILY-ADD-01 ADD-WP-08: «الأبناء والبنات في السجل المدني» on
// GET …/registry-children and POST …/registry-children/admissions.
// Synthetic data only: no reference, name or year here is real.

const router = { replace: vi.fn(), push: vi.fn() };
const location = { pathname: "/family/members/registry-children" };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => location.pathname }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

// The shipped module exports NO approved declaration; tests that need one set a synthetic one.
const declaration = vi.hoisted(() => ({ value: null as { version: string; text: string } | null }));
vi.mock("@/lib/utils/registry-admission-declaration", () => ({
  get APPROVED_REGISTRY_ADMISSION_DECLARATION() {
    return declaration.value;
  },
}));

const SYNTHETIC_DECLARATION = { version: "synthetic-declaration-v0", text: "إقرار تجريبي: هذا الاسم من أفراد أسرتي." };
const REF_A = "synthetic-ref-a.0000000000000000";
const REF_B = "synthetic-ref-b.1111111111111111";
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

function child(overrides: Partial<RegistryChild> = {}): RegistryChild {
  return { display_name: "سامر", birth_year: 2010, status: "ELIGIBLE", ref: REF_A, ...overrides };
}

function page(children: RegistryChild[], meta: Partial<RegistryChildrenPage["meta"]> = {}): RegistryChildrenPage {
  return { children, meta: { page: 1, per_page: 25, total: children.length, ...meta } };
}

type Discovery = RegistryChildrenPage | Error | ((path: string) => Promise<unknown>);

function renderPage(discovery: Discovery = page([child()])) {
  const base = familyGet();
  const get = vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path.startsWith(REGISTRY_CHILDREN_PATH)) {
      if (typeof discovery === "function") return discovery(path);
      if (discovery instanceof Error) throw discovery;
      return { data: discovery };
    }
    return base(path);
  });
  const post = vi.spyOn(apiClient, "post");
  const result = renderWithClient(
    <FamilyGate>
      <RegistryChildren />
    </FamilyGate>
  );

  return { get, post, ...result };
}

/** Any spy's recorded calls (the generic apiClient signatures do not fit vi.spyOn's default type). */
type Calls = { mock: { calls: unknown[][] } };

const discoveryCalls = (get: Calls) =>
  get.mock.calls.map(([path]) => String(path)).filter((path) => path.startsWith(REGISTRY_CHILDREN_PATH));
const list = () => screen.findByRole("list", { name: "الأبناء والبنات المقترحون" });
const admitButton = async () => within(await list()).getByRole("button", { name: "إضافة إلى الأسرة" });
const dialog = () => screen.findByRole("dialog", { name: "إضافة إلى أفراد الأسرة" });
const confirmButton = () => screen.getByRole("button", { name: /تأكيد الإضافة|إعادة المحاولة/ });

/** Opens the dialog for the first admissible child and accepts the declaration. */
async function openAndAccept(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await admitButton());
  await dialog();
  await user.click(screen.getByRole("checkbox", { name: SYNTHETIC_DECLARATION.text }));
}

const refusal = (status: number, code: string) => new ApiError(status, { message: "رسالة الخادم", code });
const outcome = (replayed: boolean) => ({ data: { member_ref: "f".repeat(64), replayed } });

beforeEach(() => {
  router.replace.mockReset();
  router.push.mockReset();
  location.pathname = "/family/members/registry-children";
  declaration.value = null;
  window.localStorage.clear();
  window.sessionStorage.clear();
  vi.spyOn(crypto, "randomUUID");
});

afterEach(() => {
  declaration.value = null;
});

describe("the entry point", () => {
  it("is hidden while no server contract says the service is open (OFF, unknown or unavailable)", async () => {
    expect(registryChildrenEntryVisible()).toBe(false);
    const get = vi.spyOn(apiClient, "get").mockImplementation(familyGet());
    renderWithClient(
      <FamilyGate>
        <FamilyMembers />
      </FamilyGate>
    );

    // The first render of the file loads the portal shell: allow it time.
    await screen.findByRole("list", { name: "أفراد الأسرة" }, { timeout: 5000 });
    expect(screen.queryByRole("link", { name: "عرض الأبناء والبنات المقترحين" })).not.toBeInTheDocument();
    expect(screen.queryByText("الأبناء والبنات في السجل المدني")).not.toBeInTheDocument();
    // Visibility is never decided by an (audited, counted) discovery read.
    expect(discoveryCalls(get)).toEqual([]);
  });

  it("direct navigation with the service closed reads once, says so, and offers nothing to submit", async () => {
    declaration.value = SYNTHETIC_DECLARATION;
    const { get, post } = renderPage(refusal(404, "ADMISSION_UNAVAILABLE"));

    expect(await screen.findByRole("alert")).toHaveTextContent("غير متاحة لأسرتك حاليًا");
    expect(discoveryCalls(get)).toHaveLength(1);
    expect(screen.queryByRole("button", { name: "إضافة إلى الأسرة" })).not.toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });
});

describe("discovery (GET …/registry-children)", () => {
  it("reads page 1 with no identifier, and shows only the approved fields in the server's order", async () => {
    const { get, container } = renderPage(
      page([child({ display_name: "سامر", birth_year: 2010 }), child({ display_name: "ليلى", birth_year: null, status: "ALREADY_MEMBER", ref: null })])
    );
    const rows = within(await list()).getAllByRole("listitem");

    expect(discoveryCalls(get)).toEqual([`${REGISTRY_CHILDREN_PATH}?page=1`]);
    expect(discoveryCalls(get).join(" ")).not.toMatch(/national|family_id|person_id|member/);
    expect(rows.map((row) => row.querySelector("span")?.textContent)).toEqual(["سامر", "ليلى"]);
    expect(within(rows[0]).getByText("2010")).toBeInTheDocument();
    expect(within(rows[1]).queryByText(/سنة الميلاد/)).not.toBeInTheDocument();
    // The opaque reference never reaches the DOM.
    expect(container.innerHTML).not.toContain(REF_A);
  });

  it("shows a missing name as unavailable, never as an empty row", async () => {
    renderPage(page([child({ display_name: null })]));

    expect(within(await list()).getByText("الاسم غير متاح")).toBeInTheDocument();
  });

  it("explains every documented status safely, and treats an undocumented one as NOT_ELIGIBLE", async () => {
    renderPage(
      page([
        child({ status: "ALREADY_MEMBER", ref: null }),
        child({ status: "REQUEST_OPEN", ref: null }),
        child({ status: "NOT_ELIGIBLE", ref: null }),
        child({ status: "SOMETHING_NEW" as RegistryChild["status"], ref: REF_B }),
      ])
    );
    const rows = within(await list()).getAllByRole("listitem");

    expect(rows[0]).toHaveTextContent("ضمن أفراد الأسرة");
    expect(rows[1]).toHaveTextContent("طلب قيد المراجعة");
    expect(rows[1]).toHaveTextContent("«طلباتي»");
    expect(rows[2]).toHaveTextContent("لا تتوفر إضافة مباشرة");
    expect(rows[2]).toHaveTextContent("«إضافة فرد إلى الأسرة»");
    expect(rows[3]).toHaveTextContent("لا تتوفر إضافة مباشرة");
    // Only ELIGIBLE rows ever carry the action.
    expect(screen.queryByRole("button", { name: "إضافة إلى الأسرة" })).not.toBeInTheDocument();
    // NOT_ELIGIBLE never says why.
    expect(document.body.textContent).not.toMatch(/متوفى|وفاة|أسرة أخرى|الجنس|محذوف/);
  });

  it("shows the empty state", async () => {
    renderPage(page([]));

    expect(await screen.findByText("لا يوجد أبناء أو بنات مقترحون لأسرتك في السجل المدني المنشور.")).toBeInTheDocument();
  });

  it("keeps the server's pagination: {page, per_page, total} drives the bar and ?page=N", async () => {
    const user = userEvent.setup();
    const { get } = renderPage(async (path) => {
      const n = Number(new URL(path, "http://x").searchParams.get("page"));
      return { data: page([child({ display_name: n === 1 ? "سامر" : "ليلى" })], { page: n, total: 26 }) };
    });
    await list();

    expect(screen.getByRole("navigation", { name: "التنقل بين الصفحات" })).toHaveTextContent(/صفحة\s*[1١]\s*من\s*[2٢]/);
    await user.click(screen.getByRole("button", { name: "الصفحة التالية" }));
    expect(await within(await list()).findByText("ليلى")).toBeInTheDocument();
    expect(discoveryCalls(get)).toEqual([`${REGISTRY_CHILDREN_PATH}?page=1`, `${REGISTRY_CHILDREN_PATH}?page=2`]);
  });

  it("has no pagination bar for a single page", async () => {
    renderPage(page([child()]));
    await list();

    expect(screen.queryByRole("navigation", { name: "التنقل بين الصفحات" })).not.toBeInTheDocument();
  });

  it("shows a loading state while reading", async () => {
    renderPage(() => new Promise(() => {}));

    expect(await screen.findByText("جارٍ تحميل الأبناء والبنات المقترحين")).toBeInTheDocument();
  });
});

describe("feature-disabled and failure states of discovery", () => {
  it.each([
    ["the closed flag or the missing permission (404)", refusal(404, "ADMISSION_UNAVAILABLE"), "غير متاحة لأسرتك حاليًا"],
    ["a too-broad result (409)", refusal(409, "RESULT_SET_TOO_BROAD"), "يرجى مراجعة المكتب"],
  ])("%s is final: no retry, a way back", async (_label, error, text) => {
    renderPage(error);

    expect(await screen.findByRole("alert")).toHaveTextContent(text);
    expect(screen.queryByRole("button", { name: "إعادة المحاولة" })).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "العودة إلى أفراد الأسرة" })).toHaveAttribute("href", "/family/members");
    expect(document.body.textContent).not.toContain("رسالة الخادم");
  });

  it.each([
    ["503", refusal(503, "REGISTRY_UNAVAILABLE"), "حاول لاحقًا"],
    ["429", refusal(429, "RATE_LIMITED"), "محاولات كثيرة"],
    ["a network failure", new TypeError("Failed to fetch"), "تحقق من الاتصال"],
  ])("%s is retryable, only when the user asks", async (_label, error, text) => {
    const user = userEvent.setup();
    let calls = 0;
    const { get } = renderPage(async () => {
      calls += 1;
      if (calls === 1) throw error;
      return { data: page([child()]) };
    });

    expect(await screen.findByRole("alert")).toHaveTextContent(text);
    expect(discoveryCalls(get)).toHaveLength(1);
    await user.click(screen.getByRole("button", { name: "إعادة المحاولة" }));
    await list();
    expect(discoveryCalls(get)).toHaveLength(2);
  });

  it.each([
    ["FAMILY_CONTEXT_UNAVAILABLE", { message: "x", code: "FAMILY_CONTEXT_UNAVAILABLE" }],
    ["a family-side boundary refusal", { message: "This action is unauthorized." }],
  ])("a 403 (%s) is the access flow, never an inline error", async (_label, body) => {
    renderPage(new ApiError(403, body));

    expect(await screen.findByRole("heading", { name: "الوصول غير متاح حاليًا" })).toBeInTheDocument();
    expect(document.querySelector("[data-registry-children-error]")).toBeNull();
  });

  it("a 401 returns to the Family login", async () => {
    renderPage(new ApiError(401, { message: "Unauthenticated." }));

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/login"));
  });
});

describe("the declaration requirement", () => {
  it("ships with NO approved declaration: an eligible child with a reference still cannot be submitted", async () => {
    const actual = await vi.importActual<typeof import("@/lib/utils/registry-admission-declaration")>("@/lib/utils/registry-admission-declaration");
    expect(actual.APPROVED_REGISTRY_ADMISSION_DECLARATION).toBeNull();

    const { post } = renderPage(page([child()]));
    const button = await admitButton();

    expect(button).toBeDisabled();
    expect(within(await list()).getByText("لا يمكن إتمام الإضافة من هنا حاليًا. حاول لاحقًا.")).toBeInTheDocument();
    await userEvent.setup().click(button);
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
  });

  it("does not hard-code a declaration version anywhere in the client", () => {
    for (const file of ["components/family/members/registry-children.tsx", "lib/api/family-registry-admission.ts", "lib/utils/registry-admission-declaration.ts"]) {
      expect(readFileSync(file, "utf8")).not.toMatch(/declaration_version\s*:\s*["'`]|version\s*:\s*["'`]\d/);
    }
  });

  it("an eligible child without a reference from the server is never admissible", async () => {
    declaration.value = SYNTHETIC_DECLARATION;
    renderPage(page([child({ ref: null }), child({ ref: "" })]));
    const buttons = within(await list()).getAllByRole("button", { name: "إضافة إلى الأسرة" });

    expect(buttons.every((button) => (button as HTMLButtonElement).disabled)).toBe(true);
  });

  it("the declaration is never preselected, and confirmation needs it", async () => {
    declaration.value = SYNTHETIC_DECLARATION;
    const user = userEvent.setup();
    const { post } = renderPage();
    await user.click(await admitButton());
    const box = within(await dialog()).getByRole("checkbox", { name: SYNTHETIC_DECLARATION.text });

    expect(box).not.toBeChecked();
    expect(confirmButton()).toBeDisabled();
    await user.click(box);
    expect(confirmButton()).toBeEnabled();
    await user.click(box);
    expect(confirmButton()).toBeDisabled();
    expect(post).not.toHaveBeenCalled();
  });

  it("re-opening the dialog asks for the declaration again", async () => {
    declaration.value = SYNTHETIC_DECLARATION;
    const user = userEvent.setup();
    renderPage();
    await openAndAccept(user);
    await user.click(screen.getByRole("button", { name: "رجوع" }));
    await user.click(await admitButton());

    expect(within(await dialog()).getByRole("checkbox")).not.toBeChecked();
  });
});

describe("admission (POST …/admissions)", () => {
  beforeEach(() => {
    declaration.value = SYNTHETIC_DECLARATION;
  });

  it("sends exactly the four fields, refreshes the members and the list, and confirms (201)", async () => {
    const user = userEvent.setup();
    let admittedYet = false;
    const { get, post } = renderPage(async () => ({ data: page([child(admittedYet ? { status: "ALREADY_MEMBER", ref: null } : {})]) }));
    post.mockImplementation(async () => {
      admittedYet = true;
      return outcome(false);
    });
    await openAndAccept(user);
    await user.click(confirmButton());

    expect(await screen.findByText("تمت إضافة سامر إلى أفراد الأسرة.")).toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(1);
    const [path, body] = post.mock.calls[0];
    expect(path).toBe(REGISTRY_ADMISSIONS_PATH);
    expect(Object.keys(body as object).sort()).toEqual(["client_reference", "declaration_accepted", "declaration_version", "ref"]);
    expect(body).toMatchObject({ ref: REF_A, declaration_version: SYNTHETIC_DECLARATION.version, declaration_accepted: true });
    expect((body as { client_reference: string }).client_reference).toMatch(UUID);
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "عرض أفراد الأسرة" })).toHaveAttribute("href", "/family/members");
    // The suggested children are re-read, and now show the child as a member.
    await waitFor(() => expect(discoveryCalls(get)).toHaveLength(2));
    expect(await within(await list()).findByText("ضمن أفراد الأسرة")).toBeInTheDocument();
  });

  it("invalidates the members, household and profile queries on success", async () => {
    const user = userEvent.setup();
    const { post, client } = renderPage();
    post.mockResolvedValue(outcome(false));
    const invalidate = vi.spyOn(client, "invalidateQueries");
    await openAndAccept(user);
    await user.click(confirmButton());
    await screen.findByText("تمت إضافة سامر إلى أفراد الأسرة.");

    const keys = invalidate.mock.calls.map(([filters]) => JSON.stringify(filters?.queryKey));
    expect(keys).toEqual(
      expect.arrayContaining(['["family","household","members"]', '["family","household"]', '["family","household","profile"]', JSON.stringify(FAMILY_REGISTRY_CHILDREN_QUERY_KEY)])
    );
  });

  it("a replay (200, replayed: true) is a success that changed nothing", async () => {
    const user = userEvent.setup();
    const { post } = renderPage();
    post.mockResolvedValue(outcome(true));
    await openAndAccept(user);
    await user.click(confirmButton());

    expect(await screen.findByText("سبق إتمام إضافة سامر إلى أفراد الأسرة، ولم يتغير شيء.")).toBeInTheDocument();
  });

  it("never sends twice: one request in flight, and an admitted reference cannot be sent again", async () => {
    const user = userEvent.setup();
    let resolve: (value: unknown) => void = () => {};
    // The re-read list still offers the same reference: the client must not send it again.
    const { post } = renderPage(page([child()]));
    post.mockImplementation(() => new Promise((r) => (resolve = r)));
    await openAndAccept(user);
    const confirm = confirmButton();
    await user.click(confirm);
    await user.click(confirm);
    await user.dblClick(confirm);

    expect(post).toHaveBeenCalledTimes(1);
    expect(screen.getByRole("button", { name: "جارٍ الإضافة…" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "رجوع" })).toBeDisabled();
    await act(async () => resolve(outcome(false)));
    await screen.findByText("تمت إضافة سامر إلى أفراد الأسرة.");

    expect(await admitButton()).toBeDisabled();
    await user.click(await admitButton());
    expect(post).toHaveBeenCalledTimes(1);
  });
});

describe("the idempotency key (client_reference)", () => {
  beforeEach(() => {
    declaration.value = SYNTHETIC_DECLARATION;
  });

  const keys = (post: Calls) => post.mock.calls.map(([, body]) => (body as { client_reference: string }).client_reference);

  it.each([
    ["503 REGISTRY_UNAVAILABLE", refusal(503, "REGISTRY_UNAVAILABLE"), "تعذّر إتمام الإضافة حاليًا"],
    ["429 RATE_LIMITED", refusal(429, "RATE_LIMITED"), "محاولات كثيرة"],
    ["a network failure", new TypeError("Failed to fetch"), "تعذّر الاتصال"],
  ])("is the same for every retry of one attempt after %s", async (_label, error, text) => {
    const user = userEvent.setup();
    const { post } = renderPage();
    post.mockRejectedValueOnce(error).mockRejectedValueOnce(error).mockResolvedValueOnce(outcome(false));
    await openAndAccept(user);
    await user.click(confirmButton());
    expect(await within(await dialog()).findByText(new RegExp(text))).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "إعادة المحاولة" }));
    await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
    // Leaving and coming back to the same child is still the same attempt.
    await user.click(screen.getByRole("button", { name: "رجوع" }));
    await openAndAccept(user);
    await user.click(confirmButton());
    await screen.findByText("تمت إضافة سامر إلى أفراد الأسرة.");

    expect(new Set(keys(post)).size).toBe(1);
    expect(keys(post)[0]).toMatch(UUID);
  });

  it("is never reused for a different child", async () => {
    const user = userEvent.setup();
    const { post } = renderPage(page([child({ display_name: "سامر", ref: REF_A }), child({ display_name: "ليلى", ref: REF_B })]));
    post.mockRejectedValue(refusal(503, "REGISTRY_UNAVAILABLE"));
    const [first, second] = within(await list()).getAllByRole("button", { name: "إضافة إلى الأسرة" });

    await user.click(first);
    await user.click(within(await dialog()).getByRole("checkbox"));
    await user.click(confirmButton());
    await within(await dialog()).findByRole("alert");
    await user.click(screen.getByRole("button", { name: "رجوع" }));
    await user.click(second);
    await user.click(within(await dialog()).getByRole("checkbox"));
    await user.click(confirmButton());
    await waitFor(() => expect(post).toHaveBeenCalledTimes(2));

    const bodies = post.mock.calls.map(([, body]) => body as { ref: string; client_reference: string });
    expect(bodies.map((body) => body.ref)).toEqual([REF_A, REF_B]);
    expect(bodies[0].client_reference).not.toBe(bodies[1].client_reference);
  });

  it("keeps an uncertain attempt's key after another child was tried in between", async () => {
    const user = userEvent.setup();
    const { post } = renderPage(page([child({ display_name: "سامر", ref: REF_A }), child({ display_name: "ليلى", ref: REF_B })]));
    post.mockRejectedValue(new TypeError("Failed to fetch"));
    const send = async (index: number) => {
      await user.click(within(await list()).getAllByRole("button", { name: "إضافة إلى الأسرة" })[index]);
      await user.click(within(await dialog()).getByRole("checkbox"));
      await user.click(confirmButton());
      await within(await dialog()).findByRole("alert");
      await user.click(screen.getByRole("button", { name: "رجوع" }));
    };
    await send(0);
    await send(1);
    await send(0);

    const bodies = post.mock.calls.map(([, body]) => body as { ref: string; client_reference: string });
    expect(bodies.map((body) => body.ref)).toEqual([REF_A, REF_B, REF_A]);
    expect(bodies[2].client_reference).toBe(bodies[0].client_reference);
    expect(bodies[1].client_reference).not.toBe(bodies[0].client_reference);
  });

  it("opening and closing the dialog never sends anything", async () => {
    const user = userEvent.setup();
    const { post } = renderPage();
    for (let i = 0; i < 3; i++) {
      await openAndAccept(user);
      await user.click(screen.getByRole("button", { name: "رجوع" }));
    }

    expect(post).not.toHaveBeenCalled();
  });

  it("starts a new attempt after 409 ADMISSION_IDEMPOTENCY_CONFLICT, and after a success", async () => {
    const user = userEvent.setup();
    const { post } = renderPage(page([child()]));
    post.mockRejectedValueOnce(refusal(409, "ADMISSION_IDEMPOTENCY_CONFLICT")).mockResolvedValueOnce(outcome(false));
    await openAndAccept(user);
    await user.click(confirmButton());
    expect(await within(await dialog()).findByText("تعذّر إتمام الإضافة. أعد المحاولة.")).toBeInTheDocument();
    await user.click(confirmButton());
    await screen.findByText("تمت إضافة سامر إلى أفراد الأسرة.");

    expect(keys(post)).toHaveLength(2);
    expect(keys(post)[0]).not.toBe(keys(post)[1]);
  });
});

describe("refused admissions", () => {
  beforeEach(() => {
    declaration.value = SYNTHETIC_DECLARATION;
  });

  it.each([
    ["422 NOT_ELIGIBLE", refusal(422, "NOT_ELIGIBLE"), "لا يمكن إتمام هذه الإضافة مباشرة", true],
    ["422 REFERENCE_INVALID", refusal(422, "REFERENCE_INVALID"), "انتهت صلاحية الاختيار", true],
    ["409 RELEASE_CHANGED", refusal(409, "RELEASE_CHANGED"), "تغيّر السجل المدني", true],
    ["404 ADMISSION_UNAVAILABLE", refusal(404, "ADMISSION_UNAVAILABLE"), "غير متاحة لأسرتك حاليًا", true],
    ["422 DECLARATION_REQUIRED", refusal(422, "DECLARATION_REQUIRED"), "تعذّر قبول الإقرار", false],
  ])("%s ends the attempt with a safe message", async (_label, error, text, rereads) => {
    const user = userEvent.setup();
    const { get, post } = renderPage();
    post.mockRejectedValue(error);
    await openAndAccept(user);
    await user.click(confirmButton());

    const notice = await screen.findByRole("alert");
    expect(notice).toHaveTextContent(text);
    expect(notice).not.toHaveTextContent("رسالة الخادم");
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    if (rereads) await waitFor(() => expect(discoveryCalls(get)).toHaveLength(2));
    else expect(discoveryCalls(get)).toHaveLength(1);
  });

  it("a 422 validation answer keeps the dialog open with a generic message, never the field values", async () => {
    const user = userEvent.setup();
    const { post } = renderPage();
    post.mockRejectedValue(new ApiError(422, { message: "البيانات المرسلة غير صالحة.", errors: { client_reference: ["x"] } }));
    await openAndAccept(user);
    await user.click(confirmButton());

    expect(await within(await dialog()).findByText("حدث خطأ غير متوقع. أعد المحاولة؛ لن تتكرر الإضافة.")).toBeInTheDocument();
  });

  it("a 403 is the access flow", async () => {
    const user = userEvent.setup();
    const { post } = renderPage();
    post.mockRejectedValue(new ApiError(403, { message: "x", code: "FAMILY_CONTEXT_UNAVAILABLE" }));
    await openAndAccept(user);
    await user.click(confirmButton());

    expect(await screen.findByRole("heading", { name: "الوصول غير متاح حاليًا" })).toBeInTheDocument();
  });

  it("a 401 returns to the Family login", async () => {
    const user = userEvent.setup();
    const { post } = renderPage();
    post.mockRejectedValue(new ApiError(401, { message: "Unauthenticated." }));
    await openAndAccept(user);
    await user.click(confirmButton());

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/family/login"));
  });
});

describe("RTL, responsive layout and accessibility", () => {
  it("isolates names and years for bidi, stacks on mobile, and labels everything", async () => {
    declaration.value = SYNTHETIC_DECLARATION;
    const user = userEvent.setup();
    renderPage();
    const row = within(await list()).getByRole("listitem");
    const button = await admitButton();

    expect(within(row).getByText("2010").tagName).toBe("BDI");
    expect(row.className).toMatch(/flex-col/);
    expect(row.className).toMatch(/sm:flex-row/);
    expect(button.className).toMatch(/w-full/);
    expect(button.className).toMatch(/sm:w-auto/);
    expect(button.className).toMatch(/h-11/);
    expect(button).toHaveAccessibleDescription(/بعد الإقرار/);
    expect(screen.getByRole("link", { name: "أفراد الأسرة" })).toHaveAttribute("href", "/family/members");

    await user.click(button);
    const modal = await dialog();
    expect(modal).toHaveAccessibleDescription(/سامر/);
    expect(within(modal).getByRole("checkbox", { name: SYNTHETIC_DECLARATION.text })).toBeRequired();
  });
});

describe("privacy", () => {
  it("logs nothing and stores nothing: no reference, key, name or year in the console or browser storage", async () => {
    declaration.value = SYNTHETIC_DECLARATION;
    const spies = (["log", "info", "warn", "error", "debug"] as const).map((method) => vi.spyOn(console, method).mockImplementation(() => {}));
    const user = userEvent.setup();
    const { post } = renderPage();
    post.mockRejectedValueOnce(refusal(503, "REGISTRY_UNAVAILABLE")).mockResolvedValueOnce(outcome(false));
    await openAndAccept(user);
    await user.click(confirmButton());
    await within(await dialog()).findByRole("alert");
    const key = (post.mock.calls[0][1] as { client_reference: string }).client_reference;
    // Rendered anywhere, the open dialog (a portal) included: never the reference or the key.
    expect(document.body.innerHTML).not.toContain(REF_A);
    expect(document.body.innerHTML).not.toContain(key);
    await user.click(confirmButton());
    await screen.findByText("تمت إضافة سامر إلى أفراد الأسرة.");
    expect(document.body.innerHTML).not.toContain(REF_A);

    const logged = spies.flatMap((spy) => spy.mock.calls).map((args) => args.map(String).join(" ")).join("\n");
    for (const secret of [REF_A, key, "سامر", "2010"]) {
      expect(logged).not.toContain(secret);
      expect(browserStorageDump()).not.toContain(secret);
    }
  });

  it("keeps no discovery result once the page is left", async () => {
    const { client, unmount } = renderPage();
    await list();
    unmount();

    await waitFor(() => expect(client.getQueryCache().findAll({ queryKey: FAMILY_REGISTRY_CHILDREN_QUERY_KEY })).toHaveLength(0));
  });
});
