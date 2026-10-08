"use client";

import Link from "next/link";
import { AlertCircle, ChevronLeft, FileClock, FileText, RotateCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { StatusBadge } from "@/components/shared/status-badge";
import { FamilyRequestsHeader } from "@/components/family/requests/family-request-parts";
import { familyRequestFormFor, useFamilyChangeRequestTypesQuery } from "@/lib/api/family-change-requests";
import { isAccessFailure } from "@/lib/api/family-household";
import { changeRequestTypeLabels } from "@/lib/utils/change-request";

const BACK = { href: "/family/requests", label: "طلباتي" };

function Message({ title, text, field }: { title: string; text: string; field: string }) {
  return (
    <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-family-new-request={field}>
      <FileClock className="size-6 text-muted-foreground" aria-hidden />
      <h2 className="text-base font-semibold text-foreground">{title}</h2>
      <p className="text-sm text-muted-foreground">{text}</p>
      <Button asChild variant="outline" className="h-10 rounded-xl">
        <Link href="/family/requests">الانتقال إلى طلباتي</Link>
      </Button>
    </section>
  );
}

/**
 * «طلب جديد» (PWA-5f): discovery only. Shows what the server says may be
 * submitted now (GET …/change-requests/types) and starts a request only
 * when this client also has a Family form for that type — none before
 * PWA-6.1. No invented options, no free-form JSON, no form builder.
 */
export function FamilyNewRequest() {
  const query = useFamilyChangeRequestTypesQuery();

  let body: React.ReactNode;
  if (query.data) {
    const { data: types, meta } = query.data;
    if (!meta.submission_enabled) {
      body = (
        <Message
          field="disabled"
          title="طلبات التحديث الجديدة غير متاحة حاليًا"
          text="يمكنك متابعة طلبات أسرتك السابقة من صفحة «طلباتي». وستظهر أنواع التحديث هنا عند إتاحتها."
        />
      );
    } else if (types.length === 0) {
      body = <Message field="empty" title="لا توجد أنواع طلبات متاحة حاليًا" text="ستظهر أنواع التحديث هنا عند إتاحتها. يمكنك متابعة طلبات أسرتك من صفحة «طلباتي»." />;
    } else {
      body = (
        <ul className="flex flex-col gap-3" aria-label="أنواع الطلبات" data-family-request-types>
          {types.map(({ type }) => {
            const label = changeRequestTypeLabels[type] ?? "طلب تحديث";
            const form = familyRequestFormFor(type);
            return (
              <li key={type} data-family-request-type={type}>
                {form ? (
                  <Link
                    href={form}
                    className="flex items-center gap-3 rounded-2xl border border-border bg-surface-1 p-4 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring"
                  >
                    <FileText className="size-5 shrink-0 text-brand-700" aria-hidden />
                    <span className="flex-1 font-semibold text-foreground">{label}</span>
                    <ChevronLeft className="size-5 shrink-0 text-muted-foreground" aria-hidden />
                  </Link>
                ) : (
                  <div className="flex items-center gap-3 rounded-2xl border border-border bg-surface-1 p-4" aria-disabled="true">
                    <FileText className="size-5 shrink-0 text-muted-foreground" aria-hidden />
                    <span className="flex-1 font-semibold text-muted-foreground">{label}</span>
                    <StatusBadge tone="neutral">غير متاح للتقديم بعد</StatusBadge>
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      );
    }
  } else if (query.isError) {
    body = isAccessFailure(query.error) ? null : (
      <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-family-new-request="error">
        <AlertCircle className="size-6 text-danger" aria-hidden />
        <p className="text-sm font-medium text-foreground" role="alert">
          تعذّر تحميل أنواع الطلبات
        </p>
        <Button variant="outline" className="h-10 gap-2 px-4" onClick={() => query.refetch()} disabled={query.isFetching}>
          <RotateCw className={`size-4 ${query.isFetching ? "animate-spin" : ""}`} aria-hidden />
          إعادة المحاولة
        </Button>
      </section>
    );
  } else {
    body = (
      <div className="flex flex-col gap-3" data-family-new-request="loading">
        <p role="status" className="sr-only">
          جارٍ التحميل
        </p>
        <Skeleton className="h-16 w-full rounded-2xl" />
        <Skeleton className="h-16 w-full rounded-2xl" />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-5">
      <FamilyRequestsHeader back={BACK} title="طلب جديد" description="اطلب تحديث بيانات أسرتك في السجل. يراجع فريق السجل كل طلب قبل تطبيقه." />
      {body}
    </div>
  );
}
