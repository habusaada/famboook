"use client";

import Link from "next/link";
import { AlertCircle, ChevronLeft, ClipboardList, Loader2, Lock, Plus } from "lucide-react";
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
import {
  AssessmentDomainIcon,
  AssessmentRatingBadge,
  AssessmentStatusBadge,
} from "@/components/assessments/assessment-badges";
import { useFamilyAssessments } from "@/lib/api/assessments";
import { ApiError } from "@/lib/api/client";
import type { AssessmentSummary } from "@/lib/types/api/assessment";
import { formatDateTime } from "@/lib/utils/date";

function AssessmentRow({ familyCode, assessment }: { familyCode: string; assessment: AssessmentSummary }) {
  return (
    <li data-assessment-id={assessment.id}>
      <Link
        href={`/families/${encodeURIComponent(familyCode)}/assessments/${assessment.id}`}
        className="flex items-start gap-3 px-4 py-3 transition-colors hover:bg-muted/50"
      >
        <div className="flex min-w-0 flex-1 flex-col gap-1.5">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-medium">
              تقييم بتاريخ <span dir="ltr">{assessment.assessment_date}</span>
            </span>
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
        <ChevronLeft className="mt-1 size-4 shrink-0 text-muted-foreground" />
      </Link>
    </li>
  );
}

export function FamilyAssessmentsTab({ familyCode }: { familyCode: string }) {
  const { data, isLoading, isError, error, hasNextPage, fetchNextPage, isFetchingNextPage } =
    useFamilyAssessments(familyCode);

  if (isLoading) {
    return (
      <Card size="sm">
        <CardContent className="flex flex-col gap-4">
          {Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="flex flex-col gap-2">
              <Skeleton className="h-4 w-56" />
              <Skeleton className="h-3 w-40" />
            </div>
          ))}
        </CardContent>
      </Card>
    );
  }

  if (isError) {
    if (error instanceof ApiError && error.status === 403) {
      return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-16 text-center">
          <Lock className="size-8 text-muted-foreground" />
          <p className="text-sm text-muted-foreground">لا تملك صلاحية عرض تقييمات هذه الأسرة.</p>
        </div>
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
    <Card size="sm">
      <CardHeader>
        <CardTitle>التقييمات</CardTitle>
        <CardDescription>
          لقطات مؤرَّخة لوضع الأسرة في مجالات التقييم، الأحدث أولًا
        </CardDescription>
        {canCreate && (
          <CardAction>
            <Button size="sm" asChild>
              <Link href={`/families/${encodeURIComponent(familyCode)}/assessments/new`}>
                <Plus className="size-4" />
                تقييم جديد
              </Link>
            </Button>
          </CardAction>
        )}
      </CardHeader>
      <CardContent className="p-0">
        {assessments.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 p-12 text-center">
            <ClipboardList className="size-8 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">لا توجد تقييمات مسجّلة لهذه الأسرة بعد.</p>
          </div>
        ) : (
          <>
            <ol className="divide-y">
              {assessments.map((assessment) => (
                <AssessmentRow key={assessment.id} familyCode={familyCode} assessment={assessment} />
              ))}
            </ol>
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
  );
}
