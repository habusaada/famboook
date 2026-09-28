"use client";

import { useState } from "react";
import Link from "next/link";
import {
  AlertCircle,
  ArrowRight,
  Building2,
  CalendarRange,
  Check,
  CheckCheck,
  ChevronLeft,
  Flag,
  HandHeart,
  Hourglass,
  Lock,
  PackageCheck,
  Pencil,
  PlayCircle,
  SearchX,
  Send,
  Target,
  Users,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { IconBox } from "@/components/shared/icon-box";
import { ProgressBar } from "@/components/shared/meter";
import { DetailItem, DetailList, SectionHeader } from "@/components/shared/page-layout";
import { SaveError } from "@/components/shared/edit-dialog-parts";
import { AssistanceFormDialog } from "@/components/assistances/assistance-form-dialog";
import { AssistanceStatusTag, ExecutionModeTag } from "@/components/assistances/assistance-case";
import { AssistanceTargetingTab } from "@/components/assistances/assistance-targeting-tab";
import { AssistanceNomineesTab } from "@/components/assistances/assistance-nominees-tab";
import { AssistanceExportTab, AssistanceIssuedListsTab } from "@/components/assistances/assistance-lists";
import { useAssistance, useCompleteAssistance, useOpenAssistance } from "@/lib/api/assistances";
import { ApiError } from "@/lib/api/client";
import type { Assistance, AssistanceStatistics } from "@/lib/types/api/assistance";
import { formatDateTime } from "@/lib/utils/date";
import { assistanceTypeLabels, executionModeLabels } from "@/lib/utils/assistance";
import { cn } from "@/lib/utils";

const TABS = ["overview", "items", "targeting", "nominees", "export", "lists"];
const fmt = (n: number) => n.toLocaleString("ar");

const tabTrigger =
  "h-10 flex-none rounded-md px-3 text-sm font-medium text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground data-active:bg-surface-selected data-active:font-semibold data-active:text-brand-800 after:bg-brand-700 group-data-horizontal/tabs:after:-bottom-[5px] focus-visible:ring-2 focus-visible:ring-ring/70 focus-visible:ring-inset";

/** A value that is not recorded: calm, never an error. */
function Unset({ children = "غير محددة" }: { children?: React.ReactNode }) {
  return <span className="font-normal text-muted-foreground">{children}</span>;
}

function Period({ start, end }: { start: string | null; end: string | null }) {
  if (!start && !end) return <Unset />;
  return (
    <bdi dir="ltr" className="tabular-nums">
      {start ?? "…"} → {end ?? "…"}
    </bdi>
  );
}

// ------------------------------------------------------------------ lifecycle dialogs (logic unchanged)

function OpenAssistanceDialog({ assistance }: { assistance: Assistance }) {
  const mutation = useOpenAssistance(assistance.id);
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);

  return (
    <Dialog open={open} onOpenChange={(next) => !mutation.isPending && setOpen(next)}>
      <DialogTrigger asChild>
        <Button size="sm" onClick={() => setError(null)}>
          <PlayCircle className="size-4" />
          فتح المساعدة
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>فتح المساعدة</DialogTitle>
          <DialogDescription>
            بعد الفتح يمكن إضافة المرشحين، ويُقفل تعريف المساعدة وعناصرها (يبقى الوصف والعدد المستهدف
            والتواريخ قابلة للتعديل).
          </DialogDescription>
        </DialogHeader>
        <SaveError message={error} />
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={mutation.isPending}>
            إلغاء
          </Button>
          <Button
            type="button"
            disabled={mutation.isPending}
            onClick={() =>
              mutation.mutate(undefined, {
                onSuccess: () => setOpen(false),
                onError: (e) =>
                  setError(
                    (e instanceof ApiError && e.message422) || "تعذّر فتح المساعدة. الرجاء المحاولة مرة أخرى."
                  ),
              })
            }
          >
            {mutation.isPending ? "جارٍ الفتح..." : "تأكيد الفتح"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function CompleteAssistanceDialog({ assistance }: { assistance: Assistance }) {
  const mutation = useCompleteAssistance(assistance.id);
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const internal = assistance.execution_mode === "INTERNAL";

  return (
    <Dialog open={open} onOpenChange={(next) => !mutation.isPending && setOpen(next)}>
      <DialogTrigger asChild>
        <Button size="sm" variant="outline" onClick={() => setError(null)}>
          <Flag className="size-4" />
          إكمال المساعدة
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>إكمال المساعدة</DialogTitle>
          <DialogDescription>
            {internal
              ? "يشترط ألا يبقى مرشح بانتظار الاعتماد ولا مستفيد معتمد بانتظار التسليم."
              : "يشترط ألا يبقى مرشح بانتظار الاعتماد وأن يُدرج كل مستفيد معتمد في كشف صادر. الإكمال لا يعني أن الجهة سلّمت المساعدة."}
          </DialogDescription>
        </DialogHeader>
        <SaveError message={error} />
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={mutation.isPending}>
            إلغاء
          </Button>
          <Button
            type="button"
            disabled={mutation.isPending}
            onClick={() =>
              mutation.mutate(undefined, {
                onSuccess: () => setOpen(false),
                onError: (e) => {
                  const errors = e instanceof ApiError ? e.validationErrors : undefined;
                  setError(
                    (errors && Object.values(errors)[0]?.[0]) ||
                      (e instanceof ApiError && e.message422) ||
                      "تعذّر إكمال المساعدة."
                  );
                },
              })
            }
          >
            {mutation.isPending ? "جارٍ الإكمال..." : "تأكيد الإكمال"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ------------------------------------------------------------------ header pieces

/** Program lifecycle as recorded: مسودة → مفتوحة → مكتملة (status only, no inferred stages). */
function LifecycleSteps({ status }: { status: Assistance["status"] }) {
  const steps = [
    { key: "DRAFT", label: "مسودة", hint: "التعريف والعناصر" },
    { key: "OPEN", label: "مفتوحة", hint: "الترشيح والاعتماد والتنفيذ" },
    { key: "COMPLETED", label: "مكتملة", hint: "سجل تاريخي" },
  ] as const;
  const current = steps.findIndex((s) => s.key === status);

  if (current === -1) {
    // CANCELLED exists only in the schema; shown as recorded, never offered.
    return <AssistanceStatusTag status={status} />;
  }

  return (
    <ol className="flex flex-wrap items-center gap-x-2 gap-y-1.5" aria-label="مراحل المساعدة" data-lifecycle>
      {steps.map((step, i) => {
        const done = i < current;
        const active = i === current;
        return (
          <li key={step.key} className="flex items-center gap-2" aria-current={active ? "step" : undefined}>
            {i > 0 && <ChevronLeft className="size-3.5 text-muted-foreground" aria-hidden />}
            <span
              className={cn(
                "flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold tabular-nums",
                active ? "bg-brand-700 text-white" : done ? "bg-brand-50 text-brand-700" : "bg-surface-2 text-muted-foreground"
              )}
              aria-hidden
            >
              {done ? <Check className="size-3" /> : i + 1}
            </span>
            <span className="flex flex-col leading-tight">
              <span className={cn("text-[13px]", active ? "font-semibold text-foreground" : "text-muted-foreground")}>
                {step.label}
                {done && <span className="sr-only"> (تمت)</span>}
                {active && <span className="sr-only"> (الحالية)</span>}
              </span>
              <span className="text-[11px] text-muted-foreground max-sm:hidden">{step.hint}</span>
            </span>
          </li>
        );
      })}
    </ol>
  );
}

function Fact({ icon: Icon, label, children, context }: { icon: LucideIcon; label: string; children: React.ReactNode; context?: React.ReactNode }) {
  return (
    <div className="flex min-w-0 items-start gap-2.5 px-4 py-3 sm:px-5" data-fact={label}>
      <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" strokeWidth={1.75} aria-hidden />
      <div className="flex min-w-0 flex-col gap-0.5">
        <span className="text-xs text-muted-foreground">{label}</span>
        <span className="text-[15px] leading-snug font-semibold text-foreground">{children}</span>
        {context && <span className="text-xs text-muted-foreground">{context}</span>}
      </div>
    </div>
  );
}

const count = (n: number | null | undefined) =>
  n === null || n === undefined ? <Unset /> : <bdi className="tabular-nums">{fmt(n)}</bdi>;

/**
 * Operational summary from the server's derived statistics only. The last
 * cell follows the execution mode: deliveries (INTERNAL) or issued lists
 * (EXTERNAL — never a delivery).
 */
function SummaryStrip({ assistance, s }: { assistance: Assistance; s: AssistanceStatistics }) {
  const internal = assistance.execution_mode === "INTERNAL";
  return (
    <div
      className={cn(
        "grid grid-cols-2 border-t border-stroke-subtle bg-surface-2/60 md:grid-cols-5",
        "[&>*]:border-stroke-subtle [&>*:not(:last-child)]:border-e max-md:[&>*:nth-child(2n)]:border-e-0 max-md:[&>*:nth-child(n+3)]:border-t max-md:[&>*:last-child:nth-child(odd)]:col-span-2"
      )}
      aria-label="ملخص تشغيلي"
    >
      <Fact icon={Target} label="الهدف">
        {assistance.target_beneficiaries === null ? <Unset>بلا هدف محدد</Unset> : count(assistance.target_beneficiaries)}
      </Fact>
      <Fact icon={Users} label="المرشحون">{count(s.total_nominees)}</Fact>
      <Fact icon={Hourglass} label="بانتظار الاعتماد">{count(s.pending_approval)}</Fact>
      <Fact icon={CheckCheck} label="المعتمدون">{count(s.approved)}</Fact>
      {internal ? (
        <Fact
          icon={PackageCheck}
          label="تم التسليم"
          context={
            s.execution_percentage === null || s.execution_percentage === undefined ? undefined : (
              <>
                نسبة التنفيذ <bdi className="tabular-nums">{fmt(s.execution_percentage)}%</bdi>
              </>
            )
          }
        >
          {count(s.delivered)}
        </Fact>
      ) : (
        <Fact icon={Send} label="أُدرجوا في كشوف صادرة" context={<>الكشوف الصادرة: {count(s.issued_lists)}</>}>
          {count(s.listed_unique)}
        </Fact>
      )}
    </div>
  );
}

// ------------------------------------------------------------------ overview

function ExecutionDetails({ assistance, s }: { assistance: Assistance; s: AssistanceStatistics }) {
  const internal = assistance.execution_mode === "INTERNAL";
  return (
    <AppCard aria-labelledby="assistance-execution-title">
      <SectionHeader
        title={<span id="assistance-execution-title">إحصاءات التنفيذ</span>}
        description={
          internal
            ? "محسوبة من الاعتمادات والتسليمات الفعلية المسجلة في Famboook."
            : "التنفيذ خارجي: نتيجة التسليم لدى الجهة غير معروفة. يظهر هنا ما تم إصداره في كشوف فقط."
        }
      />
      <DetailList className="mt-3" data-execution-stats>
        <DetailItem label="المرشحون">{count(s.total_nominees)}</DetailItem>
        <DetailItem label="بانتظار الاعتماد">{count(s.pending_approval)}</DetailItem>
        <DetailItem label="المعتمدون">{count(s.approved)}</DetailItem>
        <DetailItem label="المرفوضون">{count(s.rejected)}</DetailItem>
        <DetailItem label="الترشيحات المُزالة">{count(s.removed)}</DetailItem>
        {internal ? (
          <>
            <DetailItem label="بانتظار التسليم">{count(s.awaiting_delivery)}</DetailItem>
            <DetailItem label="تم التسليم">{count(s.delivered)}</DetailItem>
            <DetailItem label="لم يُسلَّم">{count(s.not_delivered)}</DetailItem>
            <DetailItem label="تسليمات معكوسة">{count(s.reversed_deliveries)}</DetailItem>
          </>
        ) : (
          <>
            <DetailItem label="معتمدون لم يُدرجوا في كشف">{count(s.approved_not_listed)}</DetailItem>
            <DetailItem label="تم إصدارهم في كشوف">{count(s.listed_unique)}</DetailItem>
            <DetailItem label="عدد الكشوف الصادرة">{count(s.issued_lists)}</DetailItem>
            <DetailItem label="نتيجة التسليم لدى الجهة">
              <Unset>غير معروفة</Unset>
            </DetailItem>
          </>
        )}
      </DetailList>
      {internal && s.execution_percentage !== null && s.execution_percentage !== undefined && (
        <div className="mt-3 flex flex-col gap-1.5">
          <span className="flex justify-between text-xs text-muted-foreground">
            <span>نسبة التنفيذ (من الخادم)</span>
            <bdi className="font-semibold text-foreground tabular-nums">{fmt(s.execution_percentage)}%</bdi>
          </span>
          <ProgressBar value={s.execution_percentage} total={100} label="نسبة التنفيذ" size="sm" />
        </div>
      )}
      {internal && (s.package_totals?.length ?? 0) > 0 && (
        <div className="mt-4 rounded-lg bg-surface-2 p-3" data-package-totals>
          <p className="mb-1.5 text-xs text-muted-foreground">إجمالي ما سُلِّم (الحزمة كاملة × التسليمات الفعّالة)</p>
          <ul className="flex flex-wrap gap-x-5 gap-y-1 text-sm">
            {s.package_totals!.map((p) => (
              <li key={p.item_name}>
                {p.item_name}: <bdi className="font-semibold tabular-nums">{p.quantity ?? "—"}</bdi> {p.unit ?? ""}
              </li>
            ))}
            {s.monetary_totals!.map((m) => (
              <li key={m.currency} className="font-semibold">
                <bdi dir="ltr" className="tabular-nums">
                  {m.total} {m.currency}
                </bdi>
              </li>
            ))}
          </ul>
        </div>
      )}
    </AppCard>
  );
}

function Overview({ assistance, s }: { assistance: Assistance; s: AssistanceStatistics }) {
  return (
    <div className="grid grid-cols-1 items-start gap-4 xl:grid-cols-12">
      <div className="flex flex-col gap-4 xl:col-span-7">
        {assistance.status === "DRAFT" ? (
          <AppCard padded={false}>
            <EmptyState
              icon={Hourglass}
              title="لم تُفتح المساعدة بعد"
              description="أكمل تعريف المساعدة وعناصرها، ثم افتحها لإضافة المرشحين. تظهر إحصاءات التنفيذ بعد الفتح."
              className="py-8"
            />
          </AppCard>
        ) : (
          <ExecutionDetails assistance={assistance} s={s} />
        )}
        <AppCard aria-labelledby="assistance-description-title">
          <h2 id="assistance-description-title" className="text-base font-semibold">
            الوصف
          </h2>
          {assistance.description ? (
            <p className="mt-2 text-sm leading-relaxed whitespace-pre-line text-foreground">{assistance.description}</p>
          ) : (
            <p className="mt-1 text-sm text-muted-foreground">لا يوجد وصف.</p>
          )}
        </AppCard>
      </div>

      <div className="flex flex-col gap-4 xl:col-span-5">
        <AppCard aria-labelledby="assistance-program-title">
          <SectionHeader title={<span id="assistance-program-title">بيانات البرنامج</span>} />
          <DetailList className="mt-2 sm:grid-cols-1">
            <DetailItem label="التصنيف">{assistance.category.name}</DetailItem>
            <DetailItem label="نوع المساعدة">{assistanceTypeLabels[assistance.assistance_type]}</DetailItem>
            <DetailItem label="طريقة التنفيذ">{executionModeLabels[assistance.execution_mode]}</DetailItem>
            <DetailItem label="الجهة المقدمة">{assistance.provider_name || <Unset />}</DetailItem>
            <DetailItem label="عدد المستفيدين المستهدف">
              {assistance.target_beneficiaries === null ? <Unset>بلا هدف محدد</Unset> : count(assistance.target_beneficiaries)}
            </DetailItem>
            <DetailItem label="الفترة المخططة">
              <Period start={assistance.start_date} end={assistance.end_date} />
            </DetailItem>
          </DetailList>
        </AppCard>

        <AppCard aria-labelledby="assistance-history-title">
          <SectionHeader title={<span id="assistance-history-title">السجل</span>} />
          <DetailList className="mt-2 sm:grid-cols-1">
            <DetailItem label="أُنشئت بواسطة">
              {assistance.created_by?.name ?? "—"}
              <span className="block text-xs font-normal text-muted-foreground">{formatDateTime(assistance.created_at)}</span>
            </DetailItem>
            {assistance.opened_at && (
              <DetailItem label="فُتحت بواسطة">
                {assistance.opened_by?.name ?? "—"}
                <span className="block text-xs font-normal text-muted-foreground">{formatDateTime(assistance.opened_at)}</span>
              </DetailItem>
            )}
            {assistance.completed_at && (
              <DetailItem label="اكتملت بواسطة">
                {assistance.completed_by?.name ?? "—"}
                <span className="block text-xs font-normal text-muted-foreground">{formatDateTime(assistance.completed_at)}</span>
              </DetailItem>
            )}
          </DetailList>
        </AppCard>
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ items

function Items({ assistance }: { assistance: Assistance }) {
  const items = assistance.items ?? [];
  return (
    <AppCard padded={false} aria-labelledby="assistance-items-title">
      <div className="px-4 pt-4 pb-3 sm:px-5">
        <SectionHeader
          title={<span id="assistance-items-title">عناصر المساعدة</span>}
          description="ما يُخطَّط تقديمه لكل مستفيد (تخطيط، وليس كميات مسلَّمة)."
        />
      </div>
      {items.length === 0 ? (
        <EmptyState icon={PackageCheck} title="لا توجد عناصر بعد" className="border-t border-stroke-subtle py-8" />
      ) : (
        <>
          <Table className="hidden border-t border-stroke-subtle md:table">
            <TableHeader className="bg-surface-1">
              <TableRow className="border-stroke-subtle hover:bg-transparent">
                <TableHead className="ps-5 text-xs text-muted-foreground">العنصر</TableHead>
                <TableHead className="text-xs text-muted-foreground">الكمية لكل مستفيد</TableHead>
                <TableHead className="text-xs text-muted-foreground">الوحدة</TableHead>
                <TableHead className="pe-5 text-xs text-muted-foreground">قيمة الوحدة</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {items.map((item, i) => (
                <TableRow key={i} data-item={item.item_name} className="border-stroke-subtle">
                  <TableCell className="ps-5 font-semibold">{item.item_name}</TableCell>
                  <TableCell className="tabular-nums">{item.quantity_per_beneficiary ?? <Unset />}</TableCell>
                  <TableCell>{item.unit ?? <Unset />}</TableCell>
                  <TableCell className="pe-5">
                    {item.unit_value ? (
                      <bdi dir="ltr" className="tabular-nums">
                        {item.unit_value} {item.currency ?? ""}
                      </bdi>
                    ) : (
                      <Unset />
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <ul className="divide-y divide-stroke-subtle border-t border-stroke-subtle md:hidden" aria-label="عناصر المساعدة">
            {items.map((item, i) => (
              <li key={i} className="flex flex-col gap-1 px-4 py-3" data-item={item.item_name}>
                <span className="font-semibold">{item.item_name}</span>
                <span className="text-xs text-muted-foreground">
                  {item.quantity_per_beneficiary ? `${item.quantity_per_beneficiary} ${item.unit ?? ""} لكل مستفيد` : "الكمية غير محددة"}
                  {item.unit_value && (
                    <>
                      {" · "}
                      <bdi dir="ltr">
                        {item.unit_value} {item.currency ?? ""}
                      </bdi>
                    </>
                  )}
                </span>
              </li>
            ))}
          </ul>
        </>
      )}
    </AppCard>
  );
}

// ------------------------------------------------------------------ page

/** Page context: Assistance programs › this program. */
function Breadcrumb({ title }: { title?: string }) {
  return (
    <nav aria-label="مسار الصفحة" className="flex min-w-0 items-center gap-1 text-[13px] text-muted-foreground">
      <Link href="/assistances" className="shrink-0 rounded-sm px-0.5 hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-ring">
        المساعدات
      </Link>
      {title && (
        <>
          <ChevronLeft className="size-3.5 shrink-0" aria-hidden />
          <span aria-current="page" className="truncate font-medium text-foreground">
            {title}
          </span>
        </>
      )}
    </nav>
  );
}

export function AssistanceWorkspace({ assistanceId, initialTab }: { assistanceId: string; initialTab?: string }) {
  const { data, isLoading, isError, error } = useAssistance(assistanceId);

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true">
        <Skeleton className="h-5 w-48" />
        <Skeleton className="h-56 w-full rounded-widget" />
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-64 w-full rounded-widget" />
      </div>
    );
  }

  if (isError) {
    const status = error instanceof ApiError ? error.status : 0;
    return (
      <div className="flex flex-col gap-4">
        <Button variant="ghost" size="sm" className="-ms-2 w-fit gap-1.5 text-muted-foreground" asChild>
          <Link href="/assistances">
            <ArrowRight className="size-4" />
            المساعدات
          </Link>
        </Button>
        {status === 403 || status === 404 ? (
          <AppCard padded={false}>
            <EmptyState
              icon={status === 403 ? Lock : SearchX}
              title={status === 403 ? "لا تملك صلاحية عرض المساعدات." : "لم يتم العثور على هذه المساعدة."}
            />
          </AppCard>
        ) : (
          <Alert variant="destructive">
            <AlertCircle className="size-4" />
            <AlertTitle>تعذّر تحميل المساعدة</AlertTitle>
            <AlertDescription>حدث خطأ أثناء الاتصال بالخادم.</AlertDescription>
          </Alert>
        )}
      </div>
    );
  }

  const { data: assistance, abilities, statistics } = data!;
  const external = assistance.execution_mode === "EXTERNAL";
  const completed = assistance.status === "COMPLETED";
  const hasActions = abilities.update || abilities.open || abilities.complete;

  return (
    <div className="flex flex-col gap-4">
      <Breadcrumb title={assistance.title} />

      {/* Program identity → lifecycle → operational summary. */}
      <AppCard padded={false} className="overflow-hidden" aria-label="هوية البرنامج">
        <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-5">
          <div className="flex min-w-0 items-start gap-4">
            <IconBox icon={HandHeart} size="lg" />
            <div className="flex min-w-0 flex-col gap-1.5">
              <div className="flex flex-wrap items-center gap-2">
                <AssistanceStatusTag status={assistance.status} />
                <span data-execution-mode={assistance.execution_mode}>
                  <ExecutionModeTag mode={assistance.execution_mode} />
                </span>
                <span className="text-[13px] text-muted-foreground">
                  {assistance.category.name} · {assistanceTypeLabels[assistance.assistance_type]}
                </span>
              </div>
              <h1 className="text-2xl leading-tight font-bold break-words text-foreground">{assistance.title}</h1>
              <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-muted-foreground">
                <span className="flex items-center gap-1.5">
                  <Building2 className="size-3.5 shrink-0" aria-hidden />
                  الجهة المقدمة: {assistance.provider_name ? <span className="text-foreground">{assistance.provider_name}</span> : <Unset />}
                </span>
                <span className="flex items-center gap-1.5">
                  <CalendarRange className="size-3.5 shrink-0" aria-hidden />
                  الفترة: <Period start={assistance.start_date} end={assistance.end_date} />
                </span>
              </p>
              <p className="text-xs text-muted-foreground">{executionModeLabels[assistance.execution_mode]}</p>
            </div>
          </div>

          {hasActions && (
            <div className="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end" data-program-actions>
              {abilities.update && (
                <AssistanceFormDialog
                  assistance={assistance}
                  definition={abilities.update_definition}
                  trigger={
                    <Button variant="outline" size="sm">
                      <Pencil className="size-4" />
                      تعديل
                    </Button>
                  }
                />
              )}
              {abilities.complete && <CompleteAssistanceDialog assistance={assistance} />}
              {abilities.open && <OpenAssistanceDialog assistance={assistance} />}
            </div>
          )}
        </div>

        <div className="border-t border-stroke-subtle px-4 py-3 sm:px-5">
          <LifecycleSteps status={assistance.status} />
        </div>

        <SummaryStrip assistance={assistance} s={statistics} />

        {completed && (
          <p className="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-t border-stroke-subtle px-4 py-2.5 text-[13px] text-muted-foreground sm:px-5" data-completion>
            <Lock className="size-3.5 shrink-0" aria-hidden />
            اكتملت بواسطة {assistance.completed_by?.name ?? "—"}
            {assistance.completed_at && (
              <>
                {" "}في <time dateTime={assistance.completed_at}>{formatDateTime(assistance.completed_at)}</time>
              </>
            )}
            <span aria-hidden>·</span>
            <span>سجل تاريخي للقراءة فقط</span>
            {external && (
              <>
                <span aria-hidden>·</span>
                <span>التنفيذ خارجي ونتيجة التسليم لدى الجهة غير معروفة</span>
              </>
            )}
          </p>
        )}
      </AppCard>

      <Tabs defaultValue={initialTab && TABS.includes(initialTab) ? initialTab : "overview"} className="gap-0" dir="rtl">
        <TabsList
          variant="line"
          aria-label="أقسام مساحة عمل المساعدة"
          className="h-auto! w-full justify-start gap-1 overflow-x-auto overflow-y-hidden rounded-none border-b border-stroke-subtle p-0 pb-1"
        >
          <TabsTrigger className={tabTrigger} value="overview">نظرة عامة</TabsTrigger>
          <TabsTrigger className={tabTrigger} value="items">العناصر</TabsTrigger>
          <TabsTrigger className={tabTrigger} value="targeting">الاستهداف</TabsTrigger>
          <TabsTrigger className={tabTrigger} value="nominees">
            {external ? "المرشحون والاعتماد" : "المرشحون والتسليم"}
          </TabsTrigger>
          {external && <TabsTrigger className={tabTrigger} value="export">بيانات الكشف</TabsTrigger>}
          {external && <TabsTrigger className={tabTrigger} value="lists">الكشوف الصادرة</TabsTrigger>}
        </TabsList>

        <TabsContent value="overview" className="mt-4">
          <Overview assistance={assistance} s={statistics} />
        </TabsContent>
        <TabsContent value="items" className="mt-4">
          <Items assistance={assistance} />
        </TabsContent>
        <TabsContent value="targeting" className="mt-4">
          <AssistanceTargetingTab assistance={assistance} abilities={abilities} />
        </TabsContent>
        <TabsContent value="nominees" className="mt-4">
          <AssistanceNomineesTab assistance={assistance} abilities={abilities} />
        </TabsContent>
        {external && (
          <TabsContent value="export" className="mt-4">
            <AssistanceExportTab assistance={assistance} abilities={abilities} />
          </TabsContent>
        )}
        {external && (
          <TabsContent value="lists" className="mt-4">
            <AssistanceIssuedListsTab assistance={assistance} abilities={abilities} />
          </TabsContent>
        )}
      </Tabs>
    </div>
  );
}
