import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import { FamilyCardPanel } from "@/components/families/family-card-panel";
import { ApiError, apiClient } from "@/lib/api/client";
import type { CurrentUser } from "@/lib/api/auth";
import type { StaffFamilyCard, StaffFamilyCardState } from "@/lib/api/family-cards";
import { renderWithClient } from "./helpers";

// PWA-8.2: the Staff «بطاقة الأسرة الرقمية» panel on the Family overview —
// view, issue, revoke (with a reason), reissue, each by permission. Never a
// token or QR. Synthetic data only.

const PATH = "/api/v1/families/FAM-000123/card";
const ALL = ["family-card.view", "family-card.issue", "family-card.revoke", "family-card.reissue"];

function card(overrides: Partial<StaffFamilyCard> = {}): StaffFamilyCard {
  return {
    credential_number: "FC-7K4P-9XMQ-2R",
    status: "ACTIVE",
    issued_at: "2026-10-16T09:00:00+03:00",
    issued_by: null,
    revoked_at: null,
    revoked_by: null,
    revoke_reason: null,
    ...overrides,
  };
}

const ACTIVE_STATE: StaffFamilyCardState = {
  active: card(),
  history: [card(), card({ credential_number: "FC-0000-1111-22", status: "REVOKED", revoked_at: "2026-10-10T09:00:00+03:00", revoked_by: "مسؤول تجريبي", revoke_reason: "REISSUED" })],
};
const NO_CARD: StaffFamilyCardState = { active: null, history: [] };

function staff(permissions: string[]): CurrentUser {
  return { name: "موظف تجريبي", email: "staff@example.test", role: "ADMINISTRATOR", role_label: "مسؤول", permissions };
}

function renderPanel(initial: StaffFamilyCardState, permissions = ALL) {
  const server = { state: initial };
  const get = vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path === PATH) return { data: server.state } as never;
    throw new Error(`Unexpected GET ${path}`);
  });
  const post = vi.spyOn(apiClient, "post");
  const result = renderWithClient(
    <AuthContext.Provider value={authValue(staff(permissions))}>
      <FamilyCardPanel familyCode="FAM-000123" />
    </AuthContext.Provider>
  );

  return { server, get, post, ...result };
}

const panel = () => document.querySelector("[data-family-card-panel]") as HTMLElement;
const button = (name: string) => screen.queryByRole("button", { name });

beforeEach(() => {
  vi.restoreAllMocks();
});

describe("viewing", () => {
  it("is not rendered, and never requested, without family-card.view", async () => {
    const { get } = renderPanel(ACTIVE_STATE, ["family-card.issue"]);

    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(panel()).toBeNull();
    expect(get).not.toHaveBeenCalled();
  });

  it("shows the active card number, status, issuer «النظام» and the history with reasons", async () => {
    renderPanel(ACTIVE_STATE, ["family-card.view"]);

    expect(await screen.findByText("FC-7K4P-9XMQ-2R")).toBeInTheDocument();
    expect(within(panel()).getByText("سارية")).toBeInTheDocument();
    expect(within(panel()).getByText("النظام")).toBeInTheDocument();
    const history = document.querySelector("[data-family-card-history]") as HTMLElement;
    expect(within(history).getByText("FC-0000-1111-22")).toBeInTheDocument();
    expect(history).toHaveTextContent("ملغاة — إعادة إصدار");
    expect(history).toHaveTextContent("مسؤول تجريبي");
  });

  it("says there is no active card, and never shows a QR or token", async () => {
    renderPanel(NO_CARD, ["family-card.view"]);

    expect(await screen.findByText("لا توجد بطاقة رقمية سارية لهذه الأسرة.")).toBeInTheDocument();
    expect(screen.queryByRole("img")).not.toBeInTheDocument();
    expect(panel().innerHTML).not.toMatch(/verify|svg|token/i);
  });
});

