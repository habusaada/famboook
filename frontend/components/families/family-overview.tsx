import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { getHouseholdHead } from "@/lib/mock-data/families";
import { relationshipLabels, type Family } from "@/lib/types/family";

const registrationSourceLabels: Record<string, string> = {
  PAPER_FORM: "نموذج ورقي",
  MANUAL_ENTRY: "إدخال يدوي",
  IMPORT: "استيراد بيانات",
  VERIFIED_SOURCE: "مصدر موثّق",
};

function InfoRow({
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

export function FamilyOverview({ family }: { family: Family }) {
  const head = getHouseholdHead(family);

  return (
    <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
      <Card size="sm">
        <CardHeader>
          <CardTitle>معلومات التسجيل</CardTitle>
          <CardDescription>بيانات تسجيل الأسرة في السجل</CardDescription>
        </CardHeader>
        <CardContent>
          <InfoRow
            label="تاريخ التسجيل"
            value={family.registrationDate}
            ltr
          />
          <InfoRow
            label="مصدر التسجيل"
            value={
              registrationSourceLabels[family.registrationSource] ??
              family.registrationSource
            }
          />
          {family.paperFormNo && (
            <InfoRow
              label="رقم النموذج الورقي"
              value={family.paperFormNo}
              ltr
            />
          )}
          <InfoRow label="آخر تحديث" value={family.updatedAt} />
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
              <InfoRow label="الاسم الكامل" value={head.fullName} />
              <InfoRow label="رقم الفرد" value={head.personCode} ltr />
              <InfoRow
                label="صلة القرابة"
                value={relationshipLabels[head.relationship]}
              />
              <InfoRow
                label="الجنس"
                value={head.gender === "MALE" ? "ذكر" : "أنثى"}
              />
              <InfoRow label="تاريخ الميلاد" value={head.birthDate} ltr />
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
          <InfoRow label="المحافظة" value={family.residence.governorate} />
          <InfoRow label="المدينة" value={family.residence.city} />
          {family.residence.area && (
            <InfoRow label="المنطقة" value={family.residence.area} />
          )}
          {family.residence.displacementStatus && (
            <InfoRow
              label="حالة النزوح"
              value={family.residence.displacementStatus}
            />
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
