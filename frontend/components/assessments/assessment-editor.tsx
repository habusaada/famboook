"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Controller, useForm, useWatch, type Control } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { cn } from "cn";
import { AlertCircle, CheckCircle2, Lock, Save } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { FieldError, FieldLabel, SaveError } from "@/components/shared/edit-dialog-parts";
import { AssessmentDomainIcon } from "@/components/assessments/assessment-badges";
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
import type { Assessment, AssessmentRating } from "@/lib/types/api/assessment";
import type { AssessmentDomain } from "@/lib/types/api/reference";
import {
  ASSESSMENT_RATINGS,
  assessmentRatingLabels,
  assessmentRatingStyles,
  displayDomains,
  NOT_ASSESSED_CHOICE_LABEL,
} from "@/lib/utils/assessment";

const CHOICES: (AssessmentRating | "")[] = ["", ...ASSESSMENT_RATINGS];

function RatingChoices({
  id,
  value,
  onChange,
  disabled,
}: {
  id: string;
  value: AssessmentRating | "";
  onChange: (value: AssessmentRating | "") => void;
  disabled?: boolean;
}) {
  return (
    <div role="radiogroup" aria-labelledby={id} className="flex flex-wrap gap-1.5">
      {CHOICES.map((choice) => {
        const selected = value === choice;
        return (
          <button
            key={choice || "NOT_ASSESSED"}
            type="button"
            role="radio"
            aria-checked={selected}
            disabled={disabled}
            onClick={() => onChange(choice)}
            className={cn(
              "h-7 rounded-md border px-2.5 text-xs transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50",
              selected
                ? choice === ""
                  ? "border-dashed border-foreground/30 bg-muted font-medium text-foreground"
                  : cn(assessmentRatingStyles[choice], "ring-1 ring-current/30")
                : "border-border bg-background text-muted-foreground hover:bg-muted"
            )}
          >
            {choice === "" ? NOT_ASSESSED_CHOICE_LABEL : assessmentRatingLabels[choice]}
          </button>
        );
      })}
    </div>
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
  const labelId = `domain-${domain.code}-label`;

  return (
    <li className="flex flex-col gap-2.5 px-4 py-3" data-domain={domain.code}>
      <div className="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex items-center gap-2">
          <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
            <AssessmentDomainIcon code={domain.code} className="size-4" />
          </span>
          <span id={labelId} className="text-sm font-medium">
            {domain.name}
          </span>
          {!domain.is_active && (
            <Badge variant="outline" className="text-muted-foreground">
              مجال غير مفعّل
            </Badge>
          )}
        </div>
        <Controller
          control={control}
          name={`results.${domain.code}.rating`}
          render={({ field }) => (
            <RatingChoices id={labelId} value={field.value} onChange={field.onChange} disabled={disabled} />
          )}
        />
      </div>
      {!domain.is_active && (
        <p className="text-xs text-muted-foreground">
          أُوقف هذا المجال بعد تقييمه. يجب اختيار «{NOT_ASSESSED_CHOICE_LABEL}» له قبل إكمال التقييم.
        </p>
      )}
      {rating !== "" && (
        <Controller
          control={control}
          name={`results.${domain.code}.notes`}
          render={({ field, fieldState }) => (
            <div className="flex flex-col gap-1">
              <Textarea
                rows={2}
                placeholder="ملاحظات المجال (اختياري)"
                aria-label={`ملاحظات ${domain.name}`}
                disabled={disabled}
                {...field}
              />
              <FieldError message={fieldState.error?.message} />
            </div>
          )}
        />
      )}
    </li>
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

      <Card size="sm">
        <CardContent className="grid gap-4 md:grid-cols-[14rem_1fr]">
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="assessment-date">تاريخ التقييم</FieldLabel>
            <Input
              id="assessment-date"
              type="date"
              dir="ltr"
              className="text-end"
              max={todayIso()}
              disabled={pending}
              {...register("assessmentDate")}
            />
            <p className="text-xs text-muted-foreground">تاريخ زيارة الأسرة وتقييمها، وليس تاريخ الإدخال.</p>
            <FieldError message={errors.assessmentDate?.message} />
          </div>
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="assessment-notes" optional>
              ملاحظات عامة
            </FieldLabel>
            <Textarea id="assessment-notes" rows={3} disabled={pending} {...register("generalNotes")} />
            <FieldError message={errors.generalNotes?.message} />
          </div>
        </CardContent>
      </Card>

      <Card size="sm">
        <CardHeader>
          <CardTitle>مجالات التقييم</CardTitle>
          <CardDescription>
            قيّم المجالات التي شملها التقييم فقط. «{NOT_ASSESSED_CHOICE_LABEL}» يعني أن المجال لم يُقيَّم
            ولا يُحفظ له أي نتيجة.
          </CardDescription>
        </CardHeader>
        <CardContent className="p-0">
          <ul className="divide-y border-t">
            {domains.map((domain) => (
              <DomainRow key={domain.code} domain={domain} control={control} disabled={pending} />
            ))}
          </ul>
        </CardContent>
      </Card>

      <div className="flex flex-wrap items-center justify-end gap-2">
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
        <Button type="submit" variant="outline" disabled={pending}>
          <Save className="size-4" />
          {pending ? "جارٍ الحفظ..." : "حفظ كمسودة"}
        </Button>
        <Button type="button" disabled={pending} onClick={requestComplete}>
          <CheckCircle2 className="size-4" />
          إكمال التقييم
        </Button>
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

  const header = (
    <div className="flex flex-col gap-1">
      <BackToAssessments familyCode={familyCode} />
      <h2 className="text-xl font-semibold tracking-tight">
        {assessmentId ? "تعديل مسودة التقييم" : "تقييم جديد"}
      </h2>
      <p className="text-sm text-muted-foreground">
        لقطة لوضع الأسرة في تاريخ محدد. بعد الإكمال يصبح التقييم سجلًا تاريخيًا غير قابل للتعديل.
      </p>
    </div>
  );

  if (domainsQuery.isLoading || (assessmentId && assessmentQuery.isLoading)) {
    return (
      <div className="flex flex-col gap-4">
        {header}
        <Skeleton className="h-28" />
        <Skeleton className="h-96" />
      </div>
    );
  }

  if (domainsQuery.isError || assessmentQuery.isError) {
    return (
      <div className="flex flex-col gap-4">
        {header}
        <AssessmentLoadError error={domainsQuery.error ?? assessmentQuery.error} />
      </div>
    );
  }

  if (assessment && (assessment.status !== "DRAFT" || !assessmentQuery.data?.abilities.update)) {
    return (
      <div className="flex flex-col gap-4">
        {header}
        <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-16 text-center">
          <Lock className="size-8 text-muted-foreground" />
          <p className="text-sm text-muted-foreground">
            {assessment.status === "COMPLETED"
              ? "هذا التقييم مكتمل ولا يمكن تعديله."
              : "لا تملك صلاحية تعديل هذا التقييم."}
          </p>
          <Button variant="outline" size="sm" asChild>
            <Link href={`/families/${encodeURIComponent(familyCode)}/assessments/${assessment.id}`}>
              عرض التقييم
            </Link>
          </Button>
        </div>
      </div>
    );
  }

  const domains = displayDomains(
    domainsQuery.data?.data ?? [],
    assessment?.results.map((r) => r.domain) ?? []
  );

  return (
    <div className="flex flex-col gap-4">
      {header}
      <AssessmentForm familyCode={familyCode} domains={domains} assessment={assessment} />
    </div>
  );
}
