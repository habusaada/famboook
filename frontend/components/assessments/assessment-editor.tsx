"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { Controller, useForm, useWatch, type Control } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { AlertCircle, Check, CheckCircle2, ChevronLeft, ClipboardPen, Home, Lock, MessageSquarePlus, Save } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { IconBox } from "@/components/shared/icon-box";
import { Code } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import { FieldError, FieldLabel, SaveError } from "@/components/shared/edit-dialog-parts";
import { AssessmentDomainIcon } from "@/components/assessments/assessment-badges";
import { AssessmentStatusTag } from "@/components/assessments/assessment-case";
import {
  ASSESSMENT_STATUS_MESSAGES,
  AssessmentLoadError,
  BackToAssessments,
  ConfirmCompleteDialog,
} from "@/components/assessments/assessment-shared";
import { useAssessment, useSaveAssessment } from "@/lib/api/assessments";
import { useAssessmentDomains } from "@/lib/api/reference";
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";
import {
  assessedCount,
  assessmentApiFieldToFormField,
  assessmentFormValues,
  assessmentSchema,
  toAssessmentPayload,
  todayIso,
  type AssessmentFormValues,
} from "@/lib/schemas/assessment";
import type { Assessment, AssessmentRating, AssessmentRegistryResponse } from "@/lib/types/api/assessment";
import type { FamilyDetail, ResourceResponse } from "@/lib/types/api/family";
import type { AssessmentDomain } from "@/lib/types/api/reference";
import {
  ASSESSMENT_RATINGS,
  assessmentRatingLabels,
  displayDomains,
  NOT_ASSESSED_CHOICE_LABEL,
} from "@/lib/utils/assessment";
import { cn } from "@/lib/utils";

const fmt = (n: number) => n.toLocaleString("ar");
const CHOICES: (AssessmentRating | "")[] = ["", ...ASSESSMENT_RATINGS];

// Selected-state tones, matching AssessmentRatingTag on the detail page.
const selectedTone: Record<AssessmentRating | "", string> = {
  "": "border-dashed border-foreground/35 bg-surface-1 text-foreground",
  NONE: "border-foreground/20 bg-secondary text-foreground",
  LOW: "border-brand-700/30 bg-brand-50 text-brand-800",
  MEDIUM: "border-info/35 bg-info-soft text-info",
  HIGH: "border-warning/40 bg-warning-soft text-warning",
  CRITICAL: "border-danger/40 bg-danger-soft text-danger",
};

/**
 * The household head's name if it is already in the query cache (the
 * family detail or the assessments registry) — never an extra request.
 */
function useCachedHeadName(familyCode: string, assessmentId?: string): string | null {
  const queryClient = useQueryClient();
  const family = queryClient.getQueryData<ResourceResponse<FamilyDetail>>(["families", familyCode]);
  const head = family?.data.members.find((m) => m.is_household_head);
  if (head) return head.full_name;
  if (!assessmentId) return null;
  for (const [, page] of queryClient.getQueriesData<AssessmentRegistryResponse>({ queryKey: ["assessments", "registry"] })) {
    const row = page?.data.find((r) => r.id === assessmentId);
    if (row) return row.family.household_head_name;
  }
  return null;
}

/**
 * One domain's rating as a segmented radio group: native radios (arrow
 * keys, announced selection), "غير مقيّم" = no result — never NONE.
 */
function RatingSelector({
  domain,
  value,
  onChange,
  disabled,
}: {
  domain: AssessmentDomain;
  value: AssessmentRating | "";
  onChange: (value: AssessmentRating | "") => void;
  disabled?: boolean;
}) {
  return (
    <fieldset className="min-w-0" disabled={disabled}>
      <legend className="sr-only">تقييم {domain.name}</legend>
      <div className="flex flex-wrap gap-1 rounded-lg bg-surface-2 p-1 max-sm:grid max-sm:grid-cols-3" data-rating-group={domain.code}>
        {CHOICES.map((choice) => {
          const selected = value === choice;
          return (
            <label
              key={choice || "NOT_ASSESSED"}
              className={cn(
                "relative inline-flex h-9 cursor-pointer items-center justify-center gap-1 rounded-control border px-2.5 text-xs whitespace-nowrap transition-colors sm:h-8",
                "has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-1",
                "has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-50",
                selected
                  ? cn(selectedTone[choice], "font-semibold shadow-e1")
                  : "border-transparent text-muted-foreground hover:bg-surface-1 hover:text-foreground"
              )}
              data-choice={choice || "NOT_ASSESSED"}
              data-selected={selected || undefined}
            >
              <input
                type="radio"
                name={`rating-${domain.code}`}
                value={choice || "NOT_ASSESSED"}
                checked={selected}
                onChange={() => onChange(choice)}
                className="sr-only"
              />
              {selected && <Check className="size-3.5 shrink-0" aria-hidden />}
              {choice === "" ? NOT_ASSESSED_CHOICE_LABEL : assessmentRatingLabels[choice]}
            </label>
          );
        })}
      </div>
    </fieldset>
  );
}

