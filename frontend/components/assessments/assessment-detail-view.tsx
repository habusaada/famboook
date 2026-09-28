"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import {
  CalendarDays,
  CheckCircle2,
  ChevronLeft,
  ClipboardCheck,
  CircleDashed,
  HeartHandshake,
  Home,
  Info,
  Lock,
  Pencil,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { AppCard } from "@/components/shared/app-card";
import { IconBox } from "@/components/shared/icon-box";
import { Code } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import { SaveError } from "@/components/shared/edit-dialog-parts";
import { AssessmentDomainIcon } from "@/components/assessments/assessment-badges";
import { AssessmentRatingTag, AssessmentStatusTag } from "@/components/assessments/assessment-case";
import {
  ASSESSMENT_STATUS_MESSAGES,
  AssessmentLoadError,
  BackToAssessments,
  ConfirmCompleteDialog,
} from "@/components/assessments/assessment-shared";
import { NeedFormDialog } from "@/components/needs/need-form-dialog";
import { useAssessment, useCompleteAssessment } from "@/lib/api/assessments";
import { useFamilyNeeds } from "@/lib/api/needs";
import { ApiError } from "@/lib/api/client";
import { useAssessmentDomains } from "@/lib/api/reference";
import type { Assessment, AssessmentRegistryResponse } from "@/lib/types/api/assessment";
import { assessmentStatusLabels, displayDomains } from "@/lib/utils/assessment";
import { formatDateTime } from "@/lib/utils/date";
import { cn } from "@/lib/utils";

const fmt = (n: number) => n.toLocaleString("ar");

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

/**
 * Opens Need creation with this COMPLETED assessment preselected as the
 * source. Nothing is generated automatically: the user picks the category
 * and describes each concrete need.
 */
function CreateNeedFromAssessment({ familyCode, assessmentId }: { familyCode: string; assessmentId: string }) {
  const router = useRouter();
  // Only used for the need.create ability hint.
  const needs = useFamilyNeeds(familyCode);
  if (!needs.data?.pages[0]?.abilities.create) return null;

  return (
    <NeedFormDialog
      familyCode={familyCode}
      sourceAssessmentId={assessmentId}
      onSaved={(response) => router.push(`/needs/${response.data.id}`)}
      trigger={
        <Button variant="outline" size="sm">
          <HeartHandshake className="size-4" />
          إنشاء احتياج
        </Button>
      }
    />
  );
}

/**
 * The household head's name when the workspace registry already loaded this
 * assessment (query cache only — the detail API returns the Family code).
 */
function useCachedHeadName(assessmentId: string): string | null {
  const queryClient = useQueryClient();
  for (const [, page] of queryClient.getQueriesData<AssessmentRegistryResponse>({ queryKey: ["assessments", "registry"] })) {
    const row = page?.data.find((r) => r.id === assessmentId);
    if (row) return row.family.household_head_name;
  }
  return null;
}

/** Page context: Assessments › this Family's assessments › this assessment. */
function Breadcrumb({ familyCode }: { familyCode: string }) {
  const link = "rounded-sm px-0.5 hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-ring";
  return (
    <nav aria-label="مسار الصفحة" className="flex flex-wrap items-center gap-1 text-[13px] text-muted-foreground">
      <Link href="/assessments" className={link}>
        التقييمات
      </Link>
      <ChevronLeft className="size-3.5" aria-hidden />
      <Link href={`/families/${encodeURIComponent(familyCode)}?tab=assessments`} className={link}>
        <Code className="font-medium">{familyCode}</Code>
      </Link>
      <ChevronLeft className="size-3.5" aria-hidden />
      <span aria-current="page" className="font-medium text-foreground">
        التقييم
      </span>
    </nav>
  );
}

