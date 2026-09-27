"use client";

import Link from "next/link";
import {
  AlertCircle,
  ArrowRight,
  CheckCircle2,
  ChevronLeft,
  ClipboardList,
  Clock,
  HeartHandshake,
  Home,
  Lock,
  Package,
  Pencil,
  SearchX,
  UserRound,
  Users,
  XCircle,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { IconBox } from "@/components/shared/icon-box";
import { Code, DetailItem, DetailList, SectionHeader } from "@/components/shared/page-layout";
import { NeedFormDialog } from "@/components/needs/need-form-dialog";
import { CloseNeedDialog, FulfillNeedDialog } from "@/components/needs/need-resolve-dialogs";
import { NeedPriorityTag, NeedStatusTag, needAgeLabel } from "@/components/needs/need-case";
import { ApiError } from "@/lib/api/client";
import { useNeed } from "@/lib/api/needs";
import type { Need } from "@/lib/types/api/need";
import { formatDateTime } from "@/lib/utils/date";
import { FAMILY_TARGET_LABEL, needQuantityLabel } from "@/lib/utils/need";
import { cn } from "@/lib/utils";

/** Page context: Needs › this Family's needs. */
function Breadcrumb({ familyCode }: { familyCode?: string }) {
  return (
    <nav aria-label="مسار الصفحة" className="flex flex-wrap items-center gap-1 text-[13px] text-muted-foreground">
      <Link href="/needs" className="rounded-sm px-0.5 hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-ring">
        الاحتياجات
      </Link>
      {familyCode && (
        <>
          <ChevronLeft className="size-3.5" aria-hidden />
          <Link
            href={`/families/${encodeURIComponent(familyCode)}?tab=needs`}
            className="rounded-sm px-0.5 hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-ring"
          >
            احتياجات الأسرة <Code className="font-medium text-foreground">{familyCode}</Code>
          </Link>
        </>
      )}
    </nav>
  );
}

function LoadError({ error }: { error: unknown }) {
  if (error instanceof ApiError && (error.status === 403 || error.status === 404)) {
    return (
      <AppCard padded={false}>
        <EmptyState
          icon={error.status === 403 ? Lock : SearchX}
          title={error.status === 403 ? "لا تملك صلاحية عرض الاحتياجات." : "لم يتم العثور على هذا الاحتياج."}
        />
      </AppCard>
    );
  }

  return (
    <Alert variant="destructive">
      <AlertCircle className="size-4" />
      <AlertTitle>تعذّر تحميل الاحتياج</AlertTitle>
      <AlertDescription>حدث خطأ أثناء الاتصال بالخادم.</AlertDescription>
    </Alert>
  );
}

// ------------------------------------------------------------------ header

function Fact({ icon: Icon, label, children }: { icon: LucideIcon; label: string; children: React.ReactNode }) {
  return (
    <div className="flex min-w-0 items-start gap-2.5 px-4 py-3 sm:px-5" data-fact={label}>
      <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" strokeWidth={1.75} aria-hidden />
      <div className="flex min-w-0 flex-col gap-0.5">
        <span className="text-xs text-muted-foreground">{label}</span>
        <span className="text-sm leading-snug font-semibold text-foreground">{children}</span>
      </div>
    </div>
  );
}

/** Resolution record: a FULFILLED/CLOSED need is read-only history. */
function Resolution({ need }: { need: Need }) {
  if (need.status === "OPEN") return null;
  const fulfilled = need.status === "FULFILLED";
  const Icon = fulfilled ? CheckCircle2 : XCircle;
  return (
    <div className="flex flex-col gap-1 border-t border-stroke-subtle px-4 py-3 text-sm sm:px-5" data-resolution>
      <p className="flex flex-wrap items-center gap-x-1.5 gap-y-1">
        <Icon className={cn("size-4 shrink-0", fulfilled ? "text-success" : "text-muted-foreground")} aria-hidden />
        <span className="font-semibold text-foreground">{fulfilled ? "تمت تلبيته" : "مغلق"}</span>
        <span className="text-muted-foreground">
          — {fulfilled ? "سجّله" : "أغلقه"} {need.resolved_by?.name ?? "—"}
          {need.resolved_at && (
            <>
              {" "}في <time dateTime={need.resolved_at}>{formatDateTime(need.resolved_at)}</time>
            </>
          )}
          . سجل تاريخي للقراءة فقط.
        </span>
      </p>
      {!fulfilled && need.closure_reason && (
        <p className="ps-5.5 whitespace-pre-line text-foreground">
          <span className="text-muted-foreground">سبب الإغلاق: </span>
          {need.closure_reason}
        </p>
      )}
    </div>
  );
}

function NeedHeader({ need, abilities }: { need: Need; abilities: { update: boolean; fulfill: boolean; close: boolean } }) {
  const code = need.family.family_code;
  const quantity = needQuantityLabel(need);
  const hasActions = abilities.update || abilities.fulfill || abilities.close;

  return (
    <AppCard padded={false} className="overflow-hidden" aria-label="هوية الاحتياج">
      <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-5">
        <div className="flex min-w-0 items-start gap-4">
          <IconBox icon={HeartHandshake} size="lg" />
          <div className="flex min-w-0 flex-col gap-1.5">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-[13px] font-medium text-brand-800">{need.category.name}</span>
              {!need.category.is_active && <span className="text-xs text-muted-foreground">(تصنيف غير مفعّل)</span>}
              <NeedStatusTag status={need.status} />
              <NeedPriorityTag priority={need.priority} />
            </div>
            <h1 className="text-2xl leading-tight font-bold break-words text-foreground">{need.title}</h1>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-muted-foreground">
              <span className="flex items-center gap-1.5">
                <Home className="size-3.5 shrink-0" aria-hidden />
                <Link
                  href={`/families/${encodeURIComponent(code)}`}
                  className="rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                  data-family-link
                >
                  <Code className="text-brand-800">{code}</Code>
                </Link>
                {need.family.household_head_name && <span>— {need.family.household_head_name}</span>}
              </span>
            </div>
          </div>
        </div>

        {hasActions && (
          <div className="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end" data-need-actions>
            {abilities.update && (
              <NeedFormDialog
                familyCode={code}
                need={need}
                trigger={
                  <Button variant="outline" size="sm">
                    <Pencil className="size-4" />
                    تعديل
                  </Button>
                }
              />
            )}
            {abilities.close && <CloseNeedDialog need={need} />}
            {abilities.fulfill && <FulfillNeedDialog need={need} />}
          </div>
        )}
      </div>

      {/* Quick context: target, quantity, source, age — stored data only. */}
      <div
        className={cn(
          "grid grid-cols-2 border-t border-stroke-subtle bg-surface-2/60 md:grid-cols-4",
          "[&>*]:border-stroke-subtle [&>*:not(:last-child)]:border-e max-md:[&>*:nth-child(2n)]:border-e-0 max-md:[&>*:nth-child(n+3)]:border-t"
        )}
        aria-label="سياق الاحتياج"
      >
        <Fact icon={need.person ? UserRound : Users} label="المستفيد">
          {need.person ? need.person.full_name : <span className="font-normal text-muted-foreground">{FAMILY_TARGET_LABEL} كاملة</span>}
        </Fact>
        <Fact icon={Package} label="الكمية المطلوبة">
          {quantity ?? <span className="font-normal text-muted-foreground">غير محددة</span>}
        </Fact>
        <Fact icon={ClipboardList} label="المصدر">
          {need.source_assessment ? (
            <>
              تقييم <bdi dir="ltr" className="tabular-nums">{need.source_assessment.assessment_date}</bdi>
            </>
          ) : (
            <span className="font-normal text-muted-foreground">إدخال مباشر</span>
          )}
        </Fact>
        <Fact icon={Clock} label={need.status === "OPEN" ? "عمر الاحتياج" : "تاريخ الإنشاء"}>
          {need.status === "OPEN" ? needAgeLabel(need.created_at) : <bdi dir="ltr" className="tabular-nums">{need.created_at.slice(0, 10)}</bdi>}
        </Fact>
      </div>

      <Resolution need={need} />
    </AppCard>
  );
}

// ------------------------------------------------------------------ sections

function Description({ need }: { need: Need }) {
  return (
    <AppCard aria-labelledby="need-description-title">
      <SectionHeader title={<span id="need-description-title">الوصف والملاحظات</span>} />
      {need.description ? (
        <p className="mt-3 text-sm leading-relaxed whitespace-pre-line text-foreground">{need.description}</p>
      ) : (
        <p className="mt-3 text-sm text-muted-foreground">لا يوجد وصف.</p>
      )}
    </AppCard>
  );
}

/** Family, optional person, and source assessment — the need's case context. */
function CaseContext({ need }: { need: Need }) {
  const code = need.family.family_code;
  return (
    <AppCard aria-labelledby="need-context-title">
      <SectionHeader title={<span id="need-context-title">الأسرة والمستفيد</span>} />
      <div className="mt-3 flex items-center justify-between gap-3 rounded-lg bg-surface-2 p-3">
        <div className="flex min-w-0 items-center gap-3">
          <IconBox icon={Home} size="sm" />
          <div className="flex min-w-0 flex-col">
            <Code className="font-semibold text-brand-800">{code}</Code>
            <span className="truncate text-xs text-muted-foreground">{need.family.household_head_name ?? "رب الأسرة غير محدد"}</span>
          </div>
        </div>
        <Button asChild variant="outline" size="sm" className="shrink-0 gap-1">
          <Link href={`/families/${encodeURIComponent(code)}`} aria-label={`فتح ملف الأسرة ${code}`}>
            ملف الأسرة
            <ChevronLeft className="size-4" />
          </Link>
        </Button>
      </div>
      <DetailList className="mt-2 sm:grid-cols-1">
        <DetailItem label="المستفيد">
          {need.person ? (
            <Link
              href={`/people/${encodeURIComponent(need.person.person_code)}`}
              className="rounded-sm text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
              data-person-link
            >
              {need.person.full_name}
            </Link>
          ) : (
            <span className="font-normal text-muted-foreground">احتياج على مستوى الأسرة</span>
          )}
        </DetailItem>
        <DetailItem label="التقييم المصدر">
          {need.source_assessment ? (
            <Link
              href={`/families/${encodeURIComponent(code)}/assessments/${need.source_assessment.id}`}
              className="inline-flex items-center gap-1 rounded-sm text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
              data-source-assessment
            >
              <ClipboardList className="size-3.5" aria-hidden />
              تقييم بتاريخ <bdi dir="ltr" className="tabular-nums">{need.source_assessment.assessment_date}</bdi>
            </Link>
          ) : (
            <span className="font-normal text-muted-foreground">إدخال مباشر</span>
          )}
        </DetailItem>
      </DetailList>
    </AppCard>
  );
}

function Lifecycle({ need }: { need: Need }) {
  return (
    <AppCard aria-labelledby="need-lifecycle-title">
      <SectionHeader title={<span id="need-lifecycle-title">السجل</span>} />
      <DetailList className="mt-2 sm:grid-cols-1">
        <DetailItem label="أُدخل بواسطة">{need.created_by?.name ?? <span className="font-normal text-muted-foreground">—</span>}</DetailItem>
        <DetailItem label="تاريخ الإدخال">
          <time dateTime={need.created_at} className="font-normal">{formatDateTime(need.created_at)}</time>
        </DetailItem>
        <DetailItem label="آخر تحديث">
          <time dateTime={need.updated_at} className="font-normal text-muted-foreground">{formatDateTime(need.updated_at)}</time>
        </DetailItem>
        {need.status !== "OPEN" && (
          <DetailItem label={need.status === "FULFILLED" ? "تاريخ التلبية" : "تاريخ الإغلاق"}>
            {need.resolved_at ? <time dateTime={need.resolved_at} className="font-normal">{formatDateTime(need.resolved_at)}</time> : "—"}
          </DetailItem>
        )}
      </DetailList>
    </AppCard>
  );
}

// ------------------------------------------------------------------ page

/**
 * Need case record: identity (category, title, status, priority, family),
 * quick context, the resolution record once resolved, and the existing
 * edit / close / fulfill actions — offered only per the API abilities.
 */
export function NeedDetailView({ needId }: { needId: string }) {
  const { data, isLoading, isError, error } = useNeed(needId);

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true">
        <Skeleton className="h-5 w-40" />
        <Skeleton className="h-48 w-full rounded-widget" />
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
          <Skeleton className="h-40 w-full rounded-widget xl:col-span-7" />
          <Skeleton className="h-40 w-full rounded-widget xl:col-span-5" />
        </div>
      </div>
    );
  }

  if (isError) {
    return (
      <div className="flex flex-col gap-4">
        <Button variant="ghost" size="sm" className="-ms-2 w-fit gap-1.5 text-muted-foreground" asChild>
          <Link href="/needs">
            <ArrowRight className="size-4" />
            الاحتياجات
          </Link>
        </Button>
        <LoadError error={error} />
      </div>
    );
  }

  const { data: need, abilities } = data!;

  return (
    <div className="flex flex-col gap-4">
      <Breadcrumb familyCode={need.family.family_code} />
      <NeedHeader need={need} abilities={abilities} />

      <div className="grid grid-cols-1 items-start gap-4 xl:grid-cols-12">
        <div className="flex flex-col gap-4 xl:col-span-7">
          <Description need={need} />
          <Lifecycle need={need} />
        </div>
        <div className="xl:col-span-5">
          <CaseContext need={need} />
        </div>
      </div>
    </div>
  );
}