function DomainRow({
  domain,
  control,
  disabled,
}: {
  domain: AssessmentDomain;
  control: Control<AssessmentFormValues>;
  disabled: boolean;
}) {
  const rating = useWatch({ control, name: `results.${domain.code}.rating` });
  const note = useWatch({ control, name: `results.${domain.code}.notes` });
  // An existing note stays open; otherwise the field is revealed on demand.
  const [noteOpen, setNoteOpen] = useState(() => Boolean(note?.trim()));
  const rated = rating !== "";
  const noteId = `domain-${domain.code}-notes`;

  return (
    <li className="flex flex-col gap-2 px-4 py-3.5 sm:px-5" data-domain={domain.code}>
      <div className="flex flex-col gap-2.5 lg:flex-row lg:items-center lg:justify-between lg:gap-4">
        <div className="flex min-w-0 items-center gap-3">
          <span
            className={cn(
              "flex size-8 shrink-0 items-center justify-center rounded-control",
              rated ? "bg-brand-50 text-brand-700" : "bg-surface-2 text-muted-foreground"
            )}
            aria-hidden
          >
            <AssessmentDomainIcon code={domain.code} className="size-4" />
          </span>
          <span className="text-[15px] font-semibold text-foreground">{domain.name}</span>
          {!domain.is_active && <StatusBadge tone="neutral">مجال غير مفعّل</StatusBadge>}
        </div>
        <Controller
          control={control}
          name={`results.${domain.code}.rating`}
          render={({ field }) => (
            <RatingSelector domain={domain} value={field.value} onChange={field.onChange} disabled={disabled} />
          )}
        />
      </div>
      {!domain.is_active && (
        <p className="ps-11 text-xs text-muted-foreground">
          أُوقف هذا المجال بعد تقييمه. يجب اختيار «{NOT_ASSESSED_CHOICE_LABEL}» له قبل إكمال التقييم.
        </p>
      )}
      {/* Notes belong to a stored result: available once the domain is rated. */}
      {rated &&
        (noteOpen ? (
          <Controller
            control={control}
            name={`results.${domain.code}.notes`}
            render={({ field, fieldState }) => (
              <div className="flex flex-col gap-1 ps-11">
                <label htmlFor={noteId} className="text-xs font-medium text-muted-foreground">
                  ملاحظة {domain.name} (اختياري)
                </label>
                <Textarea id={noteId} rows={2} disabled={disabled} autoFocus={!field.value} {...field} data-domain-note />
                <FieldError message={fieldState.error?.message} />
              </div>
            )}
          />
        ) : (
          <div className="ps-11">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-7 gap-1 px-2 text-xs text-muted-foreground hover:text-brand-700"
              onClick={() => setNoteOpen(true)}
              disabled={disabled}
              aria-label={`إضافة ملاحظة لمجال ${domain.name}`}
              data-add-note
            >
              <MessageSquarePlus className="size-3.5" />
              إضافة ملاحظة
            </Button>
          </div>
        ))}
    </li>
  );
}

/** Live completeness from form state: counts only, never a score. */
function Completeness({ control, total }: { control: Control<AssessmentFormValues>; total: number }) {
  const results = useWatch({ control, name: "results" });
  const rated = Object.values(results ?? {}).filter((r) => r.rating !== "").length;
  return (
    <p className="text-[13px] text-muted-foreground" aria-live="polite" data-completeness>
      <bdi className="font-semibold text-foreground tabular-nums">{fmt(rated)}</bdi> من{" "}
      <bdi className="tabular-nums">{fmt(total)}</bdi> مجالات مُقيَّمة
      <span aria-hidden> · </span>
      <bdi className="tabular-nums">{fmt(total - rated)}</bdi> غير مقيّمة
    </p>
  );
}