function Fact({ icon: Icon, label, children }: { icon: LucideIcon; label: string; children: React.ReactNode }) {
  return (
    <div className="flex min-w-0 items-start gap-2.5 px-4 py-3 sm:px-5" data-fact={label}>
      <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" strokeWidth={1.75} aria-hidden />
      <div className="flex min-w-0 flex-col gap-0.5">
        <span className="text-xs text-muted-foreground">{label}</span>
        <span className="text-[15px] leading-snug font-semibold text-foreground">{children}</span>
      </div>
    </div>
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
  const headName = useCachedHeadName(assessmentId);

  if (isLoading || domainsQuery.isLoading) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true">
        <Skeleton className="h-5 w-48" />
        <Skeleton className="h-44 w-full rounded-widget" />
        <Skeleton className="h-96 w-full rounded-widget" />
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
  const completed = assessment.status === "COMPLETED";
  const byDomain = new Map(assessment.results.map((r) => [r.domain.code, r]));
  // Current domains plus any historical (inactive) domain with a result.
  const domains = displayDomains(
    domainsQuery.data?.data ?? [],
    assessment.results.map((r) => r.domain)
  );
  const rated = assessment.results.length;
  const unrated = Math.max(domains.length - rated, 0);
  const hasDraftActions = abilities.update || abilities.complete;

  return (
    <div className="flex flex-col gap-4">
      <Breadcrumb familyCode={code} />

      {/* Identity: what, whose, state, who/when — then completeness. */}
      <AppCard padded={false} className="overflow-hidden" aria-label="هوية التقييم">
        <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-5">
          <div className="flex min-w-0 items-start gap-4">
            <IconBox icon={ClipboardCheck} size="lg" />
            <div className="flex min-w-0 flex-col gap-1.5">
              <div className="flex flex-wrap items-center gap-2">
                <Link
                  href={`/families/${encodeURIComponent(code)}`}
                  className="flex items-center gap-1.5 rounded-sm text-[13px] font-medium text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                  aria-label={`فتح ملف الأسرة ${code}`}
                  data-family-link
                >
                  <Home className="size-3.5" aria-hidden />
                  <Code>{code}</Code>
                </Link>
                {headName && <span className="text-[13px] text-muted-foreground">— {headName}</span>}
                <AssessmentStatusTag status={assessment.status} />
              </div>
              <h1 className="text-2xl leading-tight font-bold text-foreground">
                تقييم بتاريخ <bdi dir="ltr" className="tabular-nums">{assessment.assessment_date}</bdi>
              </h1>
              <p className="text-[13px] text-muted-foreground">
                أدخله {assessment.created_by?.name ?? "—"} في{" "}
                <time dateTime={assessment.created_at}>{formatDateTime(assessment.created_at)}</time>
              </p>
            </div>
          </div>

          {(completed || hasDraftActions) && (
            <div className="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end" data-assessment-actions>
              {completed && <CreateNeedFromAssessment familyCode={code} assessmentId={assessment.id} />}
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

        {/* Completeness only: counts of stored results — never a score. */}
        <div
          className={cn(
            "grid grid-cols-2 border-t border-stroke-subtle bg-surface-2/60 md:grid-cols-4",
            "[&>*]:border-stroke-subtle [&>*:not(:last-child)]:border-e max-md:[&>*:nth-child(2n)]:border-e-0 max-md:[&>*:nth-child(n+3)]:border-t"
          )}
          aria-label="اكتمال التقييم"
        >
          <Fact icon={ClipboardCheck} label="المجالات المُقيَّمة">
            <bdi className="tabular-nums">{fmt(rated)}</bdi> من <bdi className="tabular-nums">{fmt(domains.length)}</bdi>
          </Fact>
          <Fact icon={CircleDashed} label="لم يتم تقييمها">
            <span className={unrated === 0 ? "font-normal text-muted-foreground" : undefined}>
              <bdi className="tabular-nums">{fmt(unrated)}</bdi> {unrated === 1 ? "مجال" : "مجالات"}
            </span>
          </Fact>
          <Fact icon={Info} label="الحالة">
            {assessmentStatusLabels[assessment.status]}
          </Fact>
          <Fact icon={CalendarDays} label="تاريخ التقييم">
            <bdi dir="ltr" className="tabular-nums">{assessment.assessment_date}</bdi>
          </Fact>
        </div>

        {/* Lifecycle line: quiet record for COMPLETED, what is still possible for DRAFT. */}
        <p className="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-t border-stroke-subtle px-4 py-2.5 text-[13px] text-muted-foreground sm:px-5" data-lifecycle>
          {completed ? (
            <>
              <Lock className="size-3.5 shrink-0" aria-hidden />
              أكمله {assessment.completed_by?.name ?? "—"}
              {assessment.completed_at && (
                <>
                  {" "}في <time dateTime={assessment.completed_at}>{formatDateTime(assessment.completed_at)}</time>
                </>
              )}
              <span aria-hidden>·</span>
              <span>سجل تاريخي للقراءة فقط</span>
            </>
          ) : (
            <>
              <Pencil className="size-3.5 shrink-0" aria-hidden />
              مسودة: يمكن تعديلها حتى الإكمال.
            </>
          )}
        </p>
      </AppCard>

      {/* General notes: secondary, compact when empty. */}
      {assessment.general_notes ? (
        <AppCard aria-labelledby="assessment-notes-title">
          <h2 id="assessment-notes-title" className="text-base font-semibold">
            ملاحظات عامة
          </h2>
          <p className="mt-2 text-sm leading-relaxed whitespace-pre-line text-foreground">{assessment.general_notes}</p>
        </AppCard>
      ) : (
        <AppCard
          padded={false}
          className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 px-4 py-3 sm:px-5"
          aria-labelledby="assessment-notes-title"
        >
          <h2 id="assessment-notes-title" className="text-sm font-semibold">
            ملاحظات عامة
          </h2>
          <p className="text-sm text-muted-foreground" data-no-notes>
            لا توجد ملاحظات عامة
          </p>
        </AppCard>
      )}

      {/* Domains: the centre of the record — one row per domain, rating as text. */}
      <AppCard padded={false} aria-labelledby="assessment-domains-title">
        <div className="flex flex-wrap items-baseline justify-between gap-2 px-4 pt-4 pb-3 sm:px-5">
          <h2 id="assessment-domains-title" className="text-base font-semibold">
            مجالات التقييم
          </h2>
          <span className="text-[13px] text-muted-foreground">
            كل مجال مستقل؛ لا يُحتسب مجموع أو درجة كلية
          </span>
        </div>
        <ul className="divide-y divide-stroke-subtle border-t border-stroke-subtle">
          {domains.map((domain) => {
            const result = byDomain.get(domain.code);
            return (
              <li key={domain.code} className="flex flex-col gap-1.5 px-4 py-3 sm:px-5" data-domain={domain.code}>
                <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
                  <div className="flex min-w-0 items-center gap-3">
                    <span
                      className={cn(
                        "flex size-8 shrink-0 items-center justify-center rounded-control",
                        result ? "bg-brand-50 text-brand-700" : "bg-surface-2 text-muted-foreground"
                      )}
                      aria-hidden
                    >
                      <AssessmentDomainIcon code={domain.code} className="size-4" />
                    </span>
                    <span className={cn("text-[15px] font-semibold", result ? "text-foreground" : "text-foreground/80")}>
                      {domain.name}
                    </span>
                    {!domain.is_active && <StatusBadge tone="neutral">مجال غير مفعّل</StatusBadge>}
                  </div>
                  <AssessmentRatingTag rating={result?.rating ?? null} />
                </div>
                {result?.notes && (
                  <p className="ps-11 text-sm leading-relaxed whitespace-pre-line text-muted-foreground">{result.notes}</p>
                )}
              </li>
            );
          })}
        </ul>
      </AppCard>
    </div>
  );
}
