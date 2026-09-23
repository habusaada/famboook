import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import type { FamilyDetail } from "@/lib/types/api/family";
import { displacementStatusLabel } from "@/lib/utils/displacement";

const registrationSourceLabels: Record<string, string> = {
  PAPER_FORM: "نموذج ورقي",
  MANUAL_ENTRY: "إدخال يدوي",
  IMPORT: "استيراد بيانات",
  VERIFIED_SOURCE: "مصدر موثّق",
};

export function InfoRow({
  label,
  value,
  ltr,
}: {
  label: string;
  value: string;
  ltr?: boolean;
}) {
  return (
    <div className="flex items-center justify-between gap-4 border-b py-1.5 text-sm last:border-0">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium" dir={ltr ? "ltr" : undefined}>
        {value}
      </span>
    </div>
  );
}

export function FamilyOverview({ family }: { family: FamilyDetail }) {
  const head = family.members.find((m) => m.is_household_head);

  return (
    <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
      <Card size="sm">
        <CardHeader>
          <CardTitle>معلومات التسجيل</CardTitle>
          <CardDescription>بيانات تسجيل الأسرة في السجل</CardDescription>
        </CardHeader>
        <CardContent>
          {family.registration_date && (
            <InfoRow
              label="تاريخ التسجيل"
              value={family.registration_date}
              ltr
            />
          )}
          {family.registration_source && (
            <InfoRow
              label="مصدر التسجيل"
              value={
                registrationSourceLabels[family.registration_source] ??
                family.registration_source
              }
            />
          )}
          {family.paper_form_no && (
            <InfoRow
              label="رقم النموذج الورقي"
              value={family.paper_form_no}
              ltr
            />
          )}
          {family.updated_at && (
            <InfoRow
              label="آخر تحديث"
              value={new Date(family.updated_at).toLocaleString("ar", {
                dateStyle: "medium",
                timeStyle: "short",
              })}
            />
          )}
        </CardContent>
      </Card>

      <Card size="sm">
        <CardHeader>
          <CardTitle>رب الأسرة</CardTitle>
          <CardDescription>الشخص المسؤول عن الأسرة حالياً</CardDescription>
        </CardHeader>
        <CardContent>
          {head ? (
            <>
              <InfoRow label="الاسم الكامل" value={head.full_name} />
              <InfoRow label="رقم الفرد" value={head.person_code} ltr />
              <InfoRow
                label="الجنس"
                value={head.gender === "MALE" ? "ذكر" : "أنثى"}
              />
              {head.birth_date && (
                <InfoRow label="تاريخ الميلاد" value={head.birth_date} ltr />
              )}
            </>
          ) : (
            <p className="text-sm text-muted-foreground">
              لم يتم تحديد رب الأسرة بعد
            </p>
          )}
        </CardContent>
      </Card>

      <Card size="sm">
        <CardHeader>
          <CardTitle>السكن الحالي</CardTitle>
          <CardDescription>موقع إقامة الأسرة</CardDescription>
        </CardHeader>
        <CardContent>
          {family.residence ? (
            <>
              <InfoRow label="المحافظة" value={family.residence.governorate} />
              <InfoRow label="المدينة" value={family.residence.city} />
              {family.residence.area && (
                <InfoRow label="المنطقة" value={family.residence.area} />
              )}
              {family.residence.displacement_status && (
                <InfoRow
                  label="حالة النزوح"
                  value={displacementStatusLabel(family.residence.displacement_status)}
                />
              )}
            </>
          ) : (
            <p className="text-sm text-muted-foreground">
              لا يوجد سكن حالي مسجّل
            </p>
          )}
        </CardContent>
      </Card>

      {family.notes && (
        <Card size="sm">
          <CardHeader>
            <CardTitle>ملاحظات</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-sm text-muted-foreground">{family.notes}</p>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