describe("actions by permission", () => {
  it("offers only viewing to a view-only role", async () => {
    renderPanel(ACTIVE_STATE, ["family-card.view"]);
    await screen.findByText("FC-7K4P-9XMQ-2R");

    expect(button("إعادة إصدار البطاقة")).not.toBeInTheDocument();
    expect(button("إلغاء البطاقة")).not.toBeInTheDocument();
    expect(button("إصدار بطاقة")).not.toBeInTheDocument();
  });

  it("offers issue only without an active card, and reissue / revoke only with one", async () => {
    renderPanel(NO_CARD);
    expect(await screen.findByRole("button", { name: "إصدار بطاقة" })).toBeInTheDocument();
    expect(button("إلغاء البطاقة")).not.toBeInTheDocument();
    expect(button("إعادة إصدار البطاقة")).not.toBeInTheDocument();
  });

  it("issues a card after confirmation and shows the server's state", async () => {
    const { server, post } = renderPanel(NO_CARD);
    post.mockImplementation(async () => {
      server.state = { active: card(), history: [card()] };
      return { data: server.state } as never;
    });

    await userEvent.click(await screen.findByRole("button", { name: "إصدار بطاقة" }));
    await userEvent.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "إصدار البطاقة" }));

    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(post).toHaveBeenCalledWith(PATH, {});
    expect(await screen.findByText("FC-7K4P-9XMQ-2R")).toBeInTheDocument();
  });

  it("revokes only with one of the two Staff reasons, never REISSUED", async () => {
    const { server, post } = renderPanel(ACTIVE_STATE);
    post.mockImplementation(async () => {
      server.state = { active: null, history: [card({ status: "REVOKED", revoke_reason: "COMPROMISED" })] };
      return { data: server.state } as never;
    });

    await userEvent.click(await screen.findByRole("button", { name: "إلغاء البطاقة" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("لن يعود رمز QR الحالي صالحًا للتحقق.")).toBeInTheDocument();
    const reasons = within(dialog).getAllByRole("radio").map((radio) => (radio as HTMLInputElement).value);
    expect(reasons).toEqual(["ADMINISTRATIVE", "COMPROMISED"]);
    expect(within(dialog).getByRole("button", { name: "تأكيد الإلغاء" })).toBeDisabled();

    await userEvent.click(within(dialog).getByRole("radio", { name: "اشتباه في إساءة الاستخدام" }));
    await userEvent.click(within(dialog).getByRole("button", { name: "تأكيد الإلغاء" }));

    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(post).toHaveBeenCalledWith(`${PATH}/revoke`, { reason: "COMPROMISED" });
    expect(await screen.findByText("لا توجد بطاقة رقمية سارية لهذه الأسرة.")).toBeInTheDocument();
  });

  it("reissues after a confirmation that explains the new number and QR", async () => {
    const { server, post } = renderPanel(ACTIVE_STATE);
    post.mockImplementation(async () => {
      server.state = { active: card({ credential_number: "FC-NEW1-NEW2-NE" }), history: [card({ credential_number: "FC-NEW1-NEW2-NE" })] };
      return { data: server.state } as never;
    });

    await userEvent.click(await screen.findByRole("button", { name: "إعادة إصدار البطاقة" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("سيُلغى رمز QR الحالي ورقم البطاقة، وتُصدر بطاقة جديدة برقم ورمز جديدين.")).toBeInTheDocument();
    await userEvent.click(within(dialog).getByRole("button", { name: "إعادة الإصدار" }));

    expect(post).toHaveBeenCalledWith(`${PATH}/reissue`, {});
    expect(await screen.findByText("FC-NEW1-NEW2-NE")).toBeInTheDocument();
  });

  it("shows the server's refusal and keeps the dialog open", async () => {
    const { post } = renderPanel(ACTIVE_STATE);
    post.mockRejectedValue(new ApiError(503, { message: "إصدار بطاقات الأسرة الرقمية غير متاح حاليًا.", code: "ISSUANCE_DISABLED" }));

    await userEvent.click(await screen.findByRole("button", { name: "إعادة إصدار البطاقة" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(within(dialog).getByRole("button", { name: "إعادة الإصدار" }));

    expect(await within(dialog).findByText("إصدار بطاقات الأسرة الرقمية غير متاح حاليًا.")).toBeInTheDocument();
    expect(screen.getByRole("dialog")).toBeInTheDocument();
  });
});
