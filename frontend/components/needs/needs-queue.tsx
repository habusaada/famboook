"use client";

import { useState } from "react";
import Link from "next/link";
import { AlertCircle, HeartHandshake, Loader2, Lock } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { NeedPriorityBadge, NeedStatusBadge } from "@/components/needs/need-badges";
import { NeedFilterBar } from "@/components/needs/need-filters";
import { ApiError } from "@/lib/api/client";
import { useNeedsQueue } from "@/lib/api/needs";
import type { NeedFilters } from "@/lib/types/api/need";
import { FAMILY_TARGET_LABEL } from "@/lib/utils/need";

/** Cross-family operational work queue; OPEN needs by default. */
export function NeedsQueue() {
  const [filters, setFilters] = useState<NeedFilters>({ status: "OPEN" });
  const { data, isLoading, isError, error, hasNextPage, fetchNextPage, isFetchingNextPage } =
    useNeedsQueue(filters);

  if (isError && error instanceof ApiError && error.status === 403) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-16 text-center">
        <Lock className="size-8 text-muted-foreground" />
        <p className="text-sm text-muted-foreground">لا تملك صلاحية عرض الاحتياجات.</p>
      </div>
    );
  }

  const needs = data?.pages.flatMap((page) => page.data) ?? [];
  const total = data?.pages[0]?.meta.total;

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h2 className="text-xl font-semibold tracking-tight">الاحتياجات</h2>
        <p className="text-sm text-muted-foreground">
          قائمة العمل لكل الأسر: المفتوحة والعاجلة أولًا. التلبية والإغلاق من صفحة الاحتياج.
        </p>
      </div>

      <Card size="sm">
        <CardHeader>
          <CardTitle>قائمة الاحتياجات</CardTitle>
          <CardDescription>{total !== undefined ? `${total} احتياج مطابق` : " "}</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3 p-0">
          <div className="px-4">
            <NeedFilterBar filters={filters} onChange={setFilters} showTarget />
          </div>

          {isLoading ? (
            <div className="flex flex-col gap-2 px-4 pb-4">
              {Array.from({ length: 5 }).map((_, i) => (
                <Skeleton key={i} className="h-10" />
              ))}
            </div>
          ) : isError ? (
            <div className="px-4 pb-4">
              <Alert variant="destructive">
                <AlertCircle className="size-4" />
                <AlertTitle>تعذّر تحميل الاحتياجات</AlertTitle>
                <AlertDescription>حدث خطأ أثناء الاتصال بالخادم.</AlertDescription>
              </Alert>
            </div>
          ) : needs.length === 0 ? (
            <div className="flex flex-col items-center justify-center gap-2 border-t p-12 text-center">
              <HeartHandshake className="size-8 text-muted-foreground" />
              <p className="text-sm text-muted-foreground">لا توجد احتياجات مطابقة.</p>
            </div>
          ) : (
            <div className="overflow-x-auto border-t">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>الاحتياج</TableHead>
                    <TableHead>الأسرة</TableHead>
                    <TableHead>المستفيد</TableHead>
                    <TableHead>التصنيف</TableHead>
                    <TableHead>الأولوية</TableHead>
                    <TableHead>الحالة</TableHead>
                    <TableHead>تاريخ الإنشاء</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {needs.map((need) => (
                    <TableRow key={need.id} data-need-id={need.id}>
                      <TableCell className="font-medium">
                        <Link href={`/needs/${need.id}`} className="hover:underline">
                          {need.title}
                        </Link>
                      </TableCell>
                      <TableCell>
                        <Link
                          href={`/families/${encodeURIComponent(need.family.family_code)}?tab=needs`}
                          className="flex flex-col hover:underline"
                        >
                          <span dir="ltr" className="text-end">
                            {need.family.family_code}
                          </span>
                          {need.family.household_head_name && (
                            <span className="text-xs text-muted-foreground">{need.family.household_head_name}</span>
                          )}
                        </Link>
                      </TableCell>
                      <TableCell>
                        {need.person ? (
                          <Link
                            href={`/people/${encodeURIComponent(need.person.person_code)}`}
                            className="hover:underline"
                          >
                            {need.person.full_name}
                          </Link>
                        ) : (
                          <span className="text-muted-foreground">{FAMILY_TARGET_LABEL}</span>
                        )}
                      </TableCell>
                      <TableCell>{need.category.name}</TableCell>
                      <TableCell>
                        <NeedPriorityBadge priority={need.priority} />
                      </TableCell>
                      <TableCell>
                        <NeedStatusBadge status={need.status} />
                      </TableCell>
                      <TableCell className="text-muted-foreground" dir="ltr">
                        {need.created_at.slice(0, 10)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
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
        </CardContent>
      </Card>
    </div>
  );
}
