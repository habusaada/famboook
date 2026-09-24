"use client";

import { useState } from "react";
import Link from "next/link";
import { AlertCircle, ArrowRight, Flag, Lock, Pencil, PlayCircle, SearchX } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { SaveError } from "@/components/shared/edit-dialog-parts";
import { AssistanceFormDialog } from "@/components/assistances/assistance-form-dialog";
import { AssistanceStatusBadge } from "@/components/assistances/assistance-badges";
import { AssistanceTargetingTab } from "@/components/assistances/assistance-targeting-tab";
import { AssistanceNomineesTab } from "@/components/assistances/assistance-nominees-tab";
import { AssistanceExportTab, AssistanceIssuedListsTab } from "@/components/assistances/assistance-lists";
import { useAssistance, useCompleteAssistance, useOpenAssistance } from "@/lib/api/assistances";
import { ApiError } from "@/lib/api/client";
import type { Assistance, AssistanceStatistics } from "@/lib/types/api/assistance";
import { formatDateTime } from "@/lib/utils/date";
import { assistanceTypeLabels, executionModeLabels, plannedPeriod } from "@/lib/utils/assistance";

const TABS = ["overview", "items", "targeting", "nominees", "export", "lists"];

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="text-sm">{children}</dd>
    </div>
  );
}

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

function Stat({ label, value, testId }: { label: string; value: React.ReactNode; testId: string }) {
  return (
    <div className="rounded-md border p-3" data-stat={testId}>
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="text-xl font-semibold tabular-nums">{value ?? "—"}</p>
    </div>
  );
}

