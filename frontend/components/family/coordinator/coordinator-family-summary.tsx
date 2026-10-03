"use client";

import Link from "next/link";
import { ChevronRight, Loader2 } from "lucide-react";
import { useCoordinatorFamilyQuery } from "@/lib/api/coordinator";

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 px-4 py-3.5">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="text-end text-sm font-medium text-foreground">{children}</dd>
    </div>
  );
}

const BACK = (
  <Link href="/family/coordinator" className="inline-flex min-h-10 items-center gap-1 rounded-md text-sm font-semibold text-brand-700 focus-visible:outline-2 focus-visible:outline-ring">
    <ChevronRight className="size-4" aria-hidden />
    العودة إلى قائمة الأسر
  </Link>
);

/**
 * A Family summary in Coordinator Space (docs/11 §8): the same six fields
 * as the list and nothing else — not the household's registry. A Family
 * outside the scope and one that does not exist look the same.
 */
export function CoordinatorFamilySummary({ code }: { code: string }) {
  const family = useCoordinatorFamilyQuery(code);

  if (family.isError) {
    return (
      <div className="flex flex-col gap-4">
        {BACK}
        <p className="rounded-2xl border border-border bg-surface-1 px-4 py-8 text-center text-sm text-muted-foreground" role="alert">
          تعذّر الاتصال بالخادم.
        </p>
      </div>
    );
  }
  if (family.data === undefined) {
    return (
      <div className="flex justify-center py-16">
        <Loader2 className="size-6 animate-spin text-brand-700" aria-label="جارٍ التحميل" />
      </div>
    );
  }
  if (family.data === null) {
    return (
      <div className="flex flex-col gap-4">
        {BACK}
        <p className="rounded-2xl border border-border bg-surface-1 px-4 py-8 text-center text-sm text-muted-foreground" role="alert" data-coordinator-family-missing>
          الأسرة غير متاحة.
        </p>
      </div>
    );
  }

  const data = family.data;

  return (
    <div className="flex flex-col gap-4">
      {BACK}
      <div>
        <p className="text-sm text-muted-foreground">ملخص الأسرة</p>
        <h1 className="mt-0.5 text-2xl leading-snug font-bold text-foreground">
          <bdi>{data.head_name ?? "—"}</bdi>
        </h1>
      </div>
      <dl className="divide-y divide-stroke-subtle rounded-2xl border border-border bg-surface-1" data-coordinator-summary>
        <Row label="رمز الأسرة">
          <span dir="ltr" className="font-mono">
            {data.family_code}
          </span>
        </Row>
        <Row label="رب الأسرة">
          <bdi>{data.head_name ?? "—"}</bdi>
        </Row>
        <Row label="العشيرة">
          <bdi>{data.clan_name ?? "—"}</bdi>
        </Row>
        <Row label="مجموعة الفروع">
          <bdi>{data.branch_group_name ?? "—"}</bdi>
        </Row>
        <Row label="الفرع">
          <bdi>{data.branch_name ?? "—"}</bdi>
        </Row>
        <Row label="عدد الأفراد">{data.active_member_count}</Row>
      </dl>
    </div>
  );
}
