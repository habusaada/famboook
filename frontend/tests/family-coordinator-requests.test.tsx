import { screen, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyRequestDetail } from "@/components/family/requests/family-request-detail";
import { FamilyRequests } from "@/components/family/requests/family-requests";
import { apiClient } from "@/lib/api/client";
import type { FamilyChangeRequest, FamilyChangeRequestSummary } from "@/lib/api/family-change-requests";
import type { PaginatedResponse } from "@/lib/types/api/family";
import { renderWithClient } from "./helpers";

// CO-02.7b (D8): a Coordinator's request about the household is shown in its
// history with the Coordinator's display name — read-only, no action, and
// nothing else about the Coordinator. Synthetic data only.

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => "/family/requests",
  useSearchParams: () => new URLSearchParams(),
}));

beforeEach(() => vi.restoreAllMocks());

const UUID = "5b1e2c3d-4f5a-4b6c-8d7e-9f0a1b2c3d4e";
const LIST_PATH = "/api/v1/family/change-requests";

function summary(overrides: Partial<FamilyChangeRequestSummary> = {}): FamilyChangeRequestSummary {
  return {
    id: UUID,
    request_code: "CRQ-000060",
    type: "ADD_FAMILY_MEMBER",
    type_available: true,
    status: "SUBMITTED",
    submission_channel: "COORDINATOR",
    coordinator: { name: "منسّق تجريبي" },
    submitted_at: "2026-10-14T09:00:00+03:00",
    approved_at: null,
    rejected_at: null,
    applied_at: null,
    cancelled_at: null,
    available_actions: [],
    ...overrides,
  };
}

function detail(overrides: Partial<FamilyChangeRequest> = {}): FamilyChangeRequest {
  return {
    id: UUID,
    request_code: "CRQ-000060",
    type: "ADD_FAMILY_MEMBER",
    type_available: true,
    status: "RETURNED_FOR_CLARIFICATION",
    submission_channel: "COORDINATOR",
    coordinator: { name: "منسّق تجريبي" },
    submitted_at: "2026-10-14T09:00:00+03:00",
    reason: null,
    presentation: { rows: [{ label: "الاسم الكامل", current: null, proposed: "فرد تجريبي" }] },
    approved_at: null,
    applied_at: null,
    cancelled_at: null,
    rejection: null,
    timeline: [],
    available_actions: [],
    ...overrides,
  };
}

const page = (rows: FamilyChangeRequestSummary[]): PaginatedResponse<FamilyChangeRequestSummary> => ({
  data: rows,
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, from: 1, last_page: 1, path: LIST_PATH, per_page: 15, to: rows.length, total: rows.length },
});

describe("a Coordinator's request in the household history", () => {
  it("names the Coordinator on their request only", async () => {
    vi.spyOn(apiClient, "get").mockResolvedValue(
      page([summary(), summary({ id: "7c2d3e4f-5a6b-4c7d-8e9f-0a1b2c3d4e5f", request_code: "CRQ-000061", submission_channel: "FAMILY", coordinator: null, available_actions: ["cancel"] })]) as never
    );
    renderWithClient(<FamilyRequests />);

    const list = await screen.findByRole("list", { name: "طلبات الأسرة" });
    const [coordinatorCard, ownCard] = within(list).getAllByRole("listitem");
    expect(within(coordinatorCard).getByText("منسّق تجريبي")).toBeInTheDocument();
    expect(within(coordinatorCard).getByText(/قدّمه المنسّق/)).toBeInTheDocument();
    expect(within(ownCard).queryByText(/قدّمه المنسّق/)).not.toBeInTheDocument();
  });

  it("shows the Coordinator on the detail, offers no action even while returned", async () => {
    vi.spyOn(apiClient, "get").mockResolvedValue({ data: detail() } as never);
    renderWithClient(<FamilyRequestDetail id={UUID} />);

    expect(await screen.findByText(/قدّم هذا الطلب المنسّق/)).toBeInTheDocument();
    expect(screen.getByText("منسّق تجريبي")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "إرسال الاستكمال" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "إلغاء الطلب" })).not.toBeInTheDocument();
  });

  it("shows nothing about a Coordinator on the household's own request", async () => {
    vi.spyOn(apiClient, "get").mockResolvedValue({ data: detail({ submission_channel: "FAMILY", coordinator: null, available_actions: ["resubmit", "cancel"] }) } as never);
    renderWithClient(<FamilyRequestDetail id={UUID} />);
    expect(await screen.findByRole("button", { name: "إرسال الاستكمال" })).toBeInTheDocument();
    expect(screen.queryByText(/المنسّق/)).not.toBeInTheDocument();
  });
});
