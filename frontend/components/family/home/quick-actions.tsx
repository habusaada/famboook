"use client";

import Link from "next/link";
import { AlertCircle, ChevronLeft, FileText, RotateCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { familyRequestFormFor, useFamilyChangeRequestTypesQuery } from "@/lib/api/family-change-requests";
import { isAccessFailure } from "@/lib/api/family-household";
import { changeRequestTypeLabels } from "@/lib/utils/change-request";

// FB-FAMILY-DASHBOARD-01: the Family home's services, from the server only
// (GET /family/change-requests/types, as «طلب جديد» does). A service is a
// link only when submission is enabled, the type is listed and this client
// has its form; a listed type without a form is shown as unavailable and is
// never clickable. Nothing is invented.

const tile = "flex min-h-14 items-center gap-3 rounded-2xl border border-border bg-surface-1 px-3.5 py-3";

export function HomeServices() {
  const query = useFamilyChangeRequestTypesQuery();

  let body: React.ReactNode;
  if (query.data) {
    const { data: types, meta } = query.data;
    const available = meta.submission_enabled ? types.filter(({ type }) => familyRequestFormFor(type) !== null) : [];
    const unavailable = meta.submission_enabled ? types.filter(({ type }) => familyRequestFormFor(type) === null) : [];

    if (!meta.submission_enabled || types.length === 0) {
      body = (
        <p className="rounded-2xl border border-border bg-surface-1 px-4 py-3.5 text-sm text-muted-foreground" data-home-services="none">
          لا تتوفر خدمات للتقديم حاليًا. يمكنك متابعة طلبات أسرتك من «طلباتي».
        </p>
      );
    } else {
      body = (
        <div className="flex flex-col gap-3">
          {available.length > 0 && (
            <ul className="grid grid-cols-1 gap-2.5" aria-label="خدمات متاحة" data-home-services="available">
              {available.map(({ type }) => (
                <li key={type}>
                  <Link
                    href={familyRequestFormFor(type)!}
                    className={`${tile} transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring`}
                    data-home-service={type}
                  >
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700" aria-hidden>
                      <FileText className="size-4" />
                    </span>
                    <span className="min-w-0 flex-1 text-sm font-semibold text-foreground">{changeRequestTypeLabels[type] ?? "طلب تحديث"}</span>
                    <ChevronLeft className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                  </Link>
                </li>
              ))}
            </ul>
          )}
          {unavailable.length > 0 && (
            <div data-home-services="unavailable">
              <h3 className="mb-1.5 text-xs font-medium text-subtle-foreground">غير متاحة حاليًا</h3>
              <ul className="grid grid-cols-1 gap-2.5" aria-label="خدمات غير متاحة حاليًا">
                {unavailable.map(({ type }) => (
                  <li key={type}>
                    <div className={`${tile} opacity-70`} aria-disabled="true" data-home-service-unavailable={type}>
                      <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-subtle-foreground" aria-hidden>
                        <FileText className="size-4" />
                      </span>
                      <span className="min-w-0 flex-1 text-sm font-medium text-muted-foreground">{changeRequestTypeLabels[type] ?? "طلب تحديث"}</span>
                    </div>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      );
    }
  } else if (query.isError) {
    body = isAccessFailure(query.error) ? null : (
      <div className="flex items-center justify-between gap-3 rounded-2xl border border-border bg-surface-1 px-4 py-3" data-home-services="error">
        <p className="flex items-center gap-2 text-sm text-foreground" role="alert">
          <AlertCircle className="size-4 shrink-0 text-danger" aria-hidden />
          تعذّر تحميل الخدمات
        </p>
        <Button variant="outline" className="h-10 gap-2 px-3" onClick={() => query.refetch()} disabled={query.isFetching}>
          <RotateCw className={`size-4 ${query.isFetching ? "animate-spin" : ""}`} aria-hidden />
          إعادة المحاولة
        </Button>
      </div>
    );
  } else {
    body = (
      <div className="grid grid-cols-1 gap-2.5" data-home-services="loading" aria-hidden>
        <Skeleton className="h-14 rounded-2xl" />
        <Skeleton className="h-14 rounded-2xl" />
      </div>
    );
  }

  return (
    <section aria-labelledby="family-services-title" data-home-services-section>
      <h2 id="family-services-title" className="mb-2 text-sm font-semibold text-foreground">
        الخدمات
      </h2>
      {body}
    </section>
  );
}
