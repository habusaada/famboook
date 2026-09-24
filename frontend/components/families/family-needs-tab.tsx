"use client";

import { useState } from "react";
import { AlertCircle, HeartHandshake, Loader2, Lock, Plus } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { NeedFilterBar } from "@/components/needs/need-filters";
import { NeedFormDialog } from "@/components/needs/need-form-dialog";
import { NeedRow } from "@/components/needs/need-row";
import { ApiError } from "@/lib/api/client";
import { useFamilyNeeds } from "@/lib/api/needs";
import type { FamilyNeedSummary, NeedFilters } from "@/lib/types/api/need";

// Every number is derived by the API on read — nothing here is stored.
const SUMMARY_CARDS: { key: keyof FamilyNeedSummary; label: string }[] = [
  { key: "open", label: "احتياجات مفتوحة" },
  { key: "urgent_open", label: "مفتوحة عاجلة" },
  { key: "fulfilled", label: "تمت تلبيتها" },
  { key: "closed", label: "مغلقة" },
];

export function FamilyNeedsTab({ familyCode }: { familyCode: string }) {
  const [filters, setFilters] = useState<NeedFilters>({});
  const { data, isLoading, isError, error, hasNextPage, fetchNextPage, isFetchingNextPage } =
    useFamilyNeeds(familyCode, filters);

  if (isLoading) {
    return (
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {SUMMARY_CARDS.map((card) => (
          <Skeleton key={card.key} className="h-20" />
        ))}
      </div>
    );
  }

  if (isError) {
    if (error instanceof ApiError && error.status === 403) {
      return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-16 text-center">
          <Lock className="size-8 text-muted-foreground" />
          <p className="text-sm text-muted-foreground">لا تملك صلاحية عرض احتياجات هذه الأسرة.</p>
        </div>
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
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {SUMMARY_CARDS.map((card) => (
          <Card key={card.key} size="sm" data-summary={card.key}>
            <CardHeader className="pb-1">
              <CardDescription>{card.label}</CardDescription>
              <CardTitle className="text-2xl font-semibold tabular-nums">{first.summary[card.key]}</CardTitle>
            </CardHeader>
          </Card>
        ))}
      </div>

      <Card size="sm">
        <CardHeader>
          <CardTitle>الاحتياجات</CardTitle>
          <CardDescription>احتياجات محددة للأسرة أو لأفرادها، المفتوحة والعاجلة أولًا</CardDescription>
          {first.abilities.create && (
            <CardAction>
              <NeedFormDialog
                familyCode={familyCode}
                trigger={
                  <Button size="sm">
                    <Plus className="size-4" />
                    احتياج جديد
                  </Button>
                }
              />
            </CardAction>
          )}
        </CardHeader>
        <CardContent className="flex flex-col gap-3 p-0">
          <div className="px-4">
            <NeedFilterBar filters={filters} onChange={setFilters} showTarget />
          </div>

          {needs.length === 0 ? (
            <div className="flex flex-col items-center justify-center gap-2 border-t p-12 text-center">
              <HeartHandshake className="size-8 text-muted-foreground" />
              <p className="text-sm text-muted-foreground">
                {filtered ? "لا توجد احتياجات مطابقة للتصفية." : "لا توجد احتياجات مسجّلة لهذه الأسرة بعد."}
              </p>
            </div>
          ) : (
            <>
              {open.length > 0 && (
                <section>
                  <h3 className="border-t bg-muted/40 px-4 py-2 text-xs font-medium text-muted-foreground">
                    الاحتياجات المفتوحة
                  </h3>
                  <ol className="divide-y border-t">
                    {open.map((need) => (
                      <NeedRow key={need.id} need={need} />
                    ))}
                  </ol>
                </section>
              )}
              {history.length > 0 && (
                <section>
                  <h3 className="border-t bg-muted/40 px-4 py-2 text-xs font-medium text-muted-foreground">
                    السجل السابق (تمت تلبيتها أو مغلقة)
                  </h3>
                  <ol className="divide-y border-t">
                    {history.map((need) => (
                      <NeedRow key={need.id} need={need} />
                    ))}
                  </ol>
                </section>
              )}
              {hasNextPage && (
                <div className="flex justify-center border-t p-3">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={isFetchingNextPage}
                    onClick={() => fetchNextPage()}
                  >
                    {isFetchingNextPage && <Loader2 className="size-4 animate-spin" />}
                    عرض المزيد
                  </Button>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
