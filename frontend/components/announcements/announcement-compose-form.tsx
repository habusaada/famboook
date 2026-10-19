"use client";

import { useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Controller, useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { AlertTriangle, ArrowRight, Calculator, CheckCircle2, Loader2 } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import type { FamilyCodePickerProps } from "@/components/announcements/family-code-picker";
import { AppCard } from "@/components/shared/app-card";
import { FieldError, FieldLabel } from "@/components/shared/edit-dialog-parts";
import { PageHeader, SectionHeader } from "@/components/shared/page-layout";
import {
  type AnnouncementAudienceType,
  type AnnouncementDraftInput,
  type AnnouncementSide,
  useAudiencePreview,
  useCreateAnnouncement,
} from "@/lib/api/announcements";
import { ApiError } from "@/lib/api/client";
import {
  ANNOUNCEMENT_BODY_MAX,
  ANNOUNCEMENT_TITLE_MAX,
  type AnnouncementFormValues,
  type AudienceGrammar,
  announcementSchema,
  audienceKey,
  expiryInstant,
  formAudience,
} from "@/lib/schemas/announcement";
import { announcementErrorCode, announcementErrorMessage } from "@/lib/utils/announcement";

const fmt = (n: number) => n.toLocaleString("ar");

/** datetime-local wants local wall time without a zone. */
function localInputValue(at: number): string {
  const d = new Date(at);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

const FIELD_FOR: Record<string, keyof AnnouncementFormValues> = {
  title: "title",
  body: "body",
  level: "level",
  expires_at: "expiresAt",
};

export type AudienceOption = {
  type: AnnouncementAudienceType;
  label: string;
  /** Shown for a type without a family picker. */
  description?: string;
};

/**
 * A new announcement DRAFT (FB-NOTIFY-01C.3c / 3d), for either side. The
 * audience options are the side's own (and only those the account may use);
 * the selection type names families by PUBLIC family codes through the side's
 * picker. The recipient count must be confirmed by the server (preview) for
 * the CURRENT audience before saving; changing the audience invalidates it.
 *
 * Idempotency: one client_reference per attempt of an unchanged payload — a
 * retry after an uncertain failure re-sends the same reference (the server
 * replays, never duplicates); a changed payload gets a new reference, so an
 * old reference is never reused for different content. A 409 conflict drops
 * it. The detail opens only after the server confirmed the draft (201 or a
 * 200 replay).
 */
export function AnnouncementComposeForm({
  side,
  grammar,
  options,
  Picker,
  listHref,
  listLabel,
  detailHref,
}: {
  side: AnnouncementSide;
  grammar: AudienceGrammar;
  options: AudienceOption[];
  Picker: React.ComponentType<FamilyCodePickerProps>;
  listHref: string;
  listLabel: string;
  detailHref: (id: string) => string;
}) {
  const router = useRouter();
  const schema = useMemo(() => announcementSchema(grammar), [grammar]);
  const form = useForm<AnnouncementFormValues>({
    resolver: zodResolver(schema),
    defaultValues: { title: "", body: "", level: "NORMAL", expiresAt: "", audienceType: options[0]?.type ?? grammar.selection, familyCodes: [] },
  });
  const [title, body, audienceType, familyCodes] = useWatch({ control: form.control, name: ["title", "body", "audienceType", "familyCodes"] });
  const audience = formAudience({ audienceType, familyCodes }, grammar);
  const currentKey = audienceKey(audience);
  const selecting = audienceType === grammar.selection;

  const preview = useAudiencePreview(side);
  const [counted, setCounted] = useState<{ key: string; count: number } | null>(null);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const previewing = useRef(false);
  const previewValid = counted !== null && counted.key === currentKey && counted.count > 0;

  const create = useCreateAnnouncement(side);
  const attempt = useRef<{ key: string; reference: string } | null>(null);
  const saving = useRef(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  function countRecipients() {
    if (previewing.current) return;
    if ("family_codes" in audience && audience.family_codes.length === 0) {
      setPreviewError("اختر أسرة واحدة على الأقل.");
      return;
    }
    previewing.current = true;
    setPreviewError(null);
    const key = currentKey;
    preview.mutate(audience, {
      onSettled: () => {
        previewing.current = false;
      },
      onSuccess: (count) => {
        setCounted({ key, count });
        if (count === 0) setPreviewError("لا توجد أسر مستلمة ضمن هذا التحديد.");
      },
      onError: (e) => {
        setCounted(null);
        setPreviewError(announcementErrorMessage(e));
      },
    });
  }

  function send(values: AnnouncementFormValues) {
    if (saving.current) return;
    if (!previewValid) {
      setSubmitError("احسب عدد المستلمين للتحديد الحالي قبل حفظ المسودة.");
      return;
    }
    const input: Omit<AnnouncementDraftInput, "client_reference"> = {
      title: values.title.trim(),
      body: values.body.trim(),
      level: values.level,
      audience: formAudience(values, grammar),
      expires_at: expiryInstant(values.expiresAt),
    };
    const key = JSON.stringify(input);
    if (attempt.current?.key !== key) attempt.current = { key, reference: crypto.randomUUID() };

    saving.current = true;
    setSubmitError(null);
    create.mutate(
      { ...input, client_reference: attempt.current.reference },
      {
        onSettled: () => {
          saving.current = false;
        },
        onSuccess: (outcome) => router.push(detailHref(outcome.announcement.id)),
        onError: (e) => {
          if (announcementErrorCode(e) === "NOTIFICATION_IDEMPOTENCY_CONFLICT") attempt.current = null;
          if (e instanceof ApiError && e.status === 422) {
            for (const [field, messages] of Object.entries(e.validationErrors ?? {})) {
              const target = FIELD_FOR[field];
              if (target && messages[0]) form.setError(target, { type: "server", message: messages[0] });
            }
          }
          setSubmitError(announcementErrorMessage(e));
        },
      }
    );
  }

  const errors = form.formState.errors;
  // The picker's minimum, fixed when the form opens (the schema re-checks at submit).
  const [minExpiry] = useState(() => localInputValue(Date.now() + 61 * 60 * 1000));
  const nonSelecting = options.find((o) => o.type === audienceType && o.type !== grammar.selection);

  return (
    <div className="flex flex-col gap-4">
      <div>
        <Link
          href={listHref}
          className="-ms-1 inline-flex min-h-9 items-center gap-1 rounded-md px-1 text-[13px] font-medium text-brand-700 hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
        >
          <ArrowRight className="size-4" aria-hidden />
          {listLabel}
        </Link>
        <PageHeader title="إعلان جديد" description="يُحفظ الإعلان مسودة أولًا، ثم تطلب نشره من صفحة الإعلان." />
      </div>

      <form onSubmit={(event) => void form.handleSubmit(send)(event)} noValidate className="flex flex-col gap-4" aria-label="إعلان جديد" data-announcement-compose={side}>
        <AppCard variant="flat" className="flex flex-col gap-4">
          <SectionHeader title="محتوى الإعلان" description="يظهر للأسر كما هو، نصًا عاديًا." />
          <div className="flex flex-col gap-1.5">
            <div className="flex items-center justify-between gap-2">
              <FieldLabel htmlFor="announcement-title">العنوان</FieldLabel>
              <span className="text-xs text-subtle-foreground tabular-nums" data-counter="title">
                {fmt(title.trim().length)} / {fmt(ANNOUNCEMENT_TITLE_MAX)}
              </span>
            </div>
            <Input id="announcement-title" aria-invalid={Boolean(errors.title)} aria-required {...form.register("title")} />
            <FieldError message={errors.title?.message} />
          </div>
          <div className="flex flex-col gap-1.5">
            <div className="flex items-center justify-between gap-2">
              <FieldLabel htmlFor="announcement-body">النص</FieldLabel>
              <span className="text-xs text-subtle-foreground tabular-nums" data-counter="body">
                {fmt(body.trim().length)} / {fmt(ANNOUNCEMENT_BODY_MAX)}
              </span>
            </div>
            <Textarea id="announcement-body" rows={7} dir="auto" aria-invalid={Boolean(errors.body)} aria-required {...form.register("body")} />
            <FieldError message={errors.body?.message} />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="announcement-level">المستوى</FieldLabel>
              <Controller
                control={form.control}
                name="level"
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="announcement-level" className="w-full" data-announcement-level-select>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="NORMAL">عادي — يكفي الاطلاع</SelectItem>
                      <SelectItem value="IMPORTANT">مهم — يتطلب تأكيد الاطلاع</SelectItem>
                    </SelectContent>
                  </Select>
                )}
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="announcement-expiry" optional>
                موعد الانتهاء
              </FieldLabel>
              <Input
                id="announcement-expiry"
                type="datetime-local"
                dir="ltr"
                min={minExpiry}
                aria-invalid={Boolean(errors.expiresAt)}
                aria-describedby="announcement-expiry-hint"
                {...form.register("expiresAt")}
              />
              <p id="announcement-expiry-hint" className="text-xs text-muted-foreground">
                بعد ساعة على الأقل. يُؤرشف الإعلان تلقائيًا عند انتهائه.
              </p>
              <FieldError message={errors.expiresAt?.message} />
            </div>
          </div>
        </AppCard>

        <AppCard variant="flat" className="flex flex-col gap-4">
          <SectionHeader title="المستلمون" description="تُحسب الأسر المستلمة على الخادم. احسب عددها قبل الحفظ." />
          {options.length > 1 && (
            <fieldset className="flex flex-col gap-2">
              <legend className="sr-only">نوع المستلمين</legend>
              <Controller
                control={form.control}
                name="audienceType"
                render={({ field }) => (
                  <div className="flex flex-col gap-2 sm:flex-row">
                    {options.map((option) => (
                      <label
                        key={option.type}
                        className="flex min-h-11 flex-1 cursor-pointer items-center gap-2 rounded-lg border px-3 has-[:checked]:border-brand-700 has-[:checked]:bg-brand-50"
                      >
                        <input type="radio" name={field.name} value={option.type} checked={field.value === option.type} onChange={() => field.onChange(option.type)} />
                        <span className="text-sm font-medium">{option.label}</span>
                      </label>
                    ))}
                  </div>
                )}
              />
            </fieldset>
          )}

          {selecting ? (
            <>
              <Controller
                control={form.control}
                name="familyCodes"
                render={({ field }) => <Picker value={field.value} onChange={field.onChange} invalid={Boolean(errors.familyCodes)} describedBy="announcement-families-error" />}
              />
              <span id="announcement-families-error">
                <FieldError message={errors.familyCodes?.message} />
              </span>
            </>
          ) : (
            nonSelecting?.description && (
              <p className="rounded-lg bg-surface-2 px-3 py-2.5 text-sm text-foreground" data-announcement-audience-note={nonSelecting.type}>
                {nonSelecting.description}
              </p>
            )
          )}

          <div className="flex flex-col gap-2 rounded-lg border border-dashed p-3" aria-live="polite" data-announcement-preview>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <Button type="button" variant="outline" onClick={countRecipients} disabled={preview.isPending} data-announcement-preview-action>
                {preview.isPending ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <Calculator className="size-4" aria-hidden />}
                احسب عدد المستلمين
              </Button>
              {previewValid && counted && (
                <p className="flex items-center gap-1.5 text-sm font-medium text-success" data-announcement-preview-count={counted.count}>
                  <CheckCircle2 className="size-4" aria-hidden />
                  عدد الأسر المستلمة الآن: <bdi className="tabular-nums">{fmt(counted.count)}</bdi>
                </p>
              )}
            </div>
            {counted !== null && counted.key !== currentKey && (
              <p className="text-xs text-muted-foreground" data-announcement-preview-stale>
                تغيّر تحديد المستلمين. احسب العدد من جديد.
              </p>
            )}
            {previewError && (
              <p className="text-sm text-danger" role="alert" data-announcement-preview-error>
                {previewError}
              </p>
            )}
          </div>
        </AppCard>

        {submitError && (
          <Alert variant="destructive" data-announcement-submit-error>
            <AlertTriangle className="size-4" />
            <AlertTitle>لم تُحفظ المسودة</AlertTitle>
            <AlertDescription>{submitError}</AlertDescription>
          </Alert>
        )}

        {!previewValid && (
          <p id="announcement-save-hint" className="text-[13px] text-muted-foreground sm:text-end" data-announcement-save-hint>
            احسب عدد المستلمين للتحديد الحالي لتتمكن من حفظ المسودة.
          </p>
        )}
        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button asChild variant="outline">
            <Link href={listHref}>إلغاء</Link>
          </Button>
          <Button type="submit" disabled={create.isPending || !previewValid} aria-describedby={previewValid ? undefined : "announcement-save-hint"} data-announcement-save>
            {create.isPending && <Loader2 className="size-4 animate-spin" aria-hidden />}
            حفظ المسودة
          </Button>
        </div>
      </form>
    </div>
  );
}
