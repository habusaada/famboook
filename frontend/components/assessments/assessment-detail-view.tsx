"use client";

import { useState } from "react";
import Link from "next/link";
import { CalendarDays, CheckCircle2, Clock, Pencil, User } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { SaveError } from "@/components/shared/edit-dialog-parts";
import {
  AssessmentDomainIcon,
  AssessmentRatingBadge,
  AssessmentStatusBadge,
} from "@/components/assessments/assessment-badges";
import {
  ASSESSMENT_STATUS_MESSAGES,
  AssessmentLoadError,
  BackToAssessments,
  ConfirmCompleteDialog,
} from "@/components/assessments/assessment-shared";
import { useAssessment, useCompleteAssessment } from "@/lib/api/assessments";
import { ApiError } from "@/lib/api/client";
import { useAssessmentDomains } from "@/lib/api/reference";
import type { Assessment } from "@/lib/types/api/assessment";
import { displayDomains } from "@/lib/utils/assessment";
import { formatDateTime } from "@/lib/utils/date";

function MetaItem({ icon: Icon, children }: { icon: React.ElementType; children: React.ReactNode }) {
  return (
    <span className="flex items-center gap-1.5">
      <Icon className="size-3.5 shrink-0" />
      {children}
    </span>
  );
}

function CompleteAction({ familyCode, assessment }: { familyCode: string; assessment: Assessment }) {
  const mutation = useCompleteAssessment(familyCode, assessment.id);
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const empty = assessment.results.length === 0;

  return (
    <>
      <Button
        size="sm"
        disabled={empty || mutation.isPending}
        title={empty ? "قيّم مجالًا واحدًا على الأقل أولًا" : undefined}
        onClick={() => {
          setError(null);
          setOpen(true);
        }}
      >
        <CheckCircle2 className="size-4" />
        {mutation.isPending ? "جارٍ الإكمال..." : "إكمال التقييم"}
      </Button>
      <ConfirmCompleteDialog
        open={open}
        onOpenChange={setOpen}
        assessedCount={assessment.results.length}
        onConfirm={() => {
          setOpen(false);
          if (mutation.isPending) return;
          mutation.mutate(undefined, {
            onError: (e) => {
              const status = e instanceof ApiError ? e.status : 0;
              setError(
                (e instanceof ApiError && status === 422 && e.message422) ||
                  ASSESSMENT_STATUS_MESSAGES[status as keyof typeof ASSESSMENT_STATUS_MESSAGES] ||
                  "تعذّر الاتصال بالخادم. الرجاء المحاولة مرة أخرى."
              );
            },
          });
        }}
      />
      {error && (
        <div className="basis-full">
          <SaveError message={error} />
        </div>
      )}
    </>
  );
}

export function AssessmentDetailView({
  familyCode,
  assessmentId,
}: {
  familyCode: string;
  assessmentId: string;
}) {
  const { data, isLoading, isError, error } = useAssessment(assessmentId);
  const domainsQuery = useAssessmentDomains();

  if (isLoading || domainsQuery.isLoading) {
    return (
      <div className="flex flex-col gap-4">
        <BackToAssessments familyCode={familyCode} />
        <Skeleton className="h-28" />
        <Skeleton className="h-96" />
      </div>
    );
  }

  if (isError) {
    return (
      <div className="flex flex-col gap-4">
        <BackToAssessments familyCode={familyCode} />
        <AssessmentLoadError error={error} />
      </div>
    );
  }

  const { data: assessment, abilities } = data!;
  const code = assessment.family.family_code;
  const byDomain = new Map(assessment.results.map((r) => [r.domain.code, r]));
  // Current domains plus any historical (inactive) domain with a result.
  const domains = displayDomains(
    domainsQuery.data?.data ?? [],
    assessment.results.map((r) => r.domain)
  );

  return (
    <div className="flex flex-col gap-4">
      <BackToAssessments familyCode={code} />

      <Card size="sm">
        <CardContent className="flex flex-col gap-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="text-xl font-semibold tracking-tight">
                تقييم بتاريخ <span dir="ltr">{assessment.assessment_date}</span>
              </h2>
              <AssessmentStatusBadge status={assessment.status} />
            </div>
            {(abilities.update || abilities.complete) && (
              <div className="flex flex-wrap items-center gap-2">
                {abilities.update && (
                  <Button variant="outline" size="sm" asChild>
                    <Link href={`/families/${encodeURIComponent(code)}/assessments/${assessment.id}/edit`}>
                      <Pencil className="size-4" />
                      تعديل
                    </Link>
                  </Button>
                )}
                {abilities.complete && <CompleteAction familyCode={code} assessment={assessment} />}
              </div>
            )}
          </div>

          <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-muted-foreground">
            <MetaItem icon={CalendarDays}>
              تاريخ التقييم: <span dir="ltr">{assessment.assessment_date}</span>
            </MetaItem>
            <MetaItem icon={User}>أُدخل بواسطة: {assessment.created_by?.name ?? "—"}</MetaItem>
            <MetaItem icon={Clock}>
              تاريخ الإدخال: <time dateTime={assessment.created_at}>{formatDateTime(assessment.created_at)}</time>
            </MetaItem>
          </div>

          {assessment.status === "COMPLETED" ? (
            <p className="flex flex-wrap items-center gap-x-1.5 rounded-md bg-muted/60 px-3 py-2 text-sm">
              <CheckCircle2 className="size-4 text-primary" />
              <span className="font-medium">مكتمل</span>
              <span className="text-muted-foreground">
                — أكمله {assessment.completed_by?.name ?? "—"} في{" "}
                {assessment.completed_at && (
                  <time dateTime={assessment.completed_at}>{formatDateTime(assessment.completed_at)}</time>
                )}
                . هذا سجل تاريخي للقراءة فقط.
              </span>
            </p>
          ) : (
            <p className="rounded-md bg-muted/60 px-3 py-2 text-sm text-muted-foreground">
              مسودة: يمكن تعديلها حتى الإكمال.
            </p>
          )}
        </CardContent>
      </Card>

      <Card size="sm">
        <CardHeader>
          <CardTitle>ملاحظات عامة</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-sm whitespace-pre-line text-muted-foreground">
            {assessment.general_notes || "لا توجد ملاحظات عامة."}
          </p>
        </CardContent>
      </Card>

      <Card size="sm">
        <CardHeader>
          <CardTitle>مجالات التقييم</CardTitle>
          <CardDescription>
            {assessment.results.length} من {domains.length} مجالات مُقيَّمة
          </CardDescription>
        </CardHeader>
        <CardContent className="p-0">
          <ul className="divide-y border-t">
            {domains.map((domain) => {
              const result = byDomain.get(domain.code);
              return (
                <li key={domain.code} className="flex flex-col gap-1.5 px-4 py-3" data-domain={domain.code}>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                      <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                        <AssessmentDomainIcon code={domain.code} className="size-4" />
                      </span>
                      <span className="text-sm font-medium">{domain.name}</span>
                      {!domain.is_active && (
                        <Badge variant="outline" className="text-muted-foreground">
                          مجال غير مفعّل
                        </Badge>
                      )}
                    </div>
                    <AssessmentRatingBadge rating={result?.rating ?? null} />
                  </div>
                  {result?.notes && (
                    <p className="ps-9 text-sm whitespace-pre-line text-muted-foreground">{result.notes}</p>
                  )}
                </li>
              );
            })}
          </ul>
        </CardContent>
      </Card>
    </div>
  );
}
