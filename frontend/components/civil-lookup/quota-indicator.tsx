"use client";

import { Gauge, Infinity as InfinityIcon } from "lucide-react";
import { ProgressBar } from "@/components/shared/meter";
import type { CivilLookupQuota } from "@/lib/types/api/civil-lookup";
import { gazaTime } from "./labels";

const fmt = (n: number) => n.toLocaleString("ar");

/**
 * The user's daily lookup quota (FB-CIVIL-02A, ADR §L-6): limit, used,
 * remaining and the next reset (midnight, Asia/Gaza). Display only — the API
 * counts and enforces. FB-CIVIL-02E: compact (one line, a slim bar).
 */
export function QuotaIndicator({ quota }: { quota: CivilLookupQuota }) {
  if (quota.unlimited) {
    return (
      <div className="flex items-center gap-2 text-sm" data-quota="unlimited">
        <InfinityIcon className="size-4 shrink-0 text-brand-600" aria-hidden />
        <span>
          رصيد غير محدود · استعلامات اليوم: <bdi className="font-semibold tabular-nums">{fmt(quota.used)}</bdi>
        </span>
      </div>
    );
  }
  const limit = quota.daily_limit ?? 0;
  const remaining = quota.remaining ?? 0;
  const exhausted = remaining <= 0;

  return (
    <div className="flex flex-col gap-1.5" data-quota={exhausted ? "exhausted" : "limited"}>
      <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-sm">
        <span className="flex items-center gap-1.5">
          <Gauge className="size-4 shrink-0 text-muted-foreground" aria-hidden />
          المتبقي اليوم:{" "}
          <bdi className={exhausted ? "font-bold text-danger tabular-nums" : "font-bold tabular-nums"} data-quota-remaining>
            {fmt(remaining)}
          </bdi>{" "}
          من <bdi className="font-semibold tabular-nums" data-quota-limit>{fmt(limit)}</bdi>
        </span>
        <span className="text-[13px] text-muted-foreground">
          المستخدم: <bdi className="tabular-nums" data-quota-used>{fmt(quota.used)}</bdi>
        </span>
      </div>
      <ProgressBar value={Math.min(quota.used, limit)} total={limit} label="الاستعلامات المستخدمة اليوم" tone={exhausted ? "danger" : "brand"} size="sm" />
      <p className="text-xs text-muted-foreground">
        {exhausted ? "استنفدت رصيدك اليومي. " : ""}يتجدد في {gazaTime(quota.resets_at)} (بتوقيت غزة).
      </p>
    </div>
  );
}
