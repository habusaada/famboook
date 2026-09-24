"use client";

import Link from "next/link";
import {
  AlertCircle,
  ArrowRight,
  CheckCircle2,
  ClipboardList,
  Clock,
  Lock,
  Pencil,
  SearchX,
  User,
  XCircle,
} from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { NeedPriorityBadge, NeedStatusBadge } from "@/components/needs/need-badges";
import { NeedFormDialog } from "@/components/needs/need-form-dialog";
import { CloseNeedDialog, FulfillNeedDialog } from "@/components/needs/need-resolve-dialogs";
import { ApiError } from "@/lib/api/client";
import { useNeed } from "@/lib/api/needs";
import { formatDateTime } from "@/lib/utils/date";
import { FAMILY_TARGET_LABEL, needQuantityLabel } from "@/lib/utils/need";

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="text-sm">{children}</dd>
    </div>
  );
}

function LoadError({ error }: { error: unknown }) {
  if (error instanceof ApiError && (error.status === 403 || error.status === 404)) {
    const Icon = error.status === 403 ? Lock : SearchX;
    return (
      <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-16 text-center">
        <Icon className="size-8 text-muted-foreground" />
        <p className="text-sm text-muted-foreground">
          {error.status === 403 ? "لا تملك صلاحية عرض الاحتياجات." : "لم يتم العثور على هذا الاحتياج."}
        </p>
      </div>
    );
  }

  return (
    <Alert variant="destructive">
      <AlertCircle className="size-4" />
      <AlertTitle>تعذّر تحميل الاحتياج</AlertTitle>
      <AlertDescription>حدث خطأ أثناء الاتصال بالخادم.</AlertDescription>
    </Alert>
  );
}

export function NeedDetailView({ needId }: { needId: string }) {
  const { data, isLoading, isError, error } = useNeed(needId);

  const backToQueue = (
    <Button variant="ghost" size="sm" className="-ms-2 w-fit gap-1.5 text-muted-foreground" asChild>
      <Link href="/needs">
        <ArrowRight className="size-4" />
        الاحتياجات
      </Link>
    </Button>
  );

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4">
        {backToQueue}
        <Skeleton className="h-32" />
        <Skeleton className="h-64" />
      </div>
    );
  }

  if (isError) {
    return (
      <div className="flex flex-col gap-4">
        {backToQueue}
        <LoadError error={error} />
      </div>
    );
  }

  const { data: need, abilities } = data!;
  const code = need.family.family_code;
  const quantity = needQuantityLabel(need);

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-x-2">
        <Button variant="ghost" size="sm" className="-ms-2 w-fit gap-1.5 text-muted-foreground" asChild>
          <Link href={`/families/${encodeURIComponent(code)}?tab=needs`}>
            <ArrowRight className="size-4" />
            احتياجات الأسرة <span dir="ltr">{code}</span>
          </Link>
        </Button>
        <span className="text-muted-foreground">·</span>
        <Button variant="link" size="sm" className="text-muted-foreground" asChild>
          <Link href="/needs">كل الاحتياجات</Link>
        </Button>
      </div>

      <Card size="sm">
        <CardContent className="flex flex-col gap-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="text-xl font-semibold tracking-tight">{need.title}</h2>
              <NeedPriorityBadge priority={need.priority} />
              <NeedStatusBadge status={need.status} />
            </div>
            {(abilities.update || abilities.fulfill || abilities.close) && (
              <div className="flex flex-wrap items-center gap-2">
                {abilities.update && (
                  <NeedFormDialog
                    familyCode={code}
                    need={need}
                    trigger={
                      <Button variant="outline" size="sm">
                        <Pencil className="size-4" />
                        تعديل
                      </Button>
                    }
                  />
                )}
                {abilities.close && <CloseNeedDialog need={need} />}
                {abilities.fulfill && <FulfillNeedDialog need={need} />}
              </div>
            )}
          </div>
          <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
            <span className="flex items-center gap-1.5">
              <User className="size-3.5" />
              أُدخل بواسطة: {need.created_by?.name ?? "—"}
            </span>
            <span className="flex items-center gap-1.5">
              <Clock className="size-3.5" />
              <time dateTime={need.created_at}>{formatDateTime(need.created_at)}</time>
            </span>
          </p>

          {need.status === "FULFILLED" && (
            <p className="flex flex-wrap items-center gap-x-1.5 rounded-md bg-muted/60 px-3 py-2 text-sm">
              <CheckCircle2 className="size-4 text-primary" />
              <span className="font-medium">تمت تلبيته</span>
              <span className="text-muted-foreground">
                — سجّله {need.resolved_by?.name ?? "—"} في{" "}
                {need.resolved_at && <time dateTime={need.resolved_at}>{formatDateTime(need.resolved_at)}</time>}
                . للقراءة فقط.
              </span>
            </p>
          )}
          {need.status === "CLOSED" && (
            <div className="flex flex-col gap-1 rounded-md bg-muted/60 px-3 py-2 text-sm">
              <p className="flex flex-wrap items-center gap-x-1.5">
                <XCircle className="size-4 text-muted-foreground" />
                <span className="font-medium">مغلق</span>
                <span className="text-muted-foreground">
                  — أغلقه {need.resolved_by?.name ?? "—"} في{" "}
                  {need.resolved_at && <time dateTime={need.resolved_at}>{formatDateTime(need.resolved_at)}</time>}
                  . للقراءة فقط.
                </span>
              </p>
              <p className="whitespace-pre-line">
                <span className="text-muted-foreground">سبب الإغلاق: </span>
                {need.closure_reason}
              </p>
            </div>
          )}
        </CardContent>
      </Card>

      <Card size="sm">
        <CardHeader>
          <CardTitle>تفاصيل الاحتياج</CardTitle>
        </CardHeader>
        <CardContent>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Field label="الأسرة">
              <Link href={`/families/${encodeURIComponent(code)}`} className="hover:underline">
                <span dir="ltr">{code}</span>
                {need.family.household_head_name && ` — ${need.family.household_head_name}`}
              </Link>
            </Field>
            <Field label="المستفيد">
              {need.person ? (
                <Link href={`/people/${encodeURIComponent(need.person.person_code)}`} className="hover:underline">
                  {need.person.full_name}
                </Link>
              ) : (
                FAMILY_TARGET_LABEL
              )}
            </Field>
            <Field label="التصنيف">
              {need.category.name}
              {!need.category.is_active && (
                <span className="ms-1 text-xs text-muted-foreground">(غير مفعّل)</span>
              )}
            </Field>
            <Field label="الكمية">{quantity ?? "—"}</Field>
            <Field label="المصدر">
              {need.source_assessment ? (
                <Link
                  href={`/families/${encodeURIComponent(code)}/assessments/${need.source_assessment.id}`}
                  className="inline-flex items-center gap-1 hover:underline"
                >
                  <ClipboardList className="size-3.5" />
                  تقييم بتاريخ <span dir="ltr">{need.source_assessment.assessment_date}</span>
                </Link>
              ) : (
                "إدخال مباشر"
              )}
            </Field>
          </dl>
          <div className="mt-4 flex flex-col gap-1 border-t pt-4">
            <span className="text-xs text-muted-foreground">ملاحظات / وصف</span>
            <p className="text-sm whitespace-pre-line text-muted-foreground">
              {need.description || "لا يوجد وصف."}
            </p>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
