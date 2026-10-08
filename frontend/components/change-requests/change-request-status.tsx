import { StatusBadge } from "@/components/shared/status-badge";
import type { ChangeRequestStatus } from "@/lib/types/api/change-request";
import { changeRequestStatusLabels, changeRequestStatusTones } from "@/lib/utils/change-request";

/** A Change Request status as a badge: text plus tone (APPLIED is the only success). */
export function ChangeRequestStatusTag({ status }: { status: ChangeRequestStatus }) {
  return (
    <StatusBadge tone={changeRequestStatusTones[status] ?? "neutral"}>
      <span data-change-request-status={status}>{changeRequestStatusLabels[status] ?? status}</span>
    </StatusBadge>
  );
}
