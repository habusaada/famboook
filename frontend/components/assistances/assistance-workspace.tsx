"use client";

import { useState } from "react";
import Link from "next/link";
import { AlertCircle, ArrowRight, Lock, Pencil, PlayCircle, SearchX } from "lucide-react";
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
import { useAssistance, useOpenAssistance } from "@/lib/api/assistances";
import { ApiError } from "@/lib/api/client";
import type { Assistance } from "@/lib/types/api/assistance";
import { formatDateTime } from "@/lib/utils/date";
import { assistanceTypeLabels, plannedPeriod } from "@/lib/utils/assistance";

const TABS = ["overview", "items", "targeting", "nominees"];

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

  const { data: assistance, abilities } = data!;
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
            </div>
          </div>
          <p className="text-sm text-muted-foreground">
            {assistance.category.name} · {assistanceTypeLabels[assistance.assistance_type]} · {assistance.provider_name}
          </p>
        </CardContent>
      </Card>

      <Tabs defaultValue={initialTab && TABS.includes(initialTab) ? initialTab : "overview"}>
        <TabsList variant="line" className="w-full justify-start border-b">
          <TabsTrigger value="overview">نظرة عامة</TabsTrigger>
          <TabsTrigger value="items">العناصر</TabsTrigger>
          <TabsTrigger value="targeting">الاستهداف</TabsTrigger>
          <TabsTrigger value="nominees">المرشحون</TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="mt-4">
          <Card size="sm">
            <CardContent>
              <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Field label="الحالة">
                  <AssistanceStatusBadge status={assistance.status} />
                </Field>
                <Field label="التصنيف">{assistance.category.name}</Field>
                <Field label="نوع المساعدة">{assistanceTypeLabels[assistance.assistance_type]}</Field>
                <Field label="الجهة المقدمة">{assistance.provider_name}</Field>
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
          <AssistanceNomineesTab assistance={assistance} canNominate={abilities.nominate} />
        </TabsContent>
      </Tabs>
    </div>
  );
}
