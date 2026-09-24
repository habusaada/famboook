"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { AlertCircle, HandHeart, Loader2, Lock, Plus } from "lucide-react";
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { AssistanceFormDialog } from "@/components/assistances/assistance-form-dialog";
import { AssistanceStatusBadge } from "@/components/assistances/assistance-badges";
import { useAssistances } from "@/lib/api/assistances";
import { ApiError } from "@/lib/api/client";
import { useAssistanceCategories } from "@/lib/api/reference";
import type { AssistanceFilters, AssistanceStatus, AssistanceType } from "@/lib/types/api/assistance";
import {
  ASSISTANCE_STATUSES,
  ASSISTANCE_TYPES,
  assistanceStatusLabels,
  assistanceTypeLabels,
  plannedPeriod,
} from "@/lib/utils/assistance";

const ALL = "ALL";

function Filter({
  label,
  value,
  onChange,
  options,
}: {
  label: string;
  value?: string;
  onChange: (value?: string) => void;
  options: { value: string; label: string }[];
}) {
  return (
    <Select value={value ?? ALL} onValueChange={(v) => onChange(v === ALL ? undefined : v)}>
      <SelectTrigger size="sm" aria-label={label} className="min-w-36">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value={ALL}>{label}: الكل</SelectItem>
        {options.map((o) => (
          <SelectItem key={o.value} value={o.value}>
            {o.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

/** Global Assistance programs/campaigns page (/assistances). */
export function AssistancesList() {
  const router = useRouter();
  const [filters, setFilters] = useState<AssistanceFilters>({});
  const categories = useAssistanceCategories().data?.data ?? [];
  const { data, isLoading, isError, error, hasNextPage, fetchNextPage, isFetchingNextPage } =
    useAssistances(filters);

  if (isError && error instanceof ApiError && error.status === 403) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-16 text-center">
        <Lock className="size-8 text-muted-foreground" />
        <p className="text-sm text-muted-foreground">لا تملك صلاحية عرض المساعدات.</p>
      </div>
    );
  }

  const assistances = data?.pages.flatMap((p) => p.data) ?? [];
  const canCreate = data?.pages[0]?.abilities.create ?? false;

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h2 className="text-xl font-semibold tracking-tight">المساعدات</h2>
        <p className="text-sm text-muted-foreground">
          برامج وحملات المساعدة: التعريف والاستهداف والترشيح. الترشيح لا يعني استلام المساعدة.
        </p>
      </div>

      <Card size="sm">
        <CardHeader>
          <CardTitle>برامج المساعدة</CardTitle>
          <CardDescription>{data ? `${data.pages[0].meta.total} برنامج` : " "}</CardDescription>
          {canCreate && (
            <CardAction>
              <AssistanceFormDialog
                onSaved={(response) => router.push(`/assistances/${response.data.id}`)}
                trigger={
                  <Button size="sm">
                    <Plus className="size-4" />
                    مساعدة جديدة
                  </Button>
                }
              />
            </CardAction>
          )}
        </CardHeader>
        <CardContent className="flex flex-col gap-3 p-0">
          <div className="flex flex-wrap gap-2 px-4">
            <Filter
              label="الحالة"
              value={filters.status}
              onChange={(v) => setFilters({ ...filters, status: v as AssistanceStatus | undefined })}
              options={ASSISTANCE_STATUSES.map((s) => ({ value: s, label: assistanceStatusLabels[s] }))}
            />
            <Filter
              label="التصنيف"
              value={filters.category}
              onChange={(v) => setFilters({ ...filters, category: v })}
              options={categories.map((c) => ({ value: c.code, label: c.name }))}
            />
            <Filter
              label="النوع"
              value={filters.type}
              onChange={(v) => setFilters({ ...filters, type: v as AssistanceType | undefined })}
              options={ASSISTANCE_TYPES.map((t) => ({ value: t, label: assistanceTypeLabels[t] }))}
            />
          </div>

          {isLoading ? (
            <div className="flex flex-col gap-2 px-4 pb-4">
              {Array.from({ length: 4 }).map((_, i) => (
                <Skeleton key={i} className="h-10" />
              ))}
            </div>
          ) : isError ? (
            <div className="px-4 pb-4">
              <Alert variant="destructive">
                <AlertCircle className="size-4" />
                <AlertTitle>تعذّر تحميل المساعدات</AlertTitle>
                <AlertDescription>حدث خطأ أثناء الاتصال بالخادم.</AlertDescription>
              </Alert>
            </div>
          ) : assistances.length === 0 ? (
            <div className="flex flex-col items-center justify-center gap-2 border-t p-12 text-center">
              <HandHeart className="size-8 text-muted-foreground" />
              <p className="text-sm text-muted-foreground">لا توجد مساعدات مطابقة.</p>
            </div>
          ) : (
            <div className="overflow-x-auto border-t">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>المساعدة</TableHead>
                    <TableHead>التصنيف</TableHead>
                    <TableHead>النوع</TableHead>
                    <TableHead>الجهة المقدمة</TableHead>
                    <TableHead>الحالة</TableHead>
                    <TableHead>المستهدف</TableHead>
                    <TableHead>المرشحون</TableHead>
                    <TableHead>الفترة المخططة</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {assistances.map((a) => (
                    <TableRow key={a.id} data-assistance-id={a.id}>
                      <TableCell className="font-medium">
                        <Link href={`/assistances/${a.id}`} className="hover:underline">
                          {a.title}
                        </Link>
                      </TableCell>
                      <TableCell>{a.category.name}</TableCell>
                      <TableCell>{assistanceTypeLabels[a.assistance_type]}</TableCell>
                      <TableCell>{a.provider_name}</TableCell>
                      <TableCell>
                        <AssistanceStatusBadge status={a.status} />
                      </TableCell>
                      <TableCell className="tabular-nums">{a.target_beneficiaries ?? "—"}</TableCell>
                      <TableCell className="tabular-nums">{a.nominee_count}</TableCell>
                      <TableCell className="text-muted-foreground" dir="ltr">
                        {plannedPeriod(a.start_date, a.end_date)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}

          {hasNextPage && (
            <div className="flex justify-center border-t p-3">
              <Button type="button" variant="outline" size="sm" disabled={isFetchingNextPage} onClick={() => fetchNextPage()}>
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
