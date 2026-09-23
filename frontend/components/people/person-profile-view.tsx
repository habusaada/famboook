"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import {
  AlertCircle,
  ArrowRight,
  Crown,
  Phone,
  SearchX,
  User,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { EditPersonDialog } from "@/components/people/edit-person-dialog";
import { usePerson } from "@/lib/api/people";
import { ApiError } from "@/lib/api/client";
import { relationshipLabel } from "@/lib/utils/relationship";
import { calculateAge } from "@/lib/utils/date";

function InfoRow({ label, value, ltr }: { label: string; value: string; ltr?: boolean }) {
  return (
    <div className="flex items-center justify-between gap-4 border-b py-1.5 text-sm last:border-0">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium" dir={ltr ? "ltr" : undefined}>
        {value}
      </span>
    </div>
  );
}

export function PersonProfileView({ personCode }: { personCode: string }) {
  const router = useRouter();
  const { data, isLoading, isError, error } = usePerson(personCode);

  if (isLoading) {
    return (
      <div className="flex flex-col gap-5">
        <Card size="sm">
          <CardContent className="flex flex-col gap-3">
            <Skeleton className="h-8 w-40" />
            <Skeleton className="h-4 w-64" />
          </CardContent>
        </Card>
      </div>
    );
  }

  if (isError) {
    const notFound = error instanceof ApiError && error.status === 404;

    return (
      <div className="flex flex-col gap-5">
        <Button
          type="button"
          variant="ghost"
          className="w-fit gap-1.5 ps-2 text-muted-foreground"
          onClick={() => router.back()}
        >
          <ArrowRight className="size-4" />
          رجوع
        </Button>

        {notFound ? (
          <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-16 text-center">
            <SearchX className="size-8 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">
              لم يتم العثور على شخص بهذا الرقم
            </p>
          </div>
        ) : (
          <Alert variant="destructive">
            <AlertCircle className="size-4" />
            <AlertTitle>تعذّر تحميل بيانات الشخص</AlertTitle>
            <AlertDescription>
              {error instanceof Error
                ? error.message
                : "حدث خطأ غير متوقع أثناء الاتصال بالخادم."}
            </AlertDescription>
          </Alert>
        )}
      </div>
    );
  }

  const person = data!.data;
  const membership = person.family_membership;

  return (
    <div className="flex flex-col gap-5">
      <Card size="sm">
        <CardContent className="flex flex-col gap-3">
          <div className="flex items-center justify-between gap-2">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="-ms-2 w-fit gap-1.5 text-muted-foreground"
              onClick={() => router.back()}
            >
              <ArrowRight className="size-4" />
              رجوع
            </Button>
            <EditPersonDialog person={person} />
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {membership?.is_household_head && (
              <Crown className="size-5 shrink-0 text-primary" />
            )}
            <h2 className="text-xl font-semibold tracking-tight">
              {person.full_name}
            </h2>
            <Badge variant={person.is_active ? "default" : "outline"}>
              {person.is_active ? "نشط" : "غير نشط"}
            </Badge>
          </div>

          <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-muted-foreground">
            <span dir="ltr">{person.person_code}</span>
            {membership && (
              <span className="flex items-center gap-1.5">
                <User className="size-3.5" />
                {relationshipLabel(membership.relationship_type, person.gender)}
                {" — "}
                <Link
                  href={`/families/${membership.family_code}`}
                  dir="ltr"
                  className="text-primary hover:underline"
                >
                  {membership.family_code}
                </Link>
              </span>
            )}
          </div>
        </CardContent>
      </Card>

      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <Card size="sm">
          <CardHeader>
            <CardTitle>البيانات الأساسية</CardTitle>
            <CardDescription>المعلومات الشخصية المسجّلة</CardDescription>
          </CardHeader>
          <CardContent>
            <InfoRow label="رقم الفرد" value={person.person_code} ltr />
            <InfoRow
              label="الجنس"
              value={person.gender === "MALE" ? "ذكر" : "أنثى"}
            />
            {person.birth_date && (
              <>
                <InfoRow label="تاريخ الميلاد" value={person.birth_date} ltr />
                <InfoRow
                  label="العمر"
                  value={String(calculateAge(person.birth_date))}
                />
              </>
            )}
          </CardContent>
        </Card>

        <Card size="sm">
          <CardHeader>
            <CardTitle>بيانات التواصل</CardTitle>
            <CardDescription>أرقام التواصل المسجّلة</CardDescription>
          </CardHeader>
          <CardContent>
            {person.mobile ? (
              <InfoRow label="رقم الجوال" value={person.mobile} ltr />
            ) : (
              <p className="flex items-center gap-1.5 py-1.5 text-sm text-muted-foreground">
                <Phone className="size-3.5" />
                لا يوجد رقم جوال مسجّل
              </p>
            )}
            {person.alternate_mobile && (
              <InfoRow
                label="رقم جوال بديل"
                value={person.alternate_mobile}
                ltr
              />
            )}
            {person.alternate_mobile && person.alternate_mobile_owner_relation && (
              <InfoRow
                label="صاحب الرقم البديل / صلته"
                value={person.alternate_mobile_owner_relation}
              />
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
