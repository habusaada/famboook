"use client";

import Link from "next/link";
import { AlertCircle, ChevronLeft, FileClock, Home, RotateCw, UserPlus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { FamilyRequestsHeader } from "@/components/family/requests/family-request-parts";
import { coordinatorRequestErrorMessage, coordinatorRequestFormFor, useCoordinatorRequestTypesQuery } from "@/lib/api/coordinator-requests";
import type { ChangeRequestType } from "@/lib/types/api/change-request";
import { coordinatorRequestTypeLabel } from "@/lib/utils/coordinator-request";

const BACK = { href: "/family/coordinator/requests", label: "طلبات التنسيق" };

const HINTS: Partial<Record<ChangeRequestType, { text: string; icon: React.ReactNode }>> = {
  ADD_FAMILY_MEMBER: { text: "إضافة فرد غير مسجّل إلى أسرة ضمن نطاقك.", icon: <UserPlus className="size-5 shrink-0 text-brand-700" aria-hidden /> },
  REGISTER_FAMILY: { text: "تسجيل أسرة غير مسجّلة في عائلة أو فرع ضمن نطاقك.", icon: <Home className="size-5 shrink-0 text-brand-700" aria-hidden /> },
};

function Message({ title, text, field }: { title: string; text: string; field: string }) {
  return (
    <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-coordinator-new-request={field}>
      <FileClock className="size-6 text-muted-foreground" aria-hidden />
      <h2 className="text-base font-semibold text-foreground">{title}</h2>
      <p className="text-sm text-muted-foreground">{text}</p>
      <Button asChild variant="outline" className="h-10 rounded-xl">
        <Link href={BACK.href}>الانتقال إلى طلبات التنسيق</Link>
      </Button>
    </section>
  );
}

/**
 * «طلب جديد» in Coordinator Space (CO-02.6b): discovery only — the types the
 * server lists NOW (GET …/coordinator/requests/types: switch open for this
 * account, permission held, handler registered) for which this client has a
 * form. Nothing else is offered.
 */
export function CoordinatorNewRequest() {
  const query = useCoordinatorRequestTypesQuery();

  let body: React.ReactNode;
  if (query.data) {
    const { data, meta } = query.data;
    const offered = data.filter(({ type }) => coordinatorRequestFormFor(type) !== null);
    if (!meta.submission_enabled) {
      body = <Message field="disabled" title="تقديم طلبات التنسيق غير متاح حاليًا" text="يمكنك متابعة طلباتك السابقة من «طلبات التنسيق»." />;
    } else if (offered.length === 0) {
      body = <Message field="empty" title="لا توجد أنواع طلبات متاحة لك حاليًا" text="ستظهر أنواع الطلبات هنا عند إتاحتها." />;
    } else {
      body = (
        <ul className="flex flex-col gap-3" aria-label="أنواع الطلبات" data-coordinator-request-types>
          {offered.map(({ type }) => (
            <li key={type} data-coordinator-request-type={type}>
              <Link
                href={coordinatorRequestFormFor(type) as string}
                className="flex items-center gap-3 rounded-2xl border border-border bg-surface-1 p-4 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring"
              >
                {HINTS[type]?.icon}
                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                  <span className="font-semibold text-foreground">{coordinatorRequestTypeLabel(type)}</span>
                  {HINTS[type] && <span className="text-[13px] text-muted-foreground">{HINTS[type].text}</span>}
                </span>
                <ChevronLeft className="size-5 shrink-0 text-muted-foreground" aria-hidden />
              </Link>
            </li>
          ))}
        </ul>
      );
    }
  } else if (query.isError) {
    body = (
      <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-coordinator-new-request="error">
        <AlertCircle className="size-6 text-danger" aria-hidden />
        <p className="text-sm font-medium text-foreground" role="alert">
          {coordinatorRequestErrorMessage(query.error)}
        </p>
        <Button variant="outline" className="h-10 gap-2 px-4" onClick={() => query.refetch()} disabled={query.isFetching}>
          <RotateCw className={`size-4 ${query.isFetching ? "animate-spin" : ""}`} aria-hidden />
          إعادة المحاولة
        </Button>
      </section>
    );
  } else {
    body = (
      <div className="flex flex-col gap-3" data-coordinator-new-request="loading">
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
      <FamilyRequestsHeader back={BACK} title="طلب جديد" description="يراجع فريق السجل كل طلب قبل تطبيقه، ولا يتغير السجل قبل ذلك." />
      {body}
    </div>
  );
}
