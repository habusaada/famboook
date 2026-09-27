"use client";

import Link from "next/link";
import { AlertCircle, ChevronLeft, ClipboardCheck, ClipboardList, Loader2, Lock, Plus } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { SectionHeader } from "@/components/shared/page-layout";
import { cn } from "@/lib/utils";
import {
  AssessmentDomainIcon,
  AssessmentRatingBadge,
  AssessmentStatusBadge,
} from "@/components/assessments/assessment-badges";
import { useFamilyAssessments } from "@/lib/api/assessments";
import { ApiError } from "@/lib/api/client";
import type { AssessmentSummary } from "@/lib/types/api/assessment";
import { formatDateTime } from "@/lib/utils/date";

function AssessmentRow({ familyCode, assessment, latest }: { familyCode: string; assessment: AssessmentSummary; latest?: boolean }) {
  return (
    <li data-assessment-id={assessment.id}>
      <Link
        href={`/families/${encodeURIComponent(familyCode)}/assessments/${assessment.id}`}
        className={cn(
          "flex items-start gap-3 px-4 py-3.5 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring sm:px-5",
          latest && "bg-brand-50/50"
        )}
      >
        <div className="flex min-w-0 flex-1 flex-col gap-1.5">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-semibold">
              تقييم بتاريخ <bdi dir="ltr">{assessment.assessment_date}</bdi>
            </span>
            {latest && <span className="text-xs font-medium text-brand-700">الأحدث</span>}
            <AssessmentStatusBadge status={assessment.status} />
            <span className="text-xs text-muted-foreground">
              {assessment.assessed_domain_count} من المجالات مُقيَّمة
            </span>
          </div>

          {assessment.status === "COMPLETED" && assessment.ratings.length > 0 && (
            <div className="flex flex-wrap gap-1.5">
              {assessment.ratings.map(({ domain, rating }) => {
                return (
                  <span
                    key={domain.code}
                    className="inline-flex items-center gap-1 text-xs text-muted-foreground"
                    title={domain.name}
                  >
                    <AssessmentDomainIcon code={domain.code} className="size-3.5" />
                    <AssessmentRatingBadge rating={rating} />
                  </span>
                );
              })}
            </div>
          )}

          <p className="text-xs text-muted-foreground">
            أُدخل بواسطة: {assessment.created_by?.name ?? "—"}
            {assessment.completed_at && (
              <>
                <span className="mx-1.5">·</span>
                اكتمل في:{" "}
                <time dateTime={assessment.completed_at}>{formatDateTime(assessment.completed_at)}</time>
              </>
            )}
          </p>
        </div>
        <ChevronLeft className="mt-1 size-4 shrink-0 text-subtle-foreground" />
      </Link>
    </li>
  );
}

export function FamilyAssessmentsTab({ familyCode }: { familyCode: string }) {
  const { data, isLoading, isError, error, hasNextPage, fetchNextPage, isFetchingNextPage } =
    useFamilyAssessments(familyCode);

  if (isLoading) {
    return <Skeleton className="h-48 w-full rounded-widget" />;
  }

  if (isError) {
    if (error instanceof ApiError && error.status === 403) {
      return (
        <AppCard padded={false}>
          <EmptyState icon={Lock} title="لا تملك صلاحية عرض تقييمات هذه الأسرة." />
        </AppCard>
      );
    }

    return (
      <Alert variant="destructive">
        <AlertCircle className="size-4" />
        <AlertTitle>تعذّر تحميل التقييمات</AlertTitle>
        <AlertDescription>حدث خطأ أثناء الاتصال بالخادم.</AlertDescription>
      </Alert>
    );
  }

  const assessments = data!.pages.flatMap((page) => page.data);
  const canCreate = data!.pages[0]?.abilities.create ?? false;

  return (
    <AppCard padded={false} className="overflow-hidden">
      <SectionHeader
        className="p-4 sm:p-5"
        icon={ClipboardCheck}
        title="التقييمات"
        description="لقطات مؤرَّخة لوضع الأسرة في مجالات التقييم، الأحدث أولًا"
        action={
          canCreate ? (
            <Button size="sm" asChild>
              <Link href={`/families/${encodeURIComponent(familyCode)}/assessments/new`}>
                <Plus className="size-4" />
                تقييم جديد
              </Link>
            </Button>
          ) : undefined
        }
      />
      {assessments.length === 0 ? (
        <div className="border-t border-stroke-subtle">
          <EmptyState icon={ClipboardList} title="لا توجد تقييمات مسجّلة لهذه الأسرة بعد" />
        </div>
      ) : (
        <>
          <ol className="divide-y divide-stroke-subtle border-t border-stroke-subtle">
            {assessments.map((assessment, index) => (
              <AssessmentRow key={assessment.id} familyCode={familyCode} assessment={assessment} latest={index === 0} />
            ))}
          </ol>
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
