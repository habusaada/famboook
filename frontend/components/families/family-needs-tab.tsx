"use client";

import { useState } from "react";
import { AlertCircle, HeartHandshake, Loader2, Lock, Plus } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { NeedFilterBar } from "@/components/needs/need-filters";
import { NeedFormDialog } from "@/components/needs/need-form-dialog";
import { NeedRow } from "@/components/needs/need-row";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { SectionHeader } from "@/components/shared/page-layout";
import { ApiError } from "@/lib/api/client";
import { useFamilyNeeds } from "@/lib/api/needs";
import type { FamilyNeedSummary, NeedFilters } from "@/lib/types/api/need";
import { cn } from "@/lib/utils";

// Every number is derived by the API on read — nothing here is stored.
const SUMMARY: { key: keyof FamilyNeedSummary; label: string; tone?: string }[] = [
  { key: "open", label: "احتياجات مفتوحة" },
  { key: "urgent_open", label: "مفتوحة عاجلة", tone: "text-danger" },
  { key: "fulfilled", label: "تمت تلبيتها" },
  { key: "closed", label: "مغلقة" },
];

function GroupHeading({ children }: { children: React.ReactNode }) {
  return (
    <h3 className="border-t border-stroke-subtle bg-surface-2 px-4 py-2 text-xs font-semibold text-muted-foreground sm:px-5">
      {children}
    </h3>
  );
}

export function FamilyNeedsTab({ familyCode }: { familyCode: string }) {
  const [filters, setFilters] = useState<NeedFilters>({});
  const { data, isLoading, isError, error, hasNextPage, fetchNextPage, isFetchingNextPage } =
    useFamilyNeeds(familyCode, filters);

  if (isLoading) {
    return <Skeleton className="h-48 w-full rounded-widget" />;
  }

  if (isError) {
    if (error instanceof ApiError && error.status === 403) {
      return (
        <AppCard padded={false}>
          <EmptyState icon={Lock} title="لا تملك صلاحية عرض احتياجات هذه الأسرة." />
        </AppCard>
      );
    }
    return (
      <Alert variant="destructive">
        <AlertCircle className="size-4" />
        <AlertTitle>تعذّر تحميل الاحتياجات</AlertTitle>
        <AlertDescription>حدث خطأ أثناء الاتصال بالخادم.</AlertDescription>
      </Alert>
    );
  }

  const first = data!.pages[0];
  const needs = data!.pages.flatMap((page) => page.data);
  // The API returns OPEN first; split for a clear current/history view.
  const open = needs.filter((n) => n.status === "OPEN");
  const history = needs.filter((n) => n.status !== "OPEN");
  const filtered = Object.values(filters).some(Boolean);

  return (
    <AppCard padded={false} className="overflow-hidden">
      <SectionHeader
        className="p-4 sm:p-5"
        icon={HeartHandshake}
        tone="danger"
        title="الاحتياجات"
        description="احتياجات محددة للأسرة أو لأفرادها، المفتوحة والعاجلة أولًا"
        action={
          first.abilities.create ? (
            <NeedFormDialog
              familyCode={familyCode}
              trigger={
                <Button size="sm">
                  <Plus className="size-4" />
                  احتياج جديد
                </Button>
              }
            />
          ) : undefined
        }
      />

      <div className="grid grid-cols-2 border-t border-stroke-subtle sm:grid-cols-4 [&>*]:border-stroke-subtle max-sm:[&>*:nth-child(odd)]:border-e max-sm:[&>*:nth-child(n+3)]:border-t sm:[&>*:not(:last-child)]:border-e">
        {SUMMARY.map((item) => {
          const value = first.summary[item.key];
          return (
            <div key={item.key} className="flex flex-col gap-0.5 px-4 py-3 sm:px-5" data-summary={item.key}>
              <span className="text-xs text-muted-foreground">{item.label}</span>
              <span className={cn("text-xl font-bold tabular-nums", value > 0 ? (item.tone ?? "text-foreground") : "text-subtle-foreground")}>
                {value.toLocaleString("ar")}
              </span>
            </div>
          );
        })}
      </div>

      <div className="border-t border-stroke-subtle px-4 py-3 sm:px-5">
        <NeedFilterBar filters={filters} onChange={setFilters} showTarget />
      </div>

      {needs.length === 0 ? (
        <div className="border-t border-stroke-subtle">
          <EmptyState
            icon={HeartHandshake}
            title={filtered ? "لا توجد احتياجات مطابقة للتصفية" : "لا توجد احتياجات مسجّلة لهذه الأسرة بعد"}
          />
        </div>
      ) : (
        <>
          {open.length > 0 && (
            <section>
              <GroupHeading>الاحتياجات المفتوحة</GroupHeading>
              <ol className="divide-y divide-stroke-subtle border-t border-stroke-subtle">
                {open.map((need) => (
                  <NeedRow key={need.id} need={need} />
                ))}
              </ol>
            </section>
          )}
          {history.length > 0 && (
            <section className="opacity-75 transition-opacity hover:opacity-100 focus-within:opacity-100">
              <GroupHeading>السجل السابق (تمت تلبيتها أو مغلقة)</GroupHeading>
              <ol className="divide-y divide-stroke-subtle border-t border-stroke-subtle">
                {history.map((need) => (
                  <NeedRow key={need.id} need={need} />
                ))}
              </ol>
            </section>
          )}
          {hasNextPage && (
            <div className="flex justify-center border-t border-stroke-subtle p-3">
              <Button type="button" variant="outline" size="sm" disabled={isFetchingNextPage} onClick={() => fetchNextPage()}>
                {isFetchingNextPage && <Loader2 className="size-4 animate-spin" />}
                عرض المزيد
              </Button>
            </div>
          )}
        </>
      )}
    </AppCard>
  );
}