function AssessmentForm({
  familyCode,
  domains,
  assessment,
}: {
  familyCode: string;
  domains: AssessmentDomain[];
  assessment?: Assessment;
}) {
  const router = useRouter();
  const {
    register,
    control,
    handleSubmit,
    setError,
    clearErrors,
    getValues,
    formState: { errors },
  } = useForm<AssessmentFormValues>({
    resolver: zodResolver(assessmentSchema),
    defaultValues: assessmentFormValues(domains, assessment),
  });

  // Set once a new draft exists, so any retry updates it instead of
  // creating a second assessment.
  const draftId = useRef<string | undefined>(assessment?.id);
  const flow = useGuardedSave({
    mutation: useSaveAssessment(familyCode, (response) =>
      router.push(`/families/${encodeURIComponent(familyCode)}/assessments/${response.data.id}`)
    ),
    apiFieldToFormField: assessmentApiFieldToFormField,
    setError,
    statusMessages: ASSESSMENT_STATUS_MESSAGES,
  });
  const [confirmOpen, setConfirmOpen] = useState(false);

  const toVariables = (values: AssessmentFormValues, complete: boolean) => ({
    id: draftId.current,
    complete,
    payload: toAssessmentPayload(domains, values),
    onCreated: (id: string) => {
      draftId.current = id;
    },
  });

  function requestComplete() {
    void handleSubmit((values) => {
      if (assessedCount(values) === 0) {
        setError("root", { message: "قيّم مجالًا واحدًا على الأقل قبل إكمال التقييم." });
        return;
      }
      clearErrors("root");
      setConfirmOpen(true);
    })();
  }

  const pending = flow.isPending;

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        clearErrors("root");
        flow.submit(handleSubmit, (values) => toVariables(values, false));
      }}
      className="flex flex-col gap-4"
    >
      <SaveError message={flow.submitError} />
      {errors.root?.message && (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertTitle>لا يمكن إكمال التقييم</AlertTitle>
          <AlertDescription>{errors.root.message}</AlertDescription>
        </Alert>
      )}

      {/* Metadata: the business date and optional general notes. */}
      <AppCard aria-labelledby="assessment-meta-title">
        <h2 id="assessment-meta-title" className="text-base font-semibold">
          بيانات التقييم
        </h2>
        <div className="mt-3 grid gap-4 md:grid-cols-[13rem_1fr]">
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="assessment-date">تاريخ التقييم</FieldLabel>
            <Input
              id="assessment-date"
              type="date"
              dir="ltr"
              className="h-10 bg-surface-2 text-end"
              max={todayIso()}
              disabled={pending}
              aria-describedby="assessment-date-hint"
              {...register("assessmentDate")}
            />
            <p id="assessment-date-hint" className="text-xs text-muted-foreground">
              تاريخ زيارة الأسرة وتقييمها، وليس تاريخ الإدخال.
            </p>
            <FieldError message={errors.assessmentDate?.message} />
          </div>
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="assessment-notes" optional>
              ملاحظات عامة
            </FieldLabel>
            <Textarea id="assessment-notes" rows={2} className="min-h-10 bg-surface-2" disabled={pending} {...register("generalNotes")} />
            <FieldError message={errors.generalNotes?.message} />
          </div>
        </div>
      </AppCard>

      {/* Domains: all eight on one page, same order and icons as the detail. */}
      <AppCard padded={false} aria-labelledby="assessment-domains-title">
        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 px-4 pt-4 pb-3 sm:px-5">
          <h2 id="assessment-domains-title" className="text-base font-semibold">
            مجالات التقييم
          </h2>
          <p className="text-[13px] text-muted-foreground">
            قيّم المجالات التي شملها التقييم فقط. «{NOT_ASSESSED_CHOICE_LABEL}» يعني أن المجال لم يُقيَّم ولا تُحفظ له نتيجة.
          </p>
        </div>
        <ul className="divide-y divide-stroke-subtle border-t border-stroke-subtle">
          {domains.map((domain) => (
            <DomainRow key={domain.code} domain={domain} control={control} disabled={pending} />
          ))}
        </ul>
      </AppCard>

      {/* Actions footer (not sticky: the shell's overflow-x-hidden <main> would break sticky positioning). */}
      <div
        className="flex flex-col gap-3 rounded-widget border border-stroke-subtle bg-surface-1 px-4 py-3 shadow-e1 sm:flex-row sm:items-center sm:justify-between sm:px-5"
        data-editor-actions
      >
        <Completeness control={control} total={domains.length} />
        <div className="flex flex-wrap items-center gap-2 max-sm:[&>*]:flex-1">
          <Button variant="ghost" asChild>
            <Link
              href={
                assessment
                  ? `/families/${encodeURIComponent(familyCode)}/assessments/${assessment.id}`
                  : `/families/${encodeURIComponent(familyCode)}?tab=assessments`
              }
            >
              إلغاء
            </Link>
          </Button>
          <Button type="button" variant="outline" disabled={pending} onClick={requestComplete}>
            <CheckCircle2 className="size-4" />
            إكمال التقييم
          </Button>
          <Button type="submit" disabled={pending}>
            <Save className="size-4" />
            {pending ? "جارٍ الحفظ..." : "حفظ كمسودة"}
          </Button>
        </div>
      </div>

      <ConfirmCompleteDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        assessedCount={confirmOpen ? assessedCount(getValues()) : 0}
        onConfirm={() => {
          setConfirmOpen(false);
          flow.submit(handleSubmit, (values) => toVariables(values, true));
        }}
      />
    </form>
  );
}

