import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyActivityTab } from "@/components/families/family-activity-tab";
import { apiClient } from "@/lib/api/client";
import type { FamilyActivity } from "@/lib/types/api/activity";
import { renderWithClient } from "./helpers";

// PWA-5d acceptance: the Staff Family activity timeline renders the three
// Change Request activity types (and survives an unknown future code).
// Synthetic data only.

function activity(event_type: string, request_type: string | undefined, n: number): FamilyActivity {
  return {
    id: `00000000-0000-4000-8000-00000000000${n}`,
    event_type: event_type as FamilyActivity["event_type"],
    occurred_at: "2026-10-08T09:00:00+03:00",
    actor: { name: "مراجع تجريبي" },
    subject: { type: "change_request", person: null, title: null },
    metadata: request_type ? { request_type } : {},
  };
}

beforeEach(() => vi.restoreAllMocks());

describe("Change Request entries in the Family activity timeline", () => {
  it("renders their labels and request type without crashing, and a fallback for an unknown code", async () => {
    vi.spyOn(apiClient, "get").mockResolvedValue({
      data: [
        activity("CHANGE_REQUEST_SUBMITTED", "RESIDENCE_UPDATE", 1),
        activity("CHANGE_REQUEST_REJECTED", "RESIDENCE_UPDATE", 2),
        activity("CHANGE_REQUEST_APPLIED", "RESIDENCE_UPDATE", 3),
        activity("SOMETHING_FROM_A_NEWER_BACKEND", "NOT_A_TYPE", 4),
      ],
      links: {},
      meta: { current_page: 1, last_page: 1, from: 1, to: 4, total: 4, per_page: 20, path: "" },
    } as never);

    renderWithClient(<FamilyActivityTab familyCode="FAM-000123" />);

    expect(await screen.findByText("قدّمت الأسرة طلب تحديث بيانات")).toBeInTheDocument();
    expect(screen.getByText("رُفض طلب تحديث بيانات")).toBeInTheDocument();
    expect(screen.getByText("طُبّق طلب تحديث بيانات على سجل الأسرة")).toBeInTheDocument();
    expect(screen.getAllByText("تحديث بيانات السكن")).toHaveLength(3);
    expect(screen.getByText("نشاط على سجل الأسرة")).toBeInTheDocument();
    expect(document.body).not.toHaveTextContent("NOT_A_TYPE");
    expect(document.body).not.toHaveTextContent("SOMETHING_FROM_A_NEWER_BACKEND");
  });
});
