"use client";

import { useAuth } from "@/components/auth/auth-context";
import { FollowupDetailView } from "@/components/followups/followup-detail-view";
import { FollowupList } from "@/components/followups/followup-list";
import { DetailItem, PageHeader } from "@/components/shared/page-layout";

/**
 * «المتابعات» (FB-NOTIFY-01E.3-F3.3): every follow-up, for central Staff
 * (notification-followup.view — SUPER_ADMIN, ADMINISTRATOR).
 */
export function StaffFollowupList() {
  return (
    <FollowupList
      side="staff"
      forbiddenMessage="لا تملك صلاحية عرض المتابعات."
      header={<PageHeader title="المتابعات" description="متابعة الأسر التي لم تطّلع على الإعلانات أو لم تؤكد الاطلاع عليها في موعدها." />}
    />
  );
}

/**
 * One follow-up for Staff: the ownership KIND (never who), the actors' names
 * and the notes in the history. Contact and closure are offered with
 * notification-followup.manage; the Domain Actions decide (active SUPER_ADMIN /
 * ADMINISTRATOR).
 */
export function StaffFollowupDetail({ id }: { id: string }) {
  const { can } = useAuth();
  return (
    <FollowupDetailView
      side="staff"
      id={id}
      listHref="/followups"
      listLabel="المتابعات"
      manage={() => can("notification-followup.manage")}
      readOnlyNotice="لا تملك صلاحية إدارة المتابعات؛ يمكنك الاطلاع فقط."
      extraDetails={(followup) =>
        followup.ownership && (
          <DetailItem label="المسؤولية">
            <span data-followup-ownership={followup.ownership.kind}>
              {followup.ownership.kind === "CENTRAL" ? "مركزية (الإدارة)" : "المنسّق الأساسي للأسرة"}
            </span>
          </DetailItem>
        )
      }
    />
  );
}