/** Page context: Assessments › Family › (this assessment ›) the editor. */
function Breadcrumb({ familyCode, assessmentId }: { familyCode: string; assessmentId?: string }) {
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
      {assessmentId && (
        <>
          <ChevronLeft className="size-3.5" aria-hidden />
          <Link href={`/families/${encodeURIComponent(familyCode)}/assessments/${assessmentId}`} className={link}>
            التقييم
          </Link>
        </>
      )}
      <ChevronLeft className="size-3.5" aria-hidden />
      <span aria-current="page" className="font-medium text-foreground">
        {assessmentId ? "تعديل المسودة" : "تقييم جديد"}
      </span>
    </nav>
  );
}

function EditorHeader({
  familyCode,
  assessment,
  headName,
}: {
  familyCode: string;
  assessment?: Assessment;
  headName: string | null;
}) {
  return (
    <AppCard padded={false} className="flex items-start gap-4 p-4 sm:p-5" aria-label="هوية التقييم">
      <IconBox icon={ClipboardPen} size="lg" />
      <div className="flex min-w-0 flex-col gap-1.5">
        <div className="flex flex-wrap items-center gap-2">
          <Link
            href={`/families/${encodeURIComponent(familyCode)}`}
            className="flex items-center gap-1.5 rounded-sm text-[13px] font-medium text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
            aria-label={`فتح ملف الأسرة ${familyCode}`}
            data-family-link
          >
            <Home className="size-3.5" aria-hidden />
            <Code>{familyCode}</Code>
          </Link>
          {headName && <span className="text-[13px] text-muted-foreground">— {headName}</span>}
          {assessment && <AssessmentStatusTag status={assessment.status} />}
        </div>
        <h1 className="text-2xl leading-tight font-bold text-foreground">
          {assessment ? "تعديل مسودة التقييم" : "تقييم جديد"}
        </h1>
        <p className="text-[13px] text-muted-foreground">
          {assessment ? (
            <>
              تقييم بتاريخ <bdi dir="ltr" className="tabular-nums">{assessment.assessment_date}</bdi> — يمكن تعديله حتى الإكمال.
            </>
          ) : (
            "لقطة لوضع الأسرة في تاريخ محدد. بعد الإكمال يصبح التقييم سجلًا تاريخيًا غير قابل للتعديل."
          )}
        </p>
      </div>
    </AppCard>
  );
}

/** New assessment (no assessmentId) or edit of an existing DRAFT. */
export function AssessmentEditor({
  familyCode,
  assessmentId,
}: {
  familyCode: string;
  assessmentId?: string;
}) {
  const domainsQuery = useAssessmentDomains();
  const assessmentQuery = useAssessment(assessmentId);
  const assessment = assessmentQuery.data?.data;
  const headName = useCachedHeadName(familyCode, assessmentId);

  if (domainsQuery.isLoading || (assessmentId && assessmentQuery.isLoading)) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true">
        <Skeleton className="h-5 w-56" />
        <Skeleton className="h-28 w-full rounded-widget" />
        <Skeleton className="h-96 w-full rounded-widget" />
      </div>
    );
  }

  if (domainsQuery.isError || assessmentQuery.isError) {
    return (
      <div className="flex flex-col gap-4">
        <BackToAssessments familyCode={familyCode} />
        <AssessmentLoadError error={domainsQuery.error ?? assessmentQuery.error} />
      </div>
    );
  }

  if (assessment && (assessment.status !== "DRAFT" || !assessmentQuery.data?.abilities.update)) {
    return (
      <div className="flex flex-col gap-4">
        <Breadcrumb familyCode={familyCode} assessmentId={assessment.id} />
        <AppCard padded={false}>
          <EmptyState
            icon={Lock}
            title={assessment.status === "COMPLETED" ? "هذا التقييم مكتمل ولا يمكن تعديله." : "لا تملك صلاحية تعديل هذا التقييم."}
            action={
              <Button variant="outline" size="sm" asChild>
                <Link href={`/families/${encodeURIComponent(familyCode)}/assessments/${assessment.id}`}>عرض التقييم</Link>
              </Button>
            }
          />
        </AppCard>
      </div>
    );
  }

  const domains = displayDomains(
    domainsQuery.data?.data ?? [],
    assessment?.results.map((r) => r.domain) ?? []
  );

  return (
    <div className="flex flex-col gap-4">
      <Breadcrumb familyCode={familyCode} assessmentId={assessment?.id} />
      <EditorHeader familyCode={familyCode} assessment={assessment} headName={headName} />
      <AssessmentForm familyCode={familyCode} domains={domains} assessment={assessment} />
    </div>
  );
}
