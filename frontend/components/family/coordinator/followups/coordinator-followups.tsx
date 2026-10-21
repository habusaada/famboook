"use client";

import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { FollowupDetailView } from "@/components/followups/followup-detail-view";
import { FollowupList } from "@/components/followups/followup-list";

const BASE = "/family/coordinator/followups";

/**
 * «متابعات الأسر» (FB-NOTIFY-01E.3-F3.3): the follow-ups of the families in
 * the coordinator's CURRENT scope (behind CoordinatorGate) — all of them, or
 * «متابعاتي»: those whose current primary coordinator the reader is.
 */
export function CoordinatorFollowupList() {
  return (
    <FollowupList
      side="coordinator"
      forbiddenMessage="متابعات الأسر غير متاحة لهذا الحساب."
      header={
        <header>
          <Link
            href="/family/coordinator"
            className="-ms-2 inline-flex min-h-10 items-center gap-1 rounded-lg px-2 text-sm font-medium text-brand-700 hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
          >
            <ArrowRight className="size-4" aria-hidden />
            مساحة التنسيق
          </Link>
          <h1 className="mt-1 text-2xl leading-snug font-bold text-foreground">متابعات الأسر</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            أسر نطاقك التي لم تطّلع على إعلان أو لم تؤكد الاطلاع عليه في موعده. يدير كل متابعة المنسّق الأساسي للأسرة.
          </p>
        </header>
      }
    />
  );
}

/**
 * One follow-up in the current scope. Contact and closure only when the
 * server says so (`can_manage`: the CURRENT primary coordinator, never for the
 * own household); otherwise read-only. History actors are labels — another
 * coordinator is never named.
 */
export function CoordinatorFollowupDetail({ id }: { id: string }) {
  return (
    <FollowupDetailView
      side="coordinator"
      id={id}
      listHref={BASE}
      listLabel="متابعات الأسر"
      manage={(followup) => followup.can_manage === true}
      readOnlyNotice="يمكنك الاطلاع على هذه المتابعة فقط؛ يديرها المنسّق الأساسي للأسرة أو الإدارة المركزية."
    />
  );
}
