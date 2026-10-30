"use client";

import Link from "next/link";
import { AlertCircle, ChevronLeft, Lock, Megaphone, Plus, RotateCw, X } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { useAuth } from "@/components/auth/auth-context";
import { AnnouncementRowContent } from "@/components/announcements/announcement-row";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-layout";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { ANNOUNCEMENT_STATUSES, type AnnouncementStatus, type AnnouncementSummary, useAnnouncementsQuery } from "@/lib/api/announcements";
import { ApiError } from "@/lib/api/client";
import { useRegistrySearch } from "@/lib/hooks/use-registry-search";
import { announcementErrorMessage, announcementStatusLabels } from "@/lib/utils/announcement";

const ALL = "ALL";

function Row({ announcement }: { announcement: AnnouncementSummary }) {
  return (
    <li>
      <Link
        href={`/announcements/${encodeURIComponent(announcement.id)}`}
        className="flex items-center gap-3 px-4 py-3.5 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring sm:px-5"
        data-announcement-row={announcement.id}
      >
        <AnnouncementRowContent announcement={announcement} />
        <ChevronLeft className="size-5 shrink-0 text-muted-foreground" aria-hidden />
      </Link>
    </li>
  );
}

function SkeletonRows() {
  return (
    <div className="flex flex-col divide-y divide-stroke-subtle" aria-busy="true" data-announcements-loading>
      <p role="status" className="sr-only">
        جارٍ تحميل الإعلانات
      </p>
      {Array.from({ length: 5 }).map((_, i) => (
        <div key={i} className="flex flex-col gap-2 px-4 py-3.5 sm:px-5">
          <Skeleton className="h-4 w-40" />
          <Skeleton className="h-4 w-64" />
        </div>
      ))}
    </div>
  );
}

/**
 * «الإعلانات» (FB-NOTIFY-01C.3c): the signed-in Staff account's OWN
 * announcements (the server never lists another sender's), filtered by
 * status, one server page at a time — state in the URL.
 */
export function StaffAnnouncementList() {
  const { can } = useAuth();
  const registry = useRegistrySearch();
  const rawStatus = registry.param("status");
  const status = ANNOUNCEMENT_STATUSES.includes(rawStatus as AnnouncementStatus) ? (rawStatus as AnnouncementStatus) : undefined;
  const query = useAnnouncementsQuery("staff", { status, page: registry.page });
  const rows = query.data?.data ?? [];

  const header = (
    <PageHeader
      title="الإعلانات"
      description="إعلاناتك إلى الأسر: المسودات، وطلبات النشر، والإعلانات المنشورة."
      actions={
        can("announcement.create") && (
          <Button asChild data-announcement-new>
            <Link href="/announcements/new">
              <Plus className="size-4" aria-hidden />
              إعلان جديد
            </Link>
          </Button>
        )
      }
    />
  );

  if (query.isError && query.error instanceof ApiError && query.error.status === 403) {
    return (
      <div className="flex flex-col gap-4">
        {header}
        <AppCard padded={false}>
          <EmptyState icon={Lock} title="لا تملك صلاحية عرض الإعلانات." />
        </AppCard>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      {header}

      <AppCard padded={false} className="overflow-hidden">
        <div className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:p-4" role="search" aria-label="تصفية الإعلانات">
          <Select value={status ?? ALL} onValueChange={(value) => registry.setFilter("status", value === ALL ? "" : value)}>
            <SelectTrigger className="h-11! w-full bg-surface-2 sm:w-56" aria-label="الحالة" data-filter-status>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>كل الحالات</SelectItem>
              {ANNOUNCEMENT_STATUSES.map((s) => (
                <SelectItem key={s} value={s}>
                  {announcementStatusLabels[s]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {status && (
            <Button variant="ghost" className="h-11 text-muted-foreground" onClick={() => registry.reset()} data-announcements-reset>
              <X className="size-4" aria-hidden />
              إعادة الضبط
            </Button>
          )}
        </div>

        <div className="border-t border-stroke-subtle" aria-live="polite">
          {query.isPending ? (
            <SkeletonRows />
          ) : query.isError ? (
            <div className="p-4">
              <Alert variant="destructive" data-announcements-error>
                <AlertCircle className="size-4" />
                <AlertTitle>تعذّر تحميل الإعلانات</AlertTitle>
                <AlertDescription className="flex flex-col items-start gap-2">
                  {announcementErrorMessage(query.error)}
                  <Button size="sm" variant="outline" className="gap-1.5" onClick={() => query.refetch()} disabled={query.isFetching}>
                    <RotateCw className={`size-4 ${query.isFetching ? "animate-spin" : ""}`} aria-hidden />
                    إعادة المحاولة
                  </Button>
                </AlertDescription>
              </Alert>
            </div>
          ) : rows.length === 0 ? (
            <EmptyState
              icon={Megaphone}
              title={status ? "لا توجد إعلانات بهذه الحالة" : "لا توجد إعلانات بعد"}
              description={status ? "غيّر التصفية أو أعدها لعرض كل الإعلانات." : "أنشئ إعلانًا جديدًا ليظهر هنا."}
            />
          ) : (
            <ul className="flex flex-col divide-y divide-stroke-subtle" aria-label="إعلاناتي" data-announcement-list>
              {rows.map((a) => (
                <Row key={a.id} announcement={a} />
              ))}
            </ul>
          )}
        </div>

        {query.data && query.data.meta.last_page > 1 && (
          <div className="border-t border-stroke-subtle">
            <RegistryPagination meta={query.data.meta} onPage={registry.setPage} unit="إعلانًا" />
          </div>
        )}
      </AppCard>
    </div>
  );
}
