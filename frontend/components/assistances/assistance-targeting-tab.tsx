"use client";

import { useState } from "react";
import { Controller, useForm, type Control } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { cn } from "cn";
import { Search, UserPlus } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { FieldError, FieldLabel, SaveError } from "@/components/shared/edit-dialog-parts";
import { AssessmentRatingBadge } from "@/components/assessments/assessment-badges";
import { NeedPriorityBadge } from "@/components/needs/need-badges";
import { useNominateFromTargeting, useTargetingPreview } from "@/lib/api/assistances";
import { ApiError } from "@/lib/api/client";
import { useAssessmentDomains, useNeedCategories } from "@/lib/api/reference";
import {
  targetingFormValues,
  targetingSchema,
  toTargetingCriteria,
  type TargetingFormValues,
} from "@/lib/schemas/assistance";
import type {
  Assistance,
  AssistanceResponse,
  TargetingCriteria,
  TargetingMatch,
} from "@/lib/types/api/assistance";
import { ASSESSMENT_RATINGS, assessmentRatingLabels } from "@/lib/utils/assessment";
import { displacementStatusLabel } from "@/lib/utils/displacement";
import { NEED_PRIORITIES, needPriorityLabels } from "@/lib/utils/need";
import { targetingIndicatorLabels } from "@/lib/utils/assistance";

const ANY = "ANY";

