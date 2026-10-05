import { act, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { MyData } from "@/components/family/account/my-data";
import { REVEAL_ERROR, REVEAL_TOO_MANY } from "@/components/family/account/sensitive-value";
import { FamilyGate } from "@/components/family/family-gate";
import { ApiError, apiClient } from "@/lib/api/client";
import type { FamilySelf } from "@/lib/api/family-self";
import { browserStorageDump, familyGet, familySelf, renderWithClient } from "./helpers";

// PWA-3B.2: the own sensitive-value reveal on /family/account/me
// (POST /api/v1/family/self/reveal). The full value lives only in the field's
// component state while shown. Synthetic data only.

const router = { replace: vi.fn(), push: vi.fn() };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => "/family/account/me" }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const REVEAL = "/api/v1/family/self/reveal";
const FULL = { NATIONAL_ID: "123456789", MOBILE: "0591234567", ALTERNATE_MOBILE: "0567654321" } as const;
type Field = keyof typeof FULL;

function renderMyData(self: FamilySelf = familySelf()) {
  vi.spyOn(apiClient, "get").mockImplementation(familyGet({ self }));

  return renderWithClient(
    <FamilyGate>
      <MyData />
    </FamilyGate>
  );
}

/** apiClient.post answering each field with its synthetic full value. */
function revealServer() {
  return vi.spyOn(apiClient, "post").mockImplementation(async (path: string, body: unknown) => {
    expect(path).toBe(REVEAL);
    const requested = (body as { field: Field }).field;
    return { data: { field: requested, value: FULL[requested] } };
  });
}

const field = (name: string) => document.querySelector(`[data-field="${name}"] dd`) as HTMLElement;
const control = (name: string) => screen.getByRole("button", { name });

async function ready() {
  await screen.findByRole("heading", { name: "البيانات الأساسية" });
}