/** Derived execution figures; EXTERNAL never shows deliveries. */
function StatisticsCard({ assistance, statistics }: { assistance: Assistance; statistics: AssistanceStatistics }) {
  const s = statistics;
  const internal = assistance.execution_mode === "INTERNAL";

  return (
    <Card size="sm">
      <CardHeader>
        <CardTitle>إحصاءات التنفيذ</CardTitle>
        <CardDescription>
          {internal
            ? "محسوبة من الاعتمادات والتسليمات الفعلية المسجلة في Famboook."
            : "التنفيذ خارجي: نتيجة التسليم لدى الجهة غير معروفة. يظهر هنا ما تم إصداره في كشوف فقط."}
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <div className="grid grid-cols-2 gap-2 md:grid-cols-4 xl:grid-cols-6">
          <Stat label="المستهدف" value={s.target} testId="target" />
          <Stat label="المرشحون" value={s.total_nominees} testId="total_nominees" />
          <Stat label="بانتظار الاعتماد" value={s.pending_approval} testId="pending_approval" />
          <Stat label="المعتمدون" value={s.approved} testId="approved" />
          <Stat label="المرفوضون" value={s.rejected} testId="rejected" />
          {internal ? (
            <>
              <Stat label="بانتظار التسليم" value={s.awaiting_delivery} testId="awaiting_delivery" />
              <Stat label="تم التسليم" value={s.delivered} testId="delivered" />
              <Stat label="لم يُسلَّم" value={s.not_delivered} testId="not_delivered" />
              <Stat label="تسليمات معكوسة" value={s.reversed_deliveries} testId="reversed_deliveries" />
              <Stat
                label="نسبة التنفيذ"
                value={s.execution_percentage === null || s.execution_percentage === undefined ? "—" : `${s.execution_percentage}%`}
                testId="execution_percentage"
              />
            </>
          ) : (
            <>
              <Stat label="معتمدون لم يُدرجوا في كشف" value={s.approved_not_listed} testId="approved_not_listed" />
              <Stat label="تم إصدارهم في كشوف" value={s.listed_unique} testId="listed_unique" />
              <Stat label="عدد الكشوف الصادرة" value={s.issued_lists} testId="issued_lists" />
            </>
          )}
        </div>
        {internal && (s.package_totals?.length ?? 0) > 0 && (
          <div className="text-sm">
            <p className="mb-1 text-xs text-muted-foreground">إجمالي ما سُلِّم (الحزمة كاملة × التسليمات الفعّالة)</p>
            <ul className="flex flex-wrap gap-x-4 gap-y-1" data-package-totals>
              {s.package_totals!.map((p) => (
                <li key={p.item_name}>
                  {p.item_name}: <span className="tabular-nums">{p.quantity ?? "—"}</span> {p.unit ?? ""}
                </li>
              ))}
              {s.monetary_totals!.map((m) => (
                <li key={m.currency} className="font-medium">
                  {m.total} {m.currency}
                </li>
              ))}
            </ul>
          </div>
        )}
      </CardContent>
    </Card>
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

export function AssistanceWorkspace({ assistanceId, initialTab }: { assistanceId: string; initialTab?: string }) {
  const { data, isLoading, isError, error } = useAssistance(assistanceId);

  const back = (
    <Button variant="ghost" size="sm" className="-ms-2 w-fit gap-1.5 text-muted-foreground" asChild>
      <Link href="/assistances">
        <ArrowRight className="size-4" />
        المساعدات
      </Link>
    </Button>
  );

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4">
        {back}
        <Skeleton className="h-32" />
        <Skeleton className="h-64" />
      </div>
    );
  }

  if (isError) {
    const status = error instanceof ApiError ? error.status : 0;
    return (
      <div className="flex flex-col gap-4">
        {back}
        {status === 403 || status === 404 ? (
          <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-16 text-center">
            {status === 403 ? <Lock className="size-8 text-muted-foreground" /> : <SearchX className="size-8 text-muted-foreground" />}
            <p className="text-sm text-muted-foreground">
              {status === 403 ? "لا تملك صلاحية عرض المساعدات." : "لم يتم العثور على هذه المساعدة."}
            </p>
          </div>
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
  const items = assistance.items ?? [];

  return (
    <div className="flex flex-col gap-4">
      {back}

      <Card size="sm">
        <CardContent className="flex flex-col gap-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="text-xl font-semibold tracking-tight">{assistance.title}</h2>
              <AssistanceStatusBadge status={assistance.status} />
            </div>
            <div className="flex flex-wrap items-center gap-2">
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
              {abilities.open && <OpenAssistanceDialog assistance={assistance} />}
              {abilities.complete && <CompleteAssistanceDialog assistance={assistance} />}
            </div>
          </div>
          <p className="text-sm text-muted-foreground">
            {assistance.category.name} · {assistanceTypeLabels[assistance.assistance_type]} · {assistance.provider_name} ·{" "}
            <span data-execution-mode={assistance.execution_mode}>{executionModeLabels[assistance.execution_mode]}</span>
          </p>
          {assistance.completed_at && (
            <p className="rounded-md bg-muted/60 px-3 py-2 text-sm">
              اكتملت في {formatDateTime(assistance.completed_at)} بواسطة {assistance.completed_by?.name ?? "—"}
              {external && " — التنفيذ خارجي ونتيجة التسليم لدى الجهة غير معروفة."}
            </p>
          )}
        </CardContent>
      </Card>

      <Tabs defaultValue={initialTab && TABS.includes(initialTab) ? initialTab : "overview"}>
        <TabsList variant="line" className="w-full justify-start border-b">
          <TabsTrigger value="overview">نظرة عامة</TabsTrigger>
          <TabsTrigger value="items">العناصر</TabsTrigger>
          <TabsTrigger value="targeting">الاستهداف</TabsTrigger>
          <TabsTrigger value="nominees">المرشحون</TabsTrigger>
          {external && <TabsTrigger value="export">بيانات الكشف</TabsTrigger>}
          {external && <TabsTrigger value="lists">الكشوف الصادرة</TabsTrigger>}
        </TabsList>

        <TabsContent value="overview" className="mt-4 flex flex-col gap-4">
          {assistance.status !== "DRAFT" && <StatisticsCard assistance={assistance} statistics={statistics} />}
          <Card size="sm">
            <CardContent>
              <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Field label="الحالة">
                  <AssistanceStatusBadge status={assistance.status} />
                </Field>
                <Field label="التصنيف">{assistance.category.name}</Field>
                <Field label="نوع المساعدة">{assistanceTypeLabels[assistance.assistance_type]}</Field>
                <Field label="الجهة المقدمة">{assistance.provider_name}</Field>
                <Field label="طريقة التنفيذ">{executionModeLabels[assistance.execution_mode]}</Field>
                <Field label="عدد المستفيدين المستهدف">
                  <span className="tabular-nums">{assistance.target_beneficiaries ?? "—"}</span>
                </Field>
                <Field label="عدد المرشحين">
                  <span className="tabular-nums">{assistance.nominee_count}</span>
                </Field>
                <Field label="الفترة المخططة">
                  <span dir="ltr">{plannedPeriod(assistance.start_date, assistance.end_date)}</span>
                </Field>
                <Field label="أُنشئت بواسطة">
                  {assistance.created_by?.name ?? "—"}
                  <span className="block text-xs text-muted-foreground">{formatDateTime(assistance.created_at)}</span>
                </Field>
                {assistance.opened_at && (
                  <Field label="فُتحت بواسطة">
                    {assistance.opened_by?.name ?? "—"}
                    <span className="block text-xs text-muted-foreground">{formatDateTime(assistance.opened_at)}</span>
                  </Field>
                )}
              </dl>
              <div className="mt-4 flex flex-col gap-1 border-t pt-4">
                <span className="text-xs text-muted-foreground">الوصف</span>
                <p className="text-sm whitespace-pre-line text-muted-foreground">{assistance.description || "لا يوجد وصف."}</p>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="items" className="mt-4">
          <Card size="sm">
            <CardHeader>
              <CardTitle>عناصر المساعدة</CardTitle>
              <CardDescription>ما يُخطَّط تقديمه لكل مستفيد (تخطيط، وليس كميات مسلَّمة)</CardDescription>
            </CardHeader>
            <CardContent className="p-0">
              {items.length === 0 ? (
                <p className="border-t p-8 text-center text-sm text-muted-foreground">لا توجد عناصر بعد.</p>
              ) : (
                <div className="overflow-x-auto border-t">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>العنصر</TableHead>
                        <TableHead>الكمية لكل مستفيد</TableHead>
                        <TableHead>الوحدة</TableHead>
                        <TableHead>قيمة الوحدة</TableHead>
                        <TableHead>العملة</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {items.map((item, i) => (
                        <TableRow key={i} data-item={item.item_name}>
                          <TableCell className="font-medium">{item.item_name}</TableCell>
                          <TableCell className="tabular-nums">{item.quantity_per_beneficiary ?? "—"}</TableCell>
                          <TableCell>{item.unit ?? "—"}</TableCell>
                          <TableCell className="tabular-nums">{item.unit_value ?? "—"}</TableCell>
                          <TableCell>{item.currency ?? "—"}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              )}
            </CardContent>
          </Card>
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