function SelectField({
  control,
  name,
  label,
  options,
  anyLabel = "الكل",
}: {
  control: Control<TargetingFormValues>;
  name: "displacement" | "childUnderTwo" | "pregnant" | "breastfeeding" | "disability" | "chronic" | "needCategory" | "assessmentDomain";
  label: string;
  options: { value: string; label: string }[];
  anyLabel?: string;
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <FieldLabel htmlFor={`targeting-${name}`}>{label}</FieldLabel>
      <Controller
        control={control}
        name={name}
        render={({ field }) => (
          <Select value={field.value || ANY} onValueChange={(v) => field.onChange(v === ANY ? "" : v)}>
            <SelectTrigger id={`targeting-${name}`}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ANY}>{anyLabel}</SelectItem>
              {options.map((o) => (
                <SelectItem key={o.value} value={o.value}>
                  {o.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      />
    </div>
  );
}

function Chips({
  control,
  name,
  label,
  options,
}: {
  control: Control<TargetingFormValues>;
  name: "needPriorities" | "assessmentRatings";
  label: string;
  options: { value: string; label: string }[];
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <span className="text-sm font-medium">{label}</span>
      <Controller
        control={control}
        name={name}
        render={({ field }) => (
          <div className="flex flex-wrap gap-1.5" role="group" aria-label={label}>
            {options.map((o) => {
              const on = field.value.includes(o.value);
              return (
                <button
                  key={o.value}
                  type="button"
                  aria-pressed={on}
                  onClick={() => field.onChange(on ? field.value.filter((v) => v !== o.value) : [...field.value, o.value])}
                  className={cn(
                    "h-7 rounded-md border px-2.5 text-xs transition-colors",
                    on ? "border-primary bg-primary text-primary-foreground" : "bg-background text-muted-foreground hover:bg-muted"
                  )}
                >
                  {o.label}
                </button>
              );
            })}
          </div>
        )}
      />
      <p className="text-xs text-muted-foreground">يكفي تطابق قيمة واحدة من المحدد.</p>
    </div>
  );
}

const TRI = [
  { value: "yes", label: "نعم (يوجد)" },
  { value: "no", label: "لا (لا يوجد)" },
];

/** Minimal "why it matched" indicators — never details. */
function Indicators({ match }: { match: TargetingMatch }) {
  const chips: React.ReactNode[] = [];
  if (match.indicators.children_under_two !== undefined) {
    chips.push(<Badge key="u2" variant="secondary">أطفال دون سنتين: {match.indicators.children_under_two}</Badge>);
  }
  for (const [key, label] of Object.entries(targetingIndicatorLabels)) {
    if (match.indicators[key as keyof typeof targetingIndicatorLabels]) {
      chips.push(<Badge key={key} variant="secondary">{label}</Badge>);
    }
  }
  if (match.matching_need) {
    chips.push(
      <span key="need" className="inline-flex items-center gap-1 text-xs">
        احتياج {match.matching_need.category.name} مفتوح:
        <NeedPriorityBadge priority={match.matching_need.priority} />
      </span>
    );
  }
  if (match.matching_assessment) {
    chips.push(
      <span key="assessment" className="inline-flex items-center gap-1 text-xs">
        تقييم {match.matching_assessment.domain.name}:
        <AssessmentRatingBadge rating={match.matching_assessment.rating} />
      </span>
    );
  }
  return chips.length ? <div className="flex flex-wrap items-center gap-1.5">{chips}</div> : <span className="text-muted-foreground">—</span>;
}

export function AssistanceTargetingTab({
  assistance,
  abilities,
}: {
  assistance: Assistance;
  abilities: AssistanceResponse["abilities"];
}) {
  const needCategories = useNeedCategories().data?.data ?? [];
  const domains = useAssessmentDomains().data?.data ?? [];
  const preview = useTargetingPreview(assistance.id);
  const nominate = useNominateFromTargeting(assistance.id);
  const [criteria, setCriteria] = useState<TargetingCriteria | null>(null);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const {
    register,
    control,
    handleSubmit,
    formState: { errors },
  } = useForm<TargetingFormValues>({
    resolver: zodResolver(targetingSchema),
    defaultValues: targetingFormValues(assistance.targeting_criteria),
  });

  if (!abilities.preview) {
    return (
      <p className="rounded-lg border border-dashed p-12 text-center text-sm text-muted-foreground">
        {assistance.status === "DRAFT" || assistance.status === "OPEN"
          ? "لا تملك صلاحية معاينة الاستهداف."
          : "الاستهداف غير متاح في حالة المساعدة الحالية."}
      </p>
    );
  }

  function run(nextCriteria: TargetingCriteria, page: number) {
    setError(null);
    preview.mutate(
      { criteria: nextCriteria, page },
      {
        onSuccess: () => setCriteria(nextCriteria),
        onError: (e) => setError((e instanceof ApiError && e.message422) || "تعذّرت المعاينة. الرجاء المحاولة مرة أخرى."),
      }
    );
  }

  const result = preview.data;
  const rows = result?.data ?? [];
  const selectable = rows.filter((r) => !r.already_nominated);
  const pageAllSelected = selectable.length > 0 && selectable.every((r) => selected.has(r.family_code));

  function toggle(code: string) {
    const next = new Set(selected);
    if (next.has(code)) next.delete(code);
    else next.add(code);
    setSelected(next);
  }

  function nominateSelected() {
    if (!criteria || selected.size === 0 || nominate.isPending) return;
    setMessage(null);
    setError(null);
    nominate.mutate(
      { family_codes: [...selected], criteria },
      {
        onSuccess: (response) => {
          const { created, skipped_duplicates } = response.data;
          setMessage(
            `تمت إضافة ${created} أسرة كمرشحين` + (skipped_duplicates ? `، وتم تخطي ${skipped_duplicates} مرشحة مسبقًا.` : ".")
          );
          setSelected(new Set());
          run(criteria, result?.meta.current_page ?? 1);
        },
        onError: (e) => setError((e instanceof ApiError && e.message422) || "تعذّرت إضافة المرشحين."),
      }
    );
  }

  return (
    <div className="flex flex-col gap-4">
      <Card size="sm">
        <CardHeader>
          <CardTitle>معايير الاستهداف</CardTitle>
          <CardDescription>
            تُطبَّق جميع المعايير المحددة معًا (و). الحقول الفارغة لا تُقيّد النتائج. المعاينة لا تحفظ شيئًا ولا ترشّح أحدًا.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              void handleSubmit((values) => {
                setSelected(new Set());
                setMessage(null);
                run(toTargetingCriteria(values), 1);
              })();
            }}
            className="flex flex-col gap-5"
          >
            <section className="grid gap-3 sm:grid-cols-4">
              <h3 className="text-sm font-medium sm:col-span-4">حجم الأسرة (الأفراد النشطون الأحياء)</h3>
              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor="targeting-min">الحد الأدنى</FieldLabel>
                <Input id="targeting-min" inputMode="numeric" dir="ltr" className="text-end" {...register("minMembers")} />
                <FieldError message={errors.minMembers?.message} />
              </div>
              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor="targeting-max">الحد الأعلى</FieldLabel>
                <Input id="targeting-max" inputMode="numeric" dir="ltr" className="text-end" {...register("maxMembers")} />
                <FieldError message={errors.maxMembers?.message} />
              </div>
            </section>

            <section className="grid gap-3 border-t pt-4 sm:grid-cols-4">
              <h3 className="text-sm font-medium sm:col-span-4">النزوح</h3>
              <SelectField
                control={control}
                name="displacement"
                label="حالة النزوح"
                options={[
                  { value: "DISPLACED", label: "نازحة" },
                  { value: "NOT_DISPLACED", label: "غير نازحة" },
                ]}
              />
              <div className="flex flex-col gap-1.5 sm:col-span-2">
                <FieldLabel htmlFor="targeting-location" optional>
                  مكان النزوح الحالي (يحتوي على)
                </FieldLabel>
                <Input id="targeting-location" {...register("location")} />
                <FieldError message={errors.location?.message} />
              </div>
            </section>

            <section className="grid gap-3 border-t pt-4 sm:grid-cols-4">
              <h3 className="text-sm font-medium sm:col-span-4">الفئات</h3>
              <SelectField control={control} name="childUnderTwo" label="طفل دون سنتين" options={TRI} />
              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor="targeting-min-children" optional>
                  الحد الأدنى لعدد الأطفال دون سنتين
                </FieldLabel>
                <Input id="targeting-min-children" inputMode="numeric" dir="ltr" className="text-end" {...register("minChildrenUnderTwo")} />
                <FieldError message={errors.minChildrenUnderTwo?.message} />
              </div>
              <SelectField control={control} name="pregnant" label="يوجد حامل" options={TRI} />
              <SelectField control={control} name="breastfeeding" label="يوجد مرضع" options={TRI} />
              <SelectField control={control} name="disability" label="يوجد شخص ذو إعاقة" options={TRI} />
              <SelectField control={control} name="chronic" label="يوجد مرض مزمن" options={TRI} />
            </section>

            <section className="grid gap-3 border-t pt-4 sm:grid-cols-2">
              <h3 className="text-sm font-medium sm:col-span-2">الاحتياجات المفتوحة</h3>
              <SelectField
                control={control}
                name="needCategory"
                label="تصنيف الاحتياج"
                anyLabel="أي تصنيف"
                options={needCategories.map((c) => ({ value: c.code, label: c.name }))}
              />
              <Chips
                control={control}
                name="needPriorities"
                label="الأولوية"
                options={[...NEED_PRIORITIES].reverse().map((p) => ({ value: p, label: needPriorityLabels[p] }))}
              />
            </section>

            <section className="grid gap-3 border-t pt-4 sm:grid-cols-2">
              <h3 className="text-sm font-medium sm:col-span-2">
                التقييم <span className="font-normal text-muted-foreground">(آخر تقييم مكتمل قيّم هذا المجال)</span>
              </h3>
              <div className="flex flex-col gap-1.5">
                <SelectField
                  control={control}
                  name="assessmentDomain"
                  label="مجال التقييم"
                  anyLabel="بدون"
                  options={domains.map((d) => ({ value: d.code, label: d.name }))}
                />
                <FieldError message={errors.assessmentDomain?.message} />
              </div>
              <Chips
                control={control}
                name="assessmentRatings"
                label="الدرجة"
                options={[...ASSESSMENT_RATINGS].reverse().map((r) => ({ value: r, label: assessmentRatingLabels[r] }))}
              />
            </section>

            <div className="flex justify-end border-t pt-4">
              <Button type="submit" disabled={preview.isPending}>
                <Search className="size-4" />
                {preview.isPending ? "جارٍ المعاينة..." : "معاينة الأسر المطابقة"}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <SaveError message={error} />
      {message && (
        <Alert>
          <AlertDescription>{message}</AlertDescription>
        </Alert>
      )}

      {result && (
        <Card size="sm">
          <CardHeader>
            <CardTitle>عدد الأسر المطابقة: {result.meta.total}</CardTitle>
            <CardDescription>
              {abilities.nominate
                ? "اختر الأسر المراد ترشيحها. لا تُرشَّح أي أسرة تلقائيًا."
                : "افتح المساعدة لإضافة مرشحين."}
            </CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-3 p-0">
            {rows.length > 0 && (
              <div className="overflow-x-auto border-t">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-0">
                        <input
                          type="checkbox"
                          aria-label="تحديد أسر هذه الصفحة"
                          className="size-4 accent-primary"
                          disabled={!abilities.nominate || selectable.length === 0}
                          checked={pageAllSelected}
                          onChange={() => {
                            const next = new Set(selected);
                            for (const r of selectable) {
                              if (pageAllSelected) next.delete(r.family_code);
                              else next.add(r.family_code);
                            }
                            setSelected(next);
                          }}
                        />
                      </TableHead>
                      <TableHead>الأسرة</TableHead>
                      <TableHead>الأفراد</TableHead>
                      <TableHead>النزوح</TableHead>
                      <TableHead>مؤشرات المطابقة</TableHead>
                      <TableHead>الترشيح</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {rows.map((r) => (
                      <TableRow key={r.family_code} data-match={r.family_code}>
                        <TableCell>
                          <input
                            type="checkbox"
                            aria-label={`تحديد ${r.family_code}`}
                            className="size-4 accent-primary"
                            disabled={!abilities.nominate || r.already_nominated}
                            checked={selected.has(r.family_code)}
                            onChange={() => toggle(r.family_code)}
                          />
                        </TableCell>
                        <TableCell>
                          <span dir="ltr" className="font-medium">{r.family_code}</span>
                          {r.household_head_name && (
                            <span className="block text-xs text-muted-foreground">{r.household_head_name}</span>
                          )}
                        </TableCell>
                        <TableCell className="tabular-nums">{r.member_count}</TableCell>
                        <TableCell className="text-xs">
                          {r.displacement?.status ? displacementStatusLabel(r.displacement.status) : "—"}
                          {r.displacement?.location_text && (
                            <span className="block text-muted-foreground">{r.displacement.location_text}</span>
                          )}
                        </TableCell>
                        <TableCell>
                          <Indicators match={r} />
                        </TableCell>
                        <TableCell>
                          {r.already_nominated ? <Badge variant="outline">مرشحة مسبقًا</Badge> : <span className="text-xs text-muted-foreground">—</span>}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}

            <div className="flex flex-wrap items-center justify-between gap-2 border-t px-4 py-3">
              <div className="flex items-center gap-2 text-xs text-muted-foreground">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={preview.isPending || result.meta.current_page <= 1 || !criteria}
                  onClick={() => criteria && run(criteria, result.meta.current_page - 1)}
                >
                  السابق
                </Button>
                <span>
                  صفحة {result.meta.current_page} من {Math.max(result.meta.last_page, 1)}
                </span>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={preview.isPending || result.meta.current_page >= result.meta.last_page || !criteria}
                  onClick={() => criteria && run(criteria, result.meta.current_page + 1)}
                >
                  التالي
                </Button>
              </div>
              {abilities.nominate && (
                <Button type="button" disabled={selected.size === 0 || nominate.isPending} onClick={nominateSelected}>
                  <UserPlus className="size-4" />
                  {nominate.isPending ? "جارٍ الإضافة..." : `إضافة المحددين كمرشحين (${selected.size})`}
                </Button>
              )}
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