beforeEach(() => {
  vi.restoreAllMocks();
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("the reveal controls", () => {
  it("start with every applicable value masked, each with its own unpressed control", async () => {
    renderMyData();
    await ready();

    for (const [name, label, masked] of [
      ["national_id", "إظهار رقم الهوية", "*****6789"],
      ["mobile", "إظهار رقم الجوال", "05*****567"],
      ["alternate_mobile", "إظهار الجوال البديل", "05*****321"],
    ] as const) {
      expect(within(field(name)).getByRole("button")).toHaveAttribute("aria-label", label);
      expect(control(label)).toHaveAttribute("aria-pressed", "false");
      expect(field(name)).toHaveTextContent(masked);
    }
  });

  it("are not offered for a value that is not recorded", async () => {
    renderMyData(familySelf({ mobile_masked: null, alternate_mobile_masked: null }));
    await ready();

    expect(screen.queryByRole("button", { name: "إظهار رقم الجوال" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "إظهار الجوال البديل" })).not.toBeInTheDocument();
    expect(field("mobile")).toHaveTextContent("غير مسجّل");
    expect(control("إظهار رقم الهوية")).toBeInTheDocument();
  });
});

describe("revealing", () => {
  it("shows the National ID alone: only its code is sent, the others stay masked", async () => {
    const post = revealServer();
    renderMyData();
    await ready();

    await userEvent.click(control("إظهار رقم الهوية"));

    await waitFor(() => expect(field("national_id")).toHaveTextContent(FULL.NATIONAL_ID));
    expect(post).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith(REVEAL, { field: "NATIONAL_ID" });
    expect(control("إخفاء رقم الهوية")).toHaveAttribute("aria-pressed", "true");
    expect(field("mobile")).toHaveTextContent("05*****567");
    expect(field("alternate_mobile")).toHaveTextContent("05*****321");
    expect(document.body.innerHTML).not.toContain(FULL.MOBILE);
    expect(within(field("national_id")).getByText(FULL.NATIONAL_ID).closest("bdi")).toHaveAttribute("dir", "ltr");
  });

  it("discards the full value on hide and asks again on the next show", async () => {
    const post = revealServer();
    const { container } = renderMyData();
    await ready();

    await userEvent.click(control("إظهار رقم الهوية"));
    await waitFor(() => expect(field("national_id")).toHaveTextContent(FULL.NATIONAL_ID));
    await userEvent.click(control("إخفاء رقم الهوية"));

    expect(field("national_id")).toHaveTextContent("*****6789");
    expect(container.innerHTML).not.toContain(FULL.NATIONAL_ID);
    expect(control("إظهار رقم الهوية")).toHaveAttribute("aria-pressed", "false");

    await userEvent.click(control("إظهار رقم الهوية"));
    await waitFor(() => expect(field("national_id")).toHaveTextContent(FULL.NATIONAL_ID));
    expect(post).toHaveBeenCalledTimes(2);
  });

  it("shows the mobile and the alternate mobile independently", async () => {
    const post = revealServer();
    renderMyData();
    await ready();

    await userEvent.click(control("إظهار رقم الجوال"));
    await waitFor(() => expect(field("mobile")).toHaveTextContent(FULL.MOBILE));
    expect(field("national_id")).toHaveTextContent("*****6789");
    expect(field("alternate_mobile")).toHaveTextContent("05*****321");
    expect(control("إخفاء رقم الجوال")).toHaveAttribute("aria-pressed", "true");

    await userEvent.click(control("إظهار الجوال البديل"));
    await waitFor(() => expect(field("alternate_mobile")).toHaveTextContent(FULL.ALTERNATE_MOBILE));
    expect(field("mobile")).toHaveTextContent(FULL.MOBILE);
    expect(control("إخفاء الجوال البديل")).toHaveAttribute("aria-pressed", "true");
    expect(post.mock.calls.map(([, body]) => body)).toEqual([{ field: "MOBILE" }, { field: "ALTERNATE_MOBILE" }]);
  });

  it("keeps the busy state local to one field and ignores repeated clicks while busy", async () => {
    let answer: (value: unknown) => void = () => {};
    const post = vi.spyOn(apiClient, "post").mockImplementation(() => new Promise((resolve) => (answer = resolve)));
    renderMyData();
    await ready();

    await userEvent.click(control("إظهار رقم الهوية"));
    const busy = control("إظهار رقم الهوية");
    expect(busy).toBeDisabled();
    expect(busy).toHaveAttribute("aria-busy", "true");
    await userEvent.click(busy);
    expect(post).toHaveBeenCalledTimes(1);
    // The other fields stay usable.
    expect(control("إظهار رقم الجوال")).toBeEnabled();
    expect(control("إظهار الجوال البديل")).toBeEnabled();

    await act(async () => answer({ data: { field: "NATIONAL_ID", value: FULL.NATIONAL_ID } }));
    expect(field("national_id")).toHaveTextContent(FULL.NATIONAL_ID);
  });
});

describe("failures", () => {
  it("keep the mask, are reported on that field only, and can be retried", async () => {
    const post = vi.spyOn(apiClient, "post").mockRejectedValueOnce(new ApiError(500, { message: "x" }));
    renderMyData();
    await ready();

    await userEvent.click(control("إظهار رقم الهوية"));

    expect(await within(field("national_id")).findByRole("alert")).toHaveTextContent(REVEAL_ERROR);
    expect(field("national_id")).toHaveTextContent("*****6789");
    expect(document.querySelectorAll("[data-reveal-error]")).toHaveLength(1);
    // The page itself stays: one failed reveal is not a page error.
    expect(screen.getByRole("heading", { name: "البيانات الأساسية" })).toBeInTheDocument();

    post.mockResolvedValueOnce({ data: { field: "NATIONAL_ID", value: FULL.NATIONAL_ID } });
    await userEvent.click(control("إظهار رقم الهوية"));
    await waitFor(() => expect(field("national_id")).toHaveTextContent(FULL.NATIONAL_ID));
    expect(document.querySelector("[data-reveal-error]")).toBeNull();
  });

  it("explain a 429 and allow a later retry", async () => {
    const post = vi.spyOn(apiClient, "post").mockRejectedValueOnce(new ApiError(429, { message: "x", code: "TOO_MANY_REQUESTS" }));
    renderMyData();
    await ready();

    await userEvent.click(control("إظهار رقم الجوال"));

    expect(await within(field("mobile")).findByRole("alert")).toHaveTextContent(REVEAL_TOO_MANY);
    expect(field("mobile")).toHaveTextContent("05*****567");
    post.mockResolvedValueOnce({ data: { field: "MOBILE", value: FULL.MOBILE } });
    await userEvent.click(control("إظهار رقم الجوال"));
    await waitFor(() => expect(field("mobile")).toHaveTextContent(FULL.MOBILE));
  });
});

describe("disposal and privacy", () => {
  it("forgets every revealed value when the screen is left, and drops a late answer", async () => {
    revealServer();
    const first = renderMyData();
    await ready();
    await userEvent.click(control("إظهار رقم الهوية"));
    await waitFor(() => expect(field("national_id")).toHaveTextContent(FULL.NATIONAL_ID));
    first.unmount();

    // Coming back starts masked again.
    let late: (value: unknown) => void = () => {};
    vi.spyOn(apiClient, "post").mockImplementation(() => new Promise((resolve) => (late = resolve)));
    const second = renderMyData();
    await ready();
    expect(field("national_id")).toHaveTextContent("*****6789");
    expect(second.container.innerHTML).not.toContain(FULL.NATIONAL_ID);

    // A request still in flight when the screen is left is never shown.
    await userEvent.click(control("إظهار الجوال البديل"));
    second.unmount();
    await act(async () => late({ data: { field: "ALTERNATE_MOBILE", value: FULL.ALTERNATE_MOBILE } }));
    expect(document.body.innerHTML).not.toContain(FULL.ALTERNATE_MOBILE);
  });

  it("never copies, stores, caches or puts a revealed value in an attribute", async () => {
    const writeText = vi.fn();
    Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });
    revealServer();
    const { client } = renderMyData();
    await ready();

    for (const label of ["إظهار رقم الهوية", "إظهار رقم الجوال", "إظهار الجوال البديل"]) {
      await userEvent.click(control(label));
    }
    await waitFor(() => expect(field("alternate_mobile")).toHaveTextContent(FULL.ALTERNATE_MOBILE));

    expect(writeText).not.toHaveBeenCalled();
    const cached = JSON.stringify([
      client.getQueryCache().getAll().map((q) => q.state.data),
      client.getMutationCache().getAll().map((m) => m.state.data),
    ]);
    for (const full of Object.values(FULL)) {
      expect(cached).not.toContain(full);
      expect(browserStorageDump()).not.toContain(full);
      expect(document.body.innerHTML).not.toMatch(new RegExp(`="[^"]*${full}`));
    }
    // The normal query keeps only the masked payload.
    expect(client.getQueryData(["family", "self"])).toMatchObject({ national_id_masked: "*****6789", mobile_masked: "05*****567" });
  });
});
