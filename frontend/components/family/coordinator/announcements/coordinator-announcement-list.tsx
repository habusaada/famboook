"use client";

import Link from "next/link";
import { AlertCircle, ArrowRight, ChevronLeft, ChevronRight, Lock, Megaphone, Plus, RotateCw, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { AnnouncementLevelBadge, AnnouncementStatusBadge } from "@/components/announcements/announcement-parts";
import { NoCurrentScopeNotice } from "@/components/family/coordinator/announcements/coordinator-announcement-parts";
import { useFamilyUser } from "@/components/family/family-context";
import { ANNOUNCEMENT_STATUSES, type AnnouncementStatus, type AnnouncementSummary, useAnnouncementsQuery } from "@/lib/api/announcements";
import { ApiError } from "@/lib/api/client";
import { useRegistrySearch } from "@/lib/hooks/use-registry-search";
import { announcementErrorMessage, announcementStatusLabels, audienceTypeLabels } from "@/lib/utils/announcement";
import { formatTimestampDate } from "@/lib/utils/date";

const ALL = "ALL";
const BASE = "/family/coordinator/announcements";

/** The most relevant date of a row: published, else queued, else created. */
function milestone(a: AnnouncementSummary): string | null {
  if (a.published_at) return `نُشر ${formatTimestampDate(a.published_at)}`;
  if (a.queued_at) return `طُلب النشر ${formatTimestampDate(a.queued_at)}`;
  if (a.created_at) return `أُنشئ ${formatTimestampDate(a.created_at)}`;
  return null;
}

function Card({ announcement }: { announcement: AnnouncementSummary }) {
  const when = milestone(announcement);
  return (
    <li>
      <Link
        href={`${BASE}/${encodeURIComponent(announcement.id)}`}
        className="flex items-start gap-3 rounded-2xl border border-border bg-surface-1 p-4 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring"
        data-coordinator-announcement={announcement.id}
      >
        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700" aria-hidden>
          <Megaphone className="size-5" />
        </span>
        <span className="flex min-w-0 flex-1 flex-col gap-1.5">
          <span className="break-words font-semibold text-foreground">{announcement.title}</span>
          <span className="flex flex-wrap items-center gap-1.5">
            <AnnouncementStatusBadge status={announcement.status} />
            <AnnouncementLevelBadge level={announcement.level} />
          </span>
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
            <bdi dir="ltr" className="font-mono tracking-wide">
              {announcement.notice_code}
            </bdi>
            {announcement.audience_type && (
              <>
                <span aria-hidden>·</span>
                <span>{audienceTypeLabels[announcement.audience_type] ?? announcement.audience_type}</span>
              </>
            )}
            {when && (
              <>
                <span aria-hidden>·</span>
                <span>{when}</span>
              </>
            )}
            {announcement.recipient_count_at_publication !== null && (
              <>
                <span aria-hidden>·</span>
                <span data-announcement-recipients>
                  المستلمون عند النشر: <bdi className="tabular-nums">{announcement.recipient_count_at_publication.toLocaleString("ar")}</bdi>
                </span>
              </>
            )}
          </span>
        </span>
        <ChevronLeft className="mt-2.5 size-5 shrink-0 text-muted-foreground" aria-hidden />
      </Link>
    </li>
  );
}

function Panel({ children, field }: { children: React.ReactNode; field: string }) {
  return (
    <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-coordinator-announcements-state={field}>
      {children}
    </section>
  );
}

/**
 * «إعلاناتي» (FB-NOTIFY-01C.3d): the coordinator's OWN announcements — the
 * server never lists another sender's. Role-only: it stays readable after the
 * scope is revoked (the backend serves the history outside coordinator.space);
 * composing needs the current scope and is offered only then.
 */
export function CoordinatorAnnouncementList() {
  const user = useFamilyUser();
  const registry = useRegistrySearch();
  const rawStatus = registry.param("status");
  const status = ANNOUNCEMENT_STATUSES.includes(rawStatus as AnnouncementStatus) ? (rawStatus as AnnouncementStatus) : undefined;
  const query = useAnnouncementsQuery("coordinator", { status, page: registry.page });
  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;
  const back = user.coordinator_space ? { href: "/family/coordinator", label: "مساحة التنسيق" } : { href: "/family/account", label: "حسابي" };
  const forbidden = query.error instanceof ApiError && query.error.status === 403;

  return (
    <div className="flex flex-col gap-5" data-coordinator-announcements>
      <header>
        <Link
          href={back.href}
          className="-ms-2 inline-flex min-h-10 items-center gap-1 rounded-lg px-2 text-sm font-medium text-brand-700 hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
        >
          <ArrowRight className="size-4" aria-hidden />
          {back.label}
        </Link>
        <h1 className="mt-1 text-2xl leading-snug font-bold text-foreground">إعلاناتي</h1>
        <p className="mt-1 text-sm text-muted-foreground">إعلاناتك إلى أسر نطاق التنسيق: المسودات وطلبات النشر والإعلانات المنشورة.</p>
      </header>

      {!user.coordinator_space && <NoCurrentScopeNotice />}

      {user.coordinator_space && (
        <Button asChild className="h-11 w-full gap-2 rounded-xl sm:w-auto sm:self-start" data-coordinator-announcement-new>
          <Link href={`${BASE}/new`}>
            <Plus className="size-4" aria-hidden />
            إعلان جديد
          </Link>
        </Button>
      )}

      <div className="flex flex-col gap-2 sm:flex-row" role="group" aria-label="تصفية الإعلانات">
        <Select value={status ?? ALL} onValueChange={(value) => registry.setFilter("status", value === ALL ? "" : value)}>
          <SelectTrigger className="h-11! w-full rounded-xl bg-surface-1 sm:w-56" aria-label="الحالة" data-filter-status>
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
          <Button variant="ghost" className="h-11 rounded-xl text-muted-foreground" onClick={() => registry.reset()}>
            <X className="size-4" aria-hidden />
            إعادة الضبط
          </Button>
        )}
      </div>

      <div aria-busy={query.isPending} aria-live="polite">
        {query.data ? (
          rows.length === 0 ? (
            <Panel field="empty">
              <Megaphone className="size-6 text-muted-foreground" aria-hidden />
              <p className="text-sm font-medium text-foreground">{status ? "لا توجد إعلانات بهذه الحالة" : "لا توجد إعلانات بعد"}</p>
            </Panel>
          ) : (
            <ul className="flex flex-col gap-3" aria-label="إعلاناتي" data-coordinator-announcement-list>
              {rows.map((a) => (
                <Card key={a.id} announcement={a} />
              ))}
            </ul>
          )
        ) : query.isError ? (
          <Panel field={forbidden ? "forbidden" : "error"}>
            {forbidden ? <Lock className="size-6 text-muted-foreground" aria-hidden /> : <AlertCircle className="size-6 text-danger" aria-hidden />}
            <p className="text-sm font-medium text-foreground" role="alert">
              {forbidden ? "سجل الإعلانات غير متاح لهذا الحساب." : announcementErrorMessage(query.error)}
            </p>
            {!forbidden && (
              <Button variant="outline" className="h-10 gap-2 px-4" onClick={() => query.refetch()} disabled={query.isFetching}>
                <RotateCw className={`size-4 ${query.isFetching ? "animate-spin" : ""}`} aria-hidden />
                إعادة المحاولة
              </Button>
            )}
          </Panel>
        ) : (
          <div className="flex flex-col gap-3" data-coordinator-announcements-loading>
            <p role="status" className="sr-only">
              جارٍ تحميل الإعلانات
            </p>
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-24 w-full rounded-2xl" />
            ))}
          </div>
        )}
      </div>

      {meta && meta.last_page > 1 && (
        <nav aria-label="التنقل بين الصفحات" className="flex items-center justify-between gap-2" data-coordinator-announcements-pagination>
          <Button variant="outline" className="h-10 gap-1 rounded-xl" disabled={meta.current_page <= 1} onClick={() => registry.setPage(meta.current_page - 1)}>
            <ChevronRight className="size-4" aria-hidden />
            السابق
          </Button>
          <span className="text-[13px] text-muted-foreground" aria-current="page">
            صفحة <span className="tabular-nums">{meta.current_page.toLocaleString("ar")}</span> من{" "}
            <span className="tabular-nums">{meta.last_page.toLocaleString("ar")}</span>
          </span>
          <Button variant="outline" className="h-10 gap-1 rounded-xl" disabled={meta.current_page >= meta.last_page} onClick={() => registry.setPage(meta.current_page + 1)}>
            التالي
            <ChevronLeft className="size-4" aria-hidden />
          </Button>
        </nav>
      )}
    </div>
  );
}
